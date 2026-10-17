<?php
/**
 * <module_context>
 *   <name>zram_actions</name>
 *   <description>Action handlers for ZRAM device and SSD swap management</description>
 *   <dependencies>zram_config</dependencies>
 *   <consumers>UnraidZramCard.page (AJAX)</consumers>
 * </module_context>
 *
 * The work is done by zram_actions_handle(). It takes the action name and the
 * request values as arrays and returns the response as an array, so PHPUnit can
 * run every action. The code at the end of the file reads the real request and
 * sends the response. It does not run when ZRAM_ACTIONS_LIBRARY_ONLY is defined.
 * See docs/specs/TEST_SEAMS.md.
 */

require_once dirname(__FILE__) . '/zram_config.php';

// System paths this file reads or writes. A test points each at a temp folder.
defined('ZRAM_PROC_DIR')     || define('ZRAM_PROC_DIR', '/proc');
defined('ZRAM_LIBVIRT_HOOK') || define('ZRAM_LIBVIRT_HOOK', '/etc/libvirt/hooks/qemu');
defined('ZRAM_LIBVIRT_RUN')  || define('ZRAM_LIBVIRT_RUN', '/run/libvirt/qemu');
defined('ZRAM_CGROUP_DIR')   || define('ZRAM_CGROUP_DIR', '/sys/fs/cgroup');

/** Is $name listed in the first column of the /proc/swaps text $swaps? */
function zram_actions_in_swaps(string $swaps, string $name): bool {
    return $name !== '' && preg_match('/^' . preg_quote($name, '/') . '\s/m', $swaps) === 1;
}

/** Name of the loop device that is attached to the image $image, or ''. */
function zram_actions_loop_for(string $image): string {
    $out = [];
    zram_exec("losetup -j " . escapeshellarg($image) . " 2>/dev/null", $out);
    foreach ($out as $line) {
        if (preg_match('#^(' . preg_quote(ZRAM_DEV_DIR, '#') . '/loop\d+):#', $line, $m)) return $m[1];
    }
    return '';
}

/** The ZFS dataset that is mounted at $mount, or '' when it is not a ZFS mount. */
function zram_actions_zfs_dataset(string $mount): string {
    foreach (@file(ZRAM_PROC_MOUNTS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
        $pp = preg_split('/\s+/', $ln);
        if (($pp[1] ?? '') === $mount && ($pp[2] ?? '') === 'zfs') return $pp[0];
    }
    return '';
}

/** A request value as a string. '' when it is missing or not a string (for example ?action[]=x). */
function zram_actions_request_string($v): string {
    return is_string($v) ? $v : '';
}

/**
 * Validate and normalise the raw value of one auto-save setting.
 * Returns ['value' => normalised] or ['error' => message]. The caller has
 * already checked that $key is on the whitelist; a key with no case here is
 * a code error and answers "Validation missing for key".
 *
 * @return array{value:string|int}|array{error:string}
 */
function zram_actions_validate_setting(string $key, string $raw): array {
    $rawValue = $raw;
    switch ($key) {
        case 'enabled':
        case 'debug':
        case 'console_visible':
        case 'ssd_swap_allow_zfs':
            $value = ($rawValue === 'yes' || $rawValue === 'true' || $rawValue === '1' || $rawValue === 'on') ? 'yes' : 'no';
            break;
        case 'refresh_interval':
            // Form sends seconds; storage in ms. Legacy raw ms (>= 100) accepted as-is.
            $f = floatval($rawValue);
            $ms = $f >= 100 ? intval($f) : intval(round($f * 1000));
            $value = max(1000, $ms);
            break;
        case 'collection_interval':
            $value = max(1, intval($rawValue));
            break;
        case 'swappiness':
            $value = max(0, min(200, intval($rawValue)));
            break;
        case 'thp_mode':
            if (!in_array($rawValue, zram_thp_modes(), true)) {
                return ['error' => 'Invalid huge pages mode'];
            }
            $value = $rawValue;
            break;
        case 'thp_compaction':
            if (!zram_thp_compaction_valid((string)$rawValue)) {
                return ['error' => 'Invalid compaction value'];
            }
            // Store the kernel number ('off' -> '0', '07' -> '7'); 'system' stays.
            $value = $rawValue === 'system' ? 'system' : (string)zram_thp_compaction_number((string)$rawValue);
            break;
        case 'zram_percent':
            $value = max(25, min(75, intval($rawValue)));
            break;
        case 'zram_size':
            if ($rawValue === 'auto' || preg_match('/^\d+\s*[GMT]$/i', $rawValue)) {
                $value = $rawValue === 'auto' ? 'auto' : strtoupper(preg_replace('/\s+/', '', $rawValue));
            } else {
                return ['error' => 'Invalid size — use "auto" or e.g. 16G'];
            }
            break;
        case 'zram_algo':
            $algos = ['zstd', 'lz4', 'lzo', 'deflate'];
            if (!in_array($rawValue, $algos, true)) {
                return ['error' => 'Invalid algorithm'];
            }
            $value = $rawValue;
            break;
        default:
            return ['error' => 'Validation missing for key'];
    }
    return ['value' => $value];
}

/**
 * Run one action.
 *
 * @param array<string,mixed> $in  the request values (the query string)
 * @return array{status:int,type:string,body:mixed} type 'file' means body is the path of a text file for the web entry to send
 */
function zram_actions_handle(string $action, array $in, string $serverCsrf): array {
    // Read one request value as a string (null when missing or not a string).
    $str = function (string $k) use ($in): ?string {
        $v = $in[$k] ?? null;
        return is_string($v) ? $v : null;
    };
    // Read one request value as an integer in a range. False when it is missing, not a number or out of range.
    $int = function (string $k, int $min, int $max) use ($in) {
        return filter_var($in[$k] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    };
    $json = function ($body, int $status = 200): array {
        return ['status' => $status, 'type' => 'json', 'body' => $body];
    };
    $text = function (string $body, int $status = 200): array {
        return ['status' => $status, 'type' => 'text', 'body' => $body];
    };

    $csrf = $str('csrf_token') ?: '';

    // Validate the submitted token against the server-issued token in var.ini.
    // hash_equals prevents timing-based comparison attacks. If var.ini is
    // unreadable (e.g. very early boot), the server token is empty and all
    // requests are rejected — fail-closed.
    $_csrfOk = $serverCsrf !== '' && hash_equals($serverCsrf, $csrf);

    if ($action === 'view_log') {
        if (!$_csrfOk) return $text("Missing CSRF token\n", 403);
        if (file_exists(ZRAM_DEBUG_LOG) && is_readable(ZRAM_DEBUG_LOG)) {
            return ['status' => 200, 'type' => 'file', 'body' => ZRAM_DEBUG_LOG];
        }
        return $text("Debug log not found or not readable.\n");
    }

    if ($action === 'view_cmd_log') {
        if (!$_csrfOk) return $json([], 403);
        $entries = [];
        if (file_exists(ZRAM_CMD_LOG)) {
            $lines = @file(ZRAM_CMD_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $e = json_decode($line, true);
                if ($e) $entries[] = $e;
            }
        }
        return $json($entries);
    }

    // All mutating actions require CSRF
    if (!$_csrfOk) {
        return $json(['success' => false, 'message' => 'Missing CSRF token']);
    }

    $logs = [];

    // --- ZRAM DEVICE ACTIONS ---

    if ($action === 'create_zram') {
        $cfg = zram_config_read();

        // Live-form parameters from the CREATE click. Without these, the action
        // would use only saved-config values, which is what produced the user
        // bug "I moved the slider to 75% but it always creates 16G — turns out
        // I had to APPLY & SAVE first". Now CREATE uses what the user sees on
        // screen and persists those values for next-boot init.
        $sizeMode = $str('size_mode');
        $sizeIn   = $str('size');
        $pctIn    = $int('percent', 25, 75);
        $algoIn   = $str('algo') ?: '';

        // Validate algorithm against the kernel's live allow-list, then static
        // fallback. Anything else falls through to saved config (no error toast
        // — a malformed algo means our own dropdown lied, not that the user is
        // attacking us).
        $allowedAlgos = ['zstd', 'lz4', 'lzo', 'deflate'];
        foreach (glob(ZRAM_SYS_BLOCK . '/zram*/comp_algorithm') ?: [] as $af) {
            $raw = str_replace(['[', ']'], '', (string)@file_get_contents($af));
            $allowedAlgos = preg_split('/\s+/', trim($raw)) ?: [];
            break;
        }
        $algo = (in_array($algoIn, $allowedAlgos, true)) ? $algoIn : $cfg['zram_algo'];

        // Resolve size source. Custom requires a well-formed unit string; an
        // invalid custom size is rejected explicitly rather than silently
        // falling through to the previously-saved value.
        // A custom size loses its spaces and any line break: "4 G" and "4G\n"
        // both become "4G". The raw text would break zramctl and settings.ini.
        if ($sizeMode === 'custom') {
            if (!preg_match('/^\d+\s*[GMT]$/i', (string)$sizeIn)) {
                return $json([
                    'success' => false,
                    'message' => 'Invalid custom size format (use e.g. 4G, 512M, 1T)',
                    'logs'    => $logs,
                ]);
            }
            $sizeIn = preg_replace('/\s+/', '', (string)$sizeIn);
            $size = $sizeIn;
            $sizeSaved = $sizeIn;
        } elseif ($sizeMode === 'auto') {
            $size = 'auto';
            $sizeSaved = 'auto';
        } else {
            $size = $cfg['zram_size'];
            $sizeSaved = $cfg['zram_size'];
        }

        $pct = ($pctIn !== false && $pctIn !== null) ? $pctIn : intval($cfg['zram_percent']);

        // Auto-size calculation — uses resolved $pct so a live slider value wins
        // over the previously-saved one.
        if ($size === 'auto') {
            $memKb = 0;
            $meminfo = @file_get_contents(ZRAM_PROC_MEMINFO) ?: '';
            if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) $memKb = intval($m[1]);
            $pct = max(25, min(75, $pct));
            $sizeBytes = intval(($memKb * 1024) * ($pct / 100));
            $size = intval($sizeBytes / 1048576) . 'M';
        }

        // Check if we already have a device
        $existing = zram_get_our_device();
        if ($existing) {
            return $json(['success' => false, 'message' => "ZRAM device already active: " . ZRAM_DEV_DIR . "/$existing", 'logs' => $logs]);
        }

        zram_run('modprobe zram', $logs);
        $cmd = "zramctl --find --size " . escapeshellarg($size) . " --algorithm " . escapeshellarg($algo);
        zram_exec($cmd . " 2>&1", $find_out, $ret);
        $logs[] = ['cmd' => $cmd, 'output' => implode(" ", $find_out), 'status' => $ret];

        if ($ret !== 0 || empty($find_out)) {
            return $json(['success' => false, 'message' => 'Failed to allocate ZRAM device', 'logs' => $logs]);
        }

        $dev = trim(end($find_out));
        // Only a real zram device node is accepted. Other text (a warning with
        // exit code 0, for example) must not reach mkswap.
        if (!preg_match('#^' . preg_quote(ZRAM_DEV_DIR, '#') . '/zram\d+$#', $dev)) {
            return $json(['success' => false, 'message' => 'Failed to allocate ZRAM device', 'logs' => $logs]);
        }
        $devName = basename($dev);

        // Label with mkswap -L. If a later step fails, the new device is reset
        // so a retry does not leave a second unused device behind.
        if (zram_run("mkswap -L " . escapeshellarg(ZRAM_LABEL) . " " . escapeshellarg($dev), $logs) !== 0) {
            zram_run("zramctl --reset " . escapeshellarg($dev), $logs);
            return $json(['success' => false, 'message' => 'mkswap failed', 'logs' => $logs]);
        }

        $cfgPrio = zram_config_read();
        $zramPrio = max(1, min(32767, intval($cfgPrio['zram_priority'] ?? 100)));
        if (zram_run("swapon " . escapeshellarg($dev) . " -p " . $zramPrio, $logs) !== 0) {
            zram_run("zramctl --reset " . escapeshellarg($dev), $logs);
            return $json(['success' => false, 'message' => 'swapon failed', 'logs' => $logs]);
        }

        // Cache device name for collector
        @file_put_contents(ZRAM_DEVICE_FILE, $devName);

        // Persist what we actually used so next-boot init.sh reproduces the same
        // device, and so the form on the next page render reflects reality. The
        // pre-fix code wrote `zram_size: $cfg['zram_size']` (the stale value)
        // and never persisted `zram_percent` at all — so a slider tweak was lost
        // on every CREATE.
        zram_config_write([
            'zram_algo'    => $algo,
            'zram_size'    => $sizeSaved,
            'zram_percent' => max(25, min(75, $pct)),
        ]);
        return $json(['success' => true, 'message' => "Created $dev ($size, $algo)", 'logs' => $logs]);
    }

    if ($action === 'remove_zram') {
        $ourDev = zram_get_our_device();
        if (empty($ourDev)) {
            return $json(['success' => false, 'message' => 'No ZRAM Card device found', 'logs' => $logs]);
        }
        $devPath = ZRAM_DEV_DIR . "/$ourDev";

        $safety = zram_evacuation_safe($devPath, $logs);
        if (!$safety['safe']) {
            return $json(['success' => false, 'message' => $safety['error'], 'logs' => $logs]);
        }

        // Each step must succeed. Silent failure here was the root cause of the
        // "REMOVE button persists across reloads" bug: under memory pressure
        // swapoff can fail, zramctl --reset then also fails, and we used to
        // unlink device.conf and report success regardless — leaving the kernel
        // device active while the UI lost track of it.
        if (zram_run("swapoff " . escapeshellarg($devPath), $logs) !== 0) {
            return $json([
                'success' => false,
                'message' => 'swapoff failed — pages may not have evacuated. Retry once memory pressure subsides.',
                'logs' => $logs,
            ]);
        }

        // Wipe the swap signature so blkid (and its /run/blkid/blkid.tab cache)
        // immediately stops reporting our label on this device. Without this
        // step a stale cache entry can survive `zramctl --reset` (which fires
        // no udev change event) and make the next page load think the device
        // is still active. Logged but not fatal: the cache-bypassing probe in
        // zram_get_our_device() is the second line of defence.
        zram_run("wipefs -a " . escapeshellarg($devPath), $logs);

        if (zram_run("zramctl --reset " . escapeshellarg($devPath), $logs) !== 0) {
            return $json([
                'success' => false,
                'message' => 'zramctl --reset failed (device may still be allocated)',
                'logs' => $logs,
            ]);
        }
        @unlink(ZRAM_DEVICE_FILE);
        return $json(['success' => true, 'message' => "Removed $devPath", 'logs' => $logs]);
    }

    // --- DISK SWAP FILE ACTIONS ---
    // 'create_ssd_swap' / 'remove_ssd_swap' are accepted as legacy aliases so a
    // settings tab loaded from a pre-upgrade page asset (cached JS) keeps working
    // for one session post-upgrade. The cache-buster on the .page render bumps
    // next time, so subsequent requests use the new identifiers.

    if ($action === 'create_disk_swap' || $action === 'create_ssd_swap') {
        $mount = $str('mount') ?: '';
        // Spaces and line breaks are removed from the size ("16 G" -> "16G").
        // The raw text would be written to settings.ini.
        $sizeStr = strtoupper(preg_replace('/\s+/', '', $str('size') ?: '16G'));

        // Restrict mount to paths returned by the drive discovery API: all are
        // under /mnt/ (never array disks, user shares, or system paths). Resolving
        // via realpath() also blocks symlink traversal to paths outside /mnt/.
        $mntPrefix = ZRAM_MNT_ROOT . '/';
        $realMount = (strpos($mount, '..') === false && $mount !== '') ? realpath($mount) : false;
        if ($realMount === false
            || strpos($mount, $mntPrefix) !== 0
            || strpos($realMount, $mntPrefix) !== 0
            || !is_dir($realMount)
        ) {
            return $json(['success' => false, 'message' => 'Invalid mount point', 'logs' => $logs]);
        }
        $mount = $realMount;

        // Parse size to MB
        $sizeMB = 0;
        if (preg_match('/^(\d+)([GMT])$/i', $sizeStr, $sm)) {
            $num = intval($sm[1]);
            $unit = strtoupper($sm[2]);
            if ($unit === 'G') $sizeMB = $num * 1024;
            elseif ($unit === 'T') $sizeMB = $num * 1024 * 1024;
            else $sizeMB = $num;
        }
        if ($sizeMB < 256) {
            return $json(['success' => false, 'message' => 'Minimum swap file size is 256M', 'logs' => $logs]);
        }

        // Check free space (need size + 100MB headroom)
        $freeBytes = @disk_free_space($mount) ?: 0;
        $needBytes = $sizeMB * 1048576;
        if ($freeBytes < ($needBytes + 104857600)) {
            return $json(['success' => false, 'message' => 'Insufficient free space on ' . $mount, 'logs' => $logs]);
        }

        // Read backing mode from the drive picker's 'backing' field.
        // 'loop' = NOCOW image + losetup + mkswap/swapon on loop block device.
        // 'zvol' = ZFS-only: a dedicated ZFS volume, already a real block device.
        // 'file' (or anything else) = the existing direct-file path.
        $backing = $str('backing') ?: 'file';
        if (!in_array($backing, ['file', 'loop', 'zvol'], true)) $backing = 'file';

        // Detect btrfs on the target mount (used by direct-file path for NOCOW)
        $fsType  = trim((string)zram_exec("stat -f -c %T " . escapeshellarg($mount) . " 2>/dev/null"));
        $isBtrfs = ($fsType === 'btrfs');

        // Defense-in-depth: the 'backing' param above is the drive picker's echo of
        // the mode zram_drives.php computed. A stale/buggy client — or a direct API
        // call — can omit it, silently defaulting to 'file' and falling to the direct
        // swap-file path. On a multi-device btrfs pool the kernel REJECTS swap files
        // outright (WP #1387 regression symptom), and ZFS has no direct-swap-file
        // support at all — so that path is doomed either way. Independently re-detect
        // here and UPGRADE to the appropriate backing when the FS cannot host a direct
        // swap file. Authoritative server check; never downgrades a loop/zvol request.
        if ($backing !== 'loop' && $backing !== 'zvol') {
            $forceLoop = false;
            $forceZvol = false;
            if ($isBtrfs) {
                $devCount = [];
                zram_exec("btrfs filesystem show " . escapeshellarg($mount) . " 2>/dev/null | grep -c 'devid'", $devCount);
                if (intval($devCount[0] ?? 0) > 1) $forceLoop = true;   // multi-device btrfs (RAID)
            } else {
                // Canonical fstype from /proc/mounts (stat -f is unreliable for zfs).
                if (zram_actions_zfs_dataset($mount) !== '') {
                    $cfgZfs = zram_config_read();
                    // zvol supersedes loop for ZFS — real block device, no CoW/
                    // deadlock caveat. See docs/specs/TIER2_ZVOL_SWAP.md.
                    if (($cfgZfs['ssd_swap_allow_zfs'] ?? 'no') === 'yes') $forceZvol = true;
                }
            }
            if ($forceLoop) {
                $backing = 'loop';
                zram_cmd_log("Filesystem on $mount cannot host a direct swap file — using loop-device backing", 'info');
            } elseif ($forceZvol) {
                $backing = 'zvol';
                zram_cmd_log("Filesystem on $mount cannot host a direct swap file — using a ZFS volume (zvol)", 'info');
            }
        }

        if ($backing === 'zvol') {
            // ----------------------------------------------------------------
            // ZFS volume (zvol) path: zfs create -V → mkswap → swapon.
            // Already a real block device — no image file, no losetup lifecycle.
            // See docs/specs/TIER2_ZVOL_SWAP.md.
            // ----------------------------------------------------------------

            // Re-derive the ZFS dataset backing this mount from /proc/mounts —
            // authoritative, never trusts a client-supplied dataset name.
            $dataset = zram_actions_zfs_dataset($mount);
            if ($dataset === '') {
                return $json(['success' => false, 'message' => 'Selected mount is not a ZFS dataset — cannot create a zvol', 'logs' => $logs]);
            }

            $zvolDataset = $dataset . '/zram-card-swap';
            $zvolDev     = ZRAM_DEV_DIR . '/zvol/' . $zvolDataset;

            zram_cmd_log("Creating {$sizeStr} ZFS zvol swap on $dataset...", 'cmd');

            if (zram_run('zfs create -V ' . escapeshellarg($sizeMB . 'M') . ' ' . escapeshellarg($zvolDataset), $logs) !== 0) {
                return $json(['success' => false, 'message' => 'zfs create -V failed', 'logs' => $logs]);
            }

            // ARC caching swap data is self-defeating — swap exists to relieve RAM
            // pressure, so letting ZFS cache those same pages back into RAM (ARC)
            // just moves the pressure into ZFS's own cache instead of resolving
            // it. L2ARC caching is equally pointless for swap's high-churn,
            // effectively-random write pattern (no benefit, just device wear).
            // Non-fatal if it fails (a permission/version quirk shouldn't block a
            // working swap device) but always logged so a failure isn't silent.
            // See docs/specs/TIER2_ZVOL_SWAP.md.
            if (zram_run('zfs set primarycache=none secondarycache=none ' . escapeshellarg($zvolDataset), $logs) !== 0) {
                zram_log("zvol $zvolDataset: could not disable ARC/L2ARC caching — swap will work but may compete with ZFS's own cache for RAM", 'WARN');
            }

            // The /dev/zvol/... symlink is udev-created and can lag zfs create by
            // a beat; poll briefly rather than failing on the first check.
            $tries = 0;
            while (!file_exists($zvolDev) && $tries < ZRAM_ZVOL_WAIT_TRIES) { usleep(250000); $tries++; }
            if (!file_exists($zvolDev)) {
                zram_exec('zfs destroy ' . escapeshellarg($zvolDataset) . ' 2>&1');
                return $json(['success' => false, 'message' => 'zvol device did not appear at ' . $zvolDev, 'logs' => $logs]);
            }

            if (zram_run('mkswap -L ' . escapeshellarg(ZRAM_SSD_LABEL) . ' ' . escapeshellarg($zvolDev), $logs) !== 0) {
                zram_exec('zfs destroy ' . escapeshellarg($zvolDataset) . ' 2>&1');
                return $json(['success' => false, 'message' => 'mkswap on zvol failed', 'logs' => $logs]);
            }

            $cfgPrio = zram_config_read();
            $ssdPrio = max(0, min(32767, intval($cfgPrio['ssd_swap_priority'] ?? 10)));
            if (zram_run('swapon ' . escapeshellarg($zvolDev) . ' -p ' . $ssdPrio, $logs) !== 0) {
                zram_exec('zfs destroy ' . escapeshellarg($zvolDataset) . ' 2>&1');
                return $json(['success' => false, 'message' => 'swapon zvol failed', 'logs' => $logs]);
            }

            zram_config_write([
                'ssd_swap_enabled' => 'yes',
                'ssd_swap_path'    => $zvolDev,   // stable /dev/zvol/<pool>/<dataset> path, NOT /dev/zdN
                'ssd_swap_size'    => $sizeStr,
                'ssd_swap_mount'   => $mount,
                'ssd_swap_backing' => 'zvol',
            ]);

            return $json(['success' => true, 'message' => "Created {$sizeStr} ZFS zvol swap on $dataset ($zvolDev)", 'logs' => $logs]);
        }

        $swapDir = rtrim($mount, '/') . '/.swap';
        $swapFile = "$swapDir/zram-card.swap";

        if (!is_dir($swapDir)) @mkdir($swapDir, 0700, true);

        if ($backing === 'loop') {
            // ----------------------------------------------------------------
            // Loop-device path: NOCOW image → losetup → mkswap → swapon loop
            // The loop block device hides the btrfs-RAID / ZFS restriction from
            // the swap subsystem, which only sees a plain block device.
            // ----------------------------------------------------------------
            zram_cmd_log("Creating {$sizeStr} loop-backed swap image on $mount...", 'cmd');

            // Ensure the loop module is loaded
            zram_run('modprobe loop', $logs);

            // 1. Create an empty file, mark NOCOW *before* any data is written
            //    (chattr +C is a no-op on ZFS — harmless), then fallocate to
            //    pre-allocate all blocks. A sparse image that runs out of space
            //    mid-page-out can corrupt swap or panic.
            zram_run("truncate -s 0 " . escapeshellarg($swapFile), $logs);
            zram_run("chattr +C " . escapeshellarg($swapFile), $logs);
            $fallocCmd = "fallocate -l {$sizeMB}M " . escapeshellarg($swapFile);
            if (zram_run($fallocCmd, $logs) !== 0) {
                @unlink($swapFile);
                return $json(['success' => false, 'message' => 'Failed to preallocate swap image (fallocate)', 'logs' => $logs]);
            }
            @chmod($swapFile, 0600);

            // 2. Attach as a loop block device
            $losetupOut = [];
            $losetupRet = 0;
            zram_exec("losetup -f --show " . escapeshellarg($swapFile) . " 2>&1", $losetupOut, $losetupRet);
            $loopDev = trim(end($losetupOut) ?: '');
            $logs[] = ['cmd' => "losetup -f --show $swapFile", 'output' => $loopDev, 'status' => $losetupRet];
            if ($losetupRet !== 0 || !preg_match('#^' . preg_quote(ZRAM_DEV_DIR, '#') . '/loop\d+$#', $loopDev)) {
                @unlink($swapFile);
                return $json(['success' => false, 'message' => 'losetup failed: ' . $loopDev, 'logs' => $logs]);
            }

            // 3. mkswap + swapon the LOOP device (not the image file)
            if (zram_run("mkswap -L " . escapeshellarg(ZRAM_SSD_LABEL) . " " . escapeshellarg($loopDev), $logs) !== 0) {
                zram_exec("losetup -d " . escapeshellarg($loopDev) . " 2>/dev/null");
                @unlink($swapFile);
                return $json(['success' => false, 'message' => 'mkswap on loop device failed', 'logs' => $logs]);
            }

            $cfgPrio = zram_config_read();
            $ssdPrio = max(0, min(32767, intval($cfgPrio['ssd_swap_priority'] ?? 10)));
            if (zram_run("swapon " . escapeshellarg($loopDev) . " -p " . $ssdPrio, $logs) !== 0) {
                zram_exec("losetup -d " . escapeshellarg($loopDev) . " 2>/dev/null");
                @unlink($swapFile);
                @rmdir($swapDir);
                return $json(['success' => false, 'message' => 'swapon loop device failed', 'logs' => $logs]);
            }

            zram_config_write([
                'ssd_swap_enabled' => 'yes',
                'ssd_swap_path'    => $swapFile,   // stable image path, NOT /dev/loopN
                'ssd_swap_size'    => $sizeStr,
                'ssd_swap_mount'   => $mount,
                'ssd_swap_backing' => 'loop',
            ]);

            return $json(['success' => true, 'message' => "Created {$sizeStr} loop-backed swap on $mount ($loopDev)", 'logs' => $logs]);
        }

        // ----------------------------------------------------------------
        // Direct-file path (XFS, single-device btrfs) — unchanged
        // ----------------------------------------------------------------
        zram_cmd_log("Creating {$sizeStr} swap file on $mount" . ($isBtrfs ? " (btrfs NOCOW)" : "") . "...", 'cmd');

        if ($isBtrfs) {
            // btrfs requires: create empty file, set NOCOW, then fill
            zram_run("truncate -s 0 " . escapeshellarg($swapFile), $logs);
            zram_run("chattr +C " . escapeshellarg($swapFile), $logs);
        }

        $ddCmd = "dd if=/dev/zero of=" . escapeshellarg($swapFile) . " bs=1M count=$sizeMB status=none";
        if (zram_run($ddCmd, $logs) !== 0) {
            @unlink($swapFile);
            return $json(['success' => false, 'message' => 'Failed to create swap file', 'logs' => $logs]);
        }
        @chmod($swapFile, 0600);

        if (zram_run("mkswap -L " . escapeshellarg(ZRAM_SSD_LABEL) . " " . escapeshellarg($swapFile), $logs) !== 0) {
            @unlink($swapFile);
            return $json(['success' => false, 'message' => 'mkswap failed', 'logs' => $logs]);
        }

        $cfgPrio = zram_config_read();
        $ssdPrio = max(0, min(32767, intval($cfgPrio['ssd_swap_priority'] ?? 10)));
        if (zram_run("swapon " . escapeshellarg($swapFile) . " -p " . $ssdPrio, $logs) !== 0) {
            @unlink($swapFile);
            @rmdir(dirname($swapFile)); // Clean up empty .swap dir
            $hint = $isBtrfs ? ' (btrfs RAID or compressed mount may not support swap files)' : '';
            return $json(['success' => false, 'message' => 'swapon failed' . $hint, 'logs' => $logs]);
        }

        zram_config_write([
            'ssd_swap_enabled' => 'yes',
            'ssd_swap_path'    => $swapFile,
            'ssd_swap_size'    => $sizeStr,
            'ssd_swap_mount'   => $mount,
            'ssd_swap_backing' => 'file',
        ]);

        return $json(['success' => true, 'message' => "Created {$sizeStr} swap file on $mount", 'logs' => $logs]);
    }

    if ($action === 'remove_disk_swap' || $action === 'remove_ssd_swap') {
        $cfg      = zram_config_read();
        $swapFile = $cfg['ssd_swap_path'] ?? '';
        $backing  = $cfg['ssd_swap_backing'] ?? 'file';

        if (empty($swapFile) || !file_exists($swapFile)) {
            return $json(['success' => false, 'message' => 'No disk swap file found', 'logs' => $logs]);
        }

        $safety = zram_evacuation_safe('', $logs);
        if (!$safety['safe']) {
            return $json(['success' => false, 'message' => $safety['error'], 'logs' => $logs]);
        }

        if ($backing === 'zvol') {
            // No losetup-equivalent detach step — a zvol isn't "attached" the way
            // a loop device is. Resolve the stable /dev/zvol/<pool>/<dataset>
            // symlink to the /dev/zdN alias /proc/swaps actually reports, swapoff
            // it, then destroy the underlying zvol dataset entirely. This is an
            // explicit user REMOVE (unlike the .plg uninstall path, which only
            // swapoffs and preserves the dataset — see docs/specs/TIER2_ZVOL_SWAP.md).
            $zvolDev = realpath($swapFile) ?: '';
            if ($zvolDev !== '') {
                $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
                if (zram_actions_in_swaps($swaps, $zvolDev)) {
                    if (zram_run("swapoff " . escapeshellarg($zvolDev), $logs) !== 0) {
                        return $json(['success' => false, 'message' => 'swapoff zvol failed', 'logs' => $logs]);
                    }
                }
            }
            $zvolDataset = preg_replace('#^' . preg_quote(ZRAM_DEV_DIR, '#') . '/zvol/#', '', $swapFile);
            // A failed destroy must not report success: the settings would
            // forget a volume that still exists. The swap is already off, so
            // the next REMOVE click only has to destroy the volume.
            if (zram_run("zfs destroy " . escapeshellarg($zvolDataset), $logs) !== 0) {
                return $json(['success' => false, 'message' => 'zfs destroy failed — the swap is off but the ZFS volume still exists', 'logs' => $logs]);
            }
        } elseif ($backing === 'loop') {
            // Resolve the attached loop device via the stable image path
            $loopDev = zram_actions_loop_for($swapFile);
            if ($loopDev !== '') {
                $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
                if (zram_actions_in_swaps($swaps, $loopDev)) {
                    if (zram_run("swapoff " . escapeshellarg($loopDev), $logs) !== 0) {
                        return $json(['success' => false, 'message' => 'swapoff loop device failed', 'logs' => $logs]);
                    }
                }
                // If the detach fails, the image must stay: deleting it would
                // leave a loop device that holds the deleted file's space.
                if (zram_run("losetup -d " . escapeshellarg($loopDev), $logs) !== 0) {
                    return $json(['success' => false, 'message' => 'losetup -d failed — the loop device is still attached', 'logs' => $logs]);
                }
            }
        } else {
            $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
            if (zram_actions_in_swaps($swaps, $swapFile)) {
                // If swapoff fails, the file must stay: deleting a swap file
                // that is in use would hide it from the settings but keep it active.
                if (zram_run("swapoff " . escapeshellarg($swapFile), $logs) !== 0) {
                    return $json([
                        'success' => false,
                        'message' => 'swapoff failed — pages may not have evacuated. Retry once memory pressure subsides.',
                        'logs' => $logs,
                    ]);
                }
            }
        }

        // No-op for zvol: $swapFile (/dev/zvol/<pool>/<dataset>) no longer exists
        // once zfs destroy above removes the dataset, so this silently does nothing.
        @unlink($swapFile);
        zram_config_write([
            'ssd_swap_enabled' => 'no',
            'ssd_swap_path'    => '',
            'ssd_swap_size'    => $cfg['ssd_swap_size'],
            'ssd_swap_mount'   => '',
            'ssd_swap_backing' => 'auto',
        ]);

        return $json(['success' => true, 'message' => 'Disk swap file removed', 'logs' => $logs]);
    }

    // Re-activate an EXISTING (but inactive) disk swap file — no re-dd / re-mkswap,
    // the file already carries its swap signature. Surfaced as the ACTIVATE button
    // on the Tier 2 card for the "File exists, not active" state, which the boot
    // path can leave behind when the mount came up later than zram_init.sh's
    // 5-minute retry window (long array outage, USB-stick replacement, …). The
    // array-start activation (event/disks_mounted) also turns it on; this is the manual
    // override. Mirrors zram_init.sh's activate_disk_swap(). See docs/specs/TIER2_RECOVERY.md.
    if ($action === 'activate_disk_swap' || $action === 'activate_ssd_swap') {
        $cfg      = zram_config_read();
        $swapFile = $cfg['ssd_swap_path'] ?? '';
        $backing  = $cfg['ssd_swap_backing'] ?? 'file';

        if (empty($swapFile) || !file_exists($swapFile)) {
            return $json(['success' => false, 'message' => 'No disk swap file to activate. Create one first.', 'logs' => $logs]);
        }

        $prio = max(0, min(32767, intval($cfg['ssd_swap_priority'] ?? 10)));

        if ($backing === 'zvol') {
            // No attach step needed — the zvol block device exists for as long as
            // the ZFS dataset does. Just resolve the stable symlink to the
            // /dev/zdN alias /proc/swaps reports.
            $zvolDev = realpath($swapFile) ?: '';
            if ($zvolDev === '' || !file_exists($zvolDev)
                || !preg_match('#^' . preg_quote(ZRAM_DEV_DIR, '#') . '/zd\d+(p\d+)?$#', $zvolDev)) {
                return $json(['success' => false, 'message' => 'zvol device not found for ' . $swapFile, 'logs' => $logs]);
            }

            $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
            if (zram_actions_in_swaps($swaps, $zvolDev)) {
                if (($cfg['ssd_swap_enabled'] ?? 'no') !== 'yes') {
                    zram_config_write(['ssd_swap_enabled' => 'yes']);
                }
                return $json(['success' => true, 'message' => 'ZFS zvol swap is already active', 'logs' => $logs]);
            }

            if (zram_run('swapon ' . escapeshellarg($swapFile) . ' -p ' . $prio, $logs) !== 0) {
                return $json(['success' => false, 'message' => 'swapon zvol failed', 'logs' => $logs]);
            }

            zram_config_write(['ssd_swap_enabled' => 'yes']);
            zram_cmd_log("Activated ZFS zvol swap $swapFile (priority $prio)", 'cmd');
            return $json(['success' => true, 'message' => "Activated ZFS zvol swap (priority $prio)", 'logs' => $logs]);
        }

        if ($backing === 'loop') {
            // Resolve attached loop device for this image (idempotency guard)
            $loopDev = zram_actions_loop_for($swapFile);

            $freshLoop = false;
            // Already active (loop attached + in /proc/swaps)?
            if ($loopDev !== '') {
                $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
                if (zram_actions_in_swaps($swaps, $loopDev)) {
                    if (($cfg['ssd_swap_enabled'] ?? 'no') !== 'yes') {
                        zram_config_write(['ssd_swap_enabled' => 'yes']);
                    }
                    return $json(['success' => true, 'message' => 'Loop-backed swap is already active', 'logs' => $logs]);
                }
                // Loop attached but not in /proc/swaps — stale after crash; re-use it
            } else {
                // Loop not attached — attach fresh
                $losetupOut = [];
                $losetupRet = 0;
                zram_exec("losetup -f --show " . escapeshellarg($swapFile) . " 2>&1", $losetupOut, $losetupRet);
                $loopDev = trim(end($losetupOut) ?: '');
                $logs[] = ['cmd' => "losetup -f --show $swapFile", 'output' => $loopDev, 'status' => $losetupRet];
                if ($losetupRet !== 0 || !preg_match('#^' . preg_quote(ZRAM_DEV_DIR, '#') . '/loop\d+$#', $loopDev)) {
                    return $json(['success' => false, 'message' => 'losetup failed: ' . $loopDev, 'logs' => $logs]);
                }
                $freshLoop = true;
            }

            if (zram_run('swapon ' . escapeshellarg($loopDev) . ' -p ' . $prio, $logs) !== 0) {
                // Detach a loop device this action attached. Keep one that was attached before.
                if ($freshLoop) zram_exec('losetup -d ' . escapeshellarg($loopDev) . ' 2>/dev/null');
                return $json(['success' => false, 'message' => 'swapon loop device failed', 'logs' => $logs]);
            }

            zram_config_write(['ssd_swap_enabled' => 'yes']);
            zram_cmd_log("Activated loop-backed swap $swapFile via $loopDev (priority $prio)", 'cmd');
            return $json(['success' => true, 'message' => "Activated loop-backed swap ($loopDev, priority $prio)", 'logs' => $logs]);
        }

        // --- Direct-file path (unchanged from existing implementation) ---
        $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
        if (zram_actions_in_swaps($swaps, $swapFile)) {
            if (($cfg['ssd_swap_enabled'] ?? 'no') !== 'yes') {
                zram_config_write(['ssd_swap_enabled' => 'yes']);
            }
            return $json(['success' => true, 'message' => 'Disk swap is already active', 'logs' => $logs]);
        }

        zram_run('swaplabel -L ' . escapeshellarg(ZRAM_SSD_LABEL) . ' ' . escapeshellarg($swapFile), $logs);

        if (zram_run('swapon ' . escapeshellarg($swapFile) . ' -p ' . $prio, $logs) !== 0) {
            return $json(['success' => false, 'message' => 'swapon failed — see log. The mount may not be ready yet, or the filesystem may not support swap files.', 'logs' => $logs]);
        }

        zram_config_write(['ssd_swap_enabled' => 'yes']);
        zram_cmd_log("Activated disk swap file $swapFile (priority $prio)", 'cmd');
        return $json(['success' => true, 'message' => "Activated disk swap file ($prio priority)", 'logs' => $logs]);
    }

    // --- SETTINGS ACTIONS ---

    // Generic per-key settings update for the auto-save UI. The whitelist gates
    // what keys are writable from the page; per-key validation normalises the
    // value before it lands in settings.ini; targeted side effects fire only
    // when the saved key requires them, so most blurs are a single config write.
    // See docs/specs/SETTINGS_AUTO_SAVE.md.
    if ($action === 'update_setting') {
        $key = $str('key') ?: '';
        $rawValue = $str('value');
        if ($rawValue === null) $rawValue = '';

        $allowed = ['enabled','refresh_interval','collection_interval','swappiness',
                    'debug','console_visible','zram_size','zram_percent','zram_algo',
                    'ssd_swap_allow_zfs','thp_mode','thp_compaction'];
        if (!in_array($key, $allowed, true)) {
            return $json(['success' => false, 'message' => 'Invalid setting key', 'logs' => $logs]);
        }

        $v = zram_actions_validate_setting($key, $rawValue);
        if (isset($v['error'])) {
            return $json(['success' => false, 'message' => $v['error'], 'logs' => $logs]);
        }
        $value = $v['value'];

        zram_config_write([$key => $value]);

        // Targeted side effects — only fire for keys that need immediate kernel/daemon impact.
        if ($key === 'swappiness') {
            zram_run("sysctl -q vm.swappiness=" . escapeshellarg((string)$value), $logs);
        }
        if ($key === 'thp_mode' || $key === 'thp_compaction') {
            $thpCfg = zram_config_read();
            zram_apply_thp($thpCfg['thp_mode'], $thpCfg['thp_compaction'], $logs);
        }
        if ($key === 'debug') {
            zram_debug_reset();
        }
        if ($key === 'collection_interval' || $key === 'debug') {
            $docroot = ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ZRAM_EMHTTP_ROOT;
            $initScript = "$docroot/plugins/unraid-zram-card/zram_init.sh";
            if (file_exists($initScript)) {
                zram_run("nohup $initScript > /dev/null 2>&1 & disown", $logs);
            }
        }

        return $json(['success' => true, 'message' => "Saved $key", 'key' => $key, 'value' => $value, 'logs' => $logs]);
    }

    // Per-tier priority override (gated, paired-key endpoint). The single-key
    // update_setting action intentionally does NOT accept zram_priority /
    // ssd_swap_priority — they have to come through here so the comparison rule
    // (Tier 1 strictly greater than Tier 2) is enforced atomically. Inverting
    // or equalising would route every page to disk first and bypass ZRAM. See
    // docs/specs/PER_TIER_PRIORITY_OVERRIDE.md.
    if ($action === 'update_priorities') {
        $z = $int('zram', 1, 32767);
        $s = $int('ssd', 0, 32767);
        if ($z === false) {
            return $json(['success' => false, 'message' => 'Tier 1 priority must be 1-32767', 'logs' => $logs]);
        }
        if ($s === false) {
            return $json(['success' => false, 'message' => 'Tier 2 priority must be 0-32767', 'logs' => $logs]);
        }
        if ($z <= $s) {
            return $json(['success' => false, 'message' => 'Tier 1 priority must be strictly greater than Tier 2 — otherwise pages route to disk first and ZRAM is bypassed.', 'logs' => $logs]);
        }

        // Persist atomically (config-write is the always-succeeds part; live swapoff/swapon below is best-effort).
        zram_config_write([
            'zram_priority'     => (string)$z,
            'ssd_swap_priority' => (string)$s,
        ]);

        $warnings = [];

        // Best-effort live re-prioritisation for Tier 1 if the device is currently active
        $ourDev = zram_get_our_device();
        if ($ourDev && file_exists(ZRAM_SYS_BLOCK . "/$ourDev")) {
            $devPath = ZRAM_DEV_DIR . "/$ourDev";
            $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
            // Exact name match: /dev/zram1 must not match a /dev/zram10 line.
            if (zram_actions_in_swaps($swaps, $devPath)) {
                $safety = zram_evacuation_safe($devPath, $logs);
                if (!$safety['safe']) {
                    $warnings[] = 'Tier 1 priority not applied live: ' . ($safety['error'] ?? 'safety check failed');
                } else {
                    if (zram_run('swapoff ' . escapeshellarg($devPath), $logs) === 0) {
                        if (zram_run('swapon ' . escapeshellarg($devPath) . ' -p ' . intval($z), $logs) !== 0) {
                            $warnings[] = 'Tier 1 swapon failed — try CREATE';
                        }
                    } else {
                        $warnings[] = 'Tier 1 swapoff failed — priority will apply on next CREATE';
                    }
                }
            }
        }

        // Tier 2 disk swap
        $cfgNow = zram_config_read();
        $ssdPath = $cfgNow['ssd_swap_path'] ?? '';
        $backing  = $cfgNow['ssd_swap_backing'] ?? 'file';
        if ($ssdPath && file_exists($ssdPath)) {
            // Resolve the active swap target (loop-backed images use the loop
            // device; zvol-backed swap uses the resolved /dev/zdN alias)
            $swapTarget = $ssdPath;
            if ($backing === 'loop') {
                // No loop device found — can't live-reprioritise; config is already
                // persisted above. An empty target means: skip.
                $swapTarget = zram_actions_loop_for($ssdPath);
            } elseif ($backing === 'zvol') {
                // No zvol device found — can't live-reprioritise; config is already persisted above
                $swapTarget = realpath($ssdPath) ?: '';
            }
            $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
            $isActive = $swapTarget !== '' && zram_actions_in_swaps($swaps, $swapTarget);
            if ($isActive) {
                $safety = zram_evacuation_safe('', $logs);
                if (!$safety['safe']) {
                    $warnings[] = 'Tier 2 priority not applied live: ' . ($safety['error'] ?? 'safety check failed');
                } else {
                    if (zram_run('swapoff ' . escapeshellarg($swapTarget), $logs) === 0) {
                        if (zram_run('swapon ' . escapeshellarg($swapTarget) . ' -p ' . intval($s), $logs) !== 0) {
                            $warnings[] = 'Tier 2 swapon failed — try CREATE';
                        }
                    } else {
                        $warnings[] = 'Tier 2 swapoff failed — priority will apply on next CREATE';
                    }
                }
            }
        }

        $msg = empty($warnings)
            ? "Priorities saved (Tier 1=$z, Tier 2=$s)"
            : 'Saved with warnings: ' . implode('; ', $warnings);
        return $json([
            'success' => true,
            'message' => $msg,
            'zram_priority' => $z,
            'ssd_swap_priority' => $s,
            'warnings' => $warnings,
            'logs' => $logs,
        ]);
    }

    if ($action === 'update_swappiness') {
        $val = $int('val', 0, 200);
        if ($val === false) $val = 150;
        zram_run("sysctl vm.swappiness=" . intval($val), $logs);
        zram_config_write(['swappiness' => $val]);
        return $json(['success' => true, 'message' => "Swappiness set to $val", 'logs' => $logs]);
    }

    if ($action === 'update_debug') {
        $val = $str('val') === 'yes' ? 'yes' : 'no';
        zram_config_write(['debug' => $val]);
        zram_debug_reset();
        zram_log("Debug mode set to $val", 'INFO');
        return $json(['success' => true, 'message' => "Debug set to $val", 'logs' => $logs]);
    }

    if ($action === 'check_safety') {
        $dev = $str('device') ?: '';
        $safety = zram_evacuation_safe($dev, $logs);
        return $json(['safe' => $safety['safe'], 'message' => $safety['error'] ?? '', 'logs' => $logs]);
    }

    // --- LOG ACTIONS ---

    if ($action === 'clear_cmd_log') {
        @file_put_contents(ZRAM_CMD_LOG, "");
        zram_cmd_log("Console cleared.");
        return $json(['success' => true]);
    }

    if ($action === 'append_cmd_log') {
        $msg = $str('msg') ?: '';
        $type = $str('type') ?: '';
        zram_cmd_log($msg, $type);
        return $json(['success' => true]);
    }

    if ($action === 'clear_log') {
        @file_put_contents(ZRAM_DEBUG_LOG, "");
        zram_log("Log cleared by user.", 'INFO');
        return $json(['success' => true, 'message' => 'Debug log cleared']);
    }

    // Unified activity feed — merges cmd.log (operator-friendly JSON lines) and
    // debug.log (timestamped plain text). See docs/specs/UNIFIED_ACTIVITY_LOG.md.
    //
    // Why merge instead of pick: zram_run() writes to both — cmd.log gets
    // "cmd -> Success" (friendly), debug.log gets "CMD: cmd | Status: 0 | Output: ..."
    // (diagnostic). Showing both is duplication, so we drop debug.log's CMD: lines
    // and let cmd.log carry shell-command rows. debug.log carries everything else
    // (INFO events, ERROR conditions, DEBUG when enabled).
    if ($action === 'view_activity') {
        $entries = [];

        // cmd.log: JSON lines per entry
        if (file_exists(ZRAM_CMD_LOG)) {
            $lines = @file(ZRAM_CMD_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $j = json_decode($line, true);
                if (!is_array($j) || !isset($j['msg'], $j['time'])) continue;
                $type = $j['type'] ?? '';
                $level = ($type === 'err') ? 'ERROR' : (($type === 'debug') ? 'OUT' : 'CMD');
                $entries[] = [
                    'ts'    => $j['time'],
                    // Full date+time sort key. New entries carry 'dt'; legacy
                    // (pre-fix) entries only have 'time', so assume today — they
                    // age out of the transient /tmp log quickly.
                    'sort'  => $j['dt'] ?? (date('Y-m-d') . ' ' . $j['time']),
                    'level' => $level,
                    'msg'   => $j['msg'],
                ];
            }
        }

        // debug.log: plain text "[YYYY-MM-DD HH:MM:SS] [LEVEL] msg"
        if (file_exists(ZRAM_DEBUG_LOG)) {
            $lines = @file(ZRAM_DEBUG_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+\[(\w+)\]\s+(.*)$/', $line, $m)) continue;
                $msg = $m[3];
                // Drop "CMD: ..." dupes — already in cmd.log with friendlier formatting
                if (strpos($msg, 'CMD: ') === 0) continue;
                $entries[] = [
                    'ts'    => substr($m[1], 11),  // display: strip date, keep HH:MM:SS
                    'sort'  => $m[1],               // sort: full Y-m-d H:i:s (cross-day correct)
                    'level' => strtoupper($m[2]),
                    'msg'   => $msg,
                ];
            }
        }

        // Sort chronologically by the full date+time key (NOT time-of-day, or
        // yesterday's 23:xx would sort after today's 09:xx).
        usort($entries, function($a, $b) { return strcmp($a['sort'], $b['sort']); });

        // Cap at most-recent 500 to keep payload small and the DOM light
        if (count($entries) > 500) $entries = array_slice($entries, -500);

        // Drop the internal sort key; the client only needs ts/level/msg.
        $entries = array_map(function($e) {
            return ['ts' => $e['ts'], 'level' => $e['level'], 'msg' => $e['msg']];
        }, $entries);

        return $json(['success' => true, 'entries' => $entries]);
    }

    if ($action === 'clear_activity') {
        @file_put_contents(ZRAM_CMD_LOG, "");
        @file_put_contents(ZRAM_DEBUG_LOG, "");
        zram_log("Logs cleared by user.", 'INFO');
        zram_cmd_log("Activity log cleared.");
        return $json(['success' => true, 'message' => 'Activity log cleared']);
    }

    // Unknown action
    return $json(['success' => false, 'message' => "Unknown action: $action"]);
}

/**
 * Web entry: read the real request values and send the response. $get is the
 * query array ($_GET). PHPUnit calls this with ZRAM_TEST_EXIT_THROWS defined.
 *
 * @param array<string,mixed> $get
 */
function zram_actions_main(array $get): void {
    $_var = @parse_ini_file(ZRAM_VAR_INI, false, INI_SCANNER_RAW) ?: [];
    $_r = zram_actions_handle(zram_actions_request_string($get['action'] ?? ''), $get, zram_actions_request_string($_var['csrf_token'] ?? ''));
    http_response_code($_r['status']);
    if ($_r['type'] === 'text') {
        header('Content-Type: text/plain; charset=utf-8');
        if ($_r['status'] === 200) header('Cache-Control: no-cache');
        echo $_r['body'];
    } elseif ($_r['type'] === 'file') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        readfile($_r['body']);
    } else {
        header('Content-Type: application/json');
        echo json_encode($_r['body']);
    }
    zram_exit();
}

if (!defined('ZRAM_ACTIONS_LIBRARY_ONLY')) {
    zram_actions_main($_GET);
}
