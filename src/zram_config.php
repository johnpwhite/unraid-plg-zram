<?php
/**
 * <module_context>
 *   <name>zram_config</name>
 *   <description>Shared configuration, logging, device filtering, and label constants for ZRAM Card plugin</description>
 *   <dependencies>None (foundation module)</dependencies>
 *   <consumers>zram_actions, zram_status, zram_collector, ZramCard, UnraidZramCard.page</consumers>
 * </module_context>
 */

defined('ZRAM_LABEL')        || define('ZRAM_LABEL', 'ZRAM_CARD');
defined('ZRAM_SSD_LABEL')    || define('ZRAM_SSD_LABEL', 'ZRAM_CARD_DISK');
defined('ZRAM_LEGACY_SSD_LABEL') || define('ZRAM_LEGACY_SSD_LABEL', 'ZRAM_CARD_SSD');
defined('ZRAM_CONFIG_FILE')  || define('ZRAM_CONFIG_FILE', '/boot/config/plugins/unraid-zram-card/settings.ini');
defined('ZRAM_LOG_DIR')      || define('ZRAM_LOG_DIR', '/tmp/unraid-zram-card');
defined('ZRAM_DEBUG_LOG')    || define('ZRAM_DEBUG_LOG', ZRAM_LOG_DIR . '/debug.log');
defined('ZRAM_CMD_LOG')      || define('ZRAM_CMD_LOG', ZRAM_LOG_DIR . '/cmd.log');
defined('ZRAM_LOCK_FILE')    || define('ZRAM_LOCK_FILE', ZRAM_LOG_DIR . '/config.lock');
defined('ZRAM_DEVICE_FILE')  || define('ZRAM_DEVICE_FILE', ZRAM_LOG_DIR . '/device.conf');
defined('ZRAM_HISTORY_FILE') || define('ZRAM_HISTORY_FILE', ZRAM_LOG_DIR . '/history.json');
defined('ZRAM_PID_FILE')     || define('ZRAM_PID_FILE', ZRAM_LOG_DIR . '/collector.pid');

// System paths. Each is a constant with a default so PHPUnit can point the
// plugin at temp files (tests/bootstrap.php defines them first).
defined('ZRAM_PROC_SWAPS')      || define('ZRAM_PROC_SWAPS', '/proc/swaps');
defined('ZRAM_PROC_MEMINFO')    || define('ZRAM_PROC_MEMINFO', '/proc/meminfo');
defined('ZRAM_PROC_MOUNTS')     || define('ZRAM_PROC_MOUNTS', '/proc/mounts');
defined('ZRAM_PROC_VMSTAT')     || define('ZRAM_PROC_VMSTAT', '/proc/vmstat');
defined('ZRAM_SWAPPINESS_FILE') || define('ZRAM_SWAPPINESS_FILE', '/proc/sys/vm/swappiness');
defined('ZRAM_SYS_BLOCK')       || define('ZRAM_SYS_BLOCK', '/sys/block');
defined('ZRAM_SYS_CLASS_BLOCK') || define('ZRAM_SYS_CLASS_BLOCK', '/sys/class/block');
defined('ZRAM_DEV_DIR')         || define('ZRAM_DEV_DIR', '/dev');
defined('ZRAM_VAR_INI')         || define('ZRAM_VAR_INI', '/var/local/emhttp/var.ini');
defined('ZRAM_EMHTTP_ROOT')     || define('ZRAM_EMHTTP_ROOT', '/usr/local/emhttp');
defined('ZRAM_PROC_DIR')      || define('ZRAM_PROC_DIR', '/proc');
defined('ZRAM_LIBVIRT_HOOK')  || define('ZRAM_LIBVIRT_HOOK', '/etc/libvirt/hooks/qemu');
defined('ZRAM_LIBVIRT_RUN')   || define('ZRAM_LIBVIRT_RUN', '/run/libvirt/qemu');
defined('ZRAM_CGROUP_DIR')    || define('ZRAM_CGROUP_DIR', '/sys/fs/cgroup');
defined('ZRAM_UNRAID_VERSION_FILE') || define('ZRAM_UNRAID_VERSION_FILE', '/etc/unraid-version');
// The folders zram_find_binary() searches, in order. Tests point this at a temp folder of stubs.
defined('ZRAM_SBIN_DIRS')        || define('ZRAM_SBIN_DIRS', ['/sbin', '/usr/sbin']);
// The folder that holds the drives the plugin may use for disk swap, and how
// many 250 ms polls to wait for a new ZFS volume device node.
defined('ZRAM_MNT_ROOT')          || define('ZRAM_MNT_ROOT', '/mnt');
defined('ZRAM_ZVOL_WAIT_TRIES')   || define('ZRAM_ZVOL_WAIT_TRIES', 20);

defined('ZRAM_DEFAULTS') || define('ZRAM_DEFAULTS', [
    'enabled'             => 'yes',
    'refresh_interval'    => '3000',
    'collection_interval' => '3',
    'swappiness'          => '150',
    'thp_mode'            => 'system',
    'thp_compaction'      => 'system',
    'debug'               => 'no',
    'console_visible'     => 'yes',
    'zram_size'           => 'auto',
    'zram_percent'        => '50',
    'zram_algo'           => 'zstd',
    'ssd_swap_enabled'    => 'no',
    'ssd_swap_path'       => '',
    'ssd_swap_size'       => '16G',
    'ssd_swap_mount'      => '',
    'zram_priority'       => '100',
    'ssd_swap_priority'   => '10',
    'ssd_swap_backing'    => 'auto',
    'ssd_swap_allow_zfs'  => 'no',
    'oom_protect_enabled' => 'no',
    'oom_levels'          => '',
    'oom_default_level'   => 'normal',
    'oom_proc_patterns'   => '',
    'oom_auto_deps'       => '',
    'oom_oom_group'       => 'yes',
    'vm_memory_min'       => 'no',
]);

if (!is_dir(ZRAM_LOG_DIR)) @mkdir(ZRAM_LOG_DIR, 0777, true);

/** Read config merged with defaults. Never returns false. */
function zram_config_read(): array {
    $loaded = @parse_ini_file(ZRAM_CONFIG_FILE);
    return is_array($loaded) ? array_merge(ZRAM_DEFAULTS, $loaded) : ZRAM_DEFAULTS;
}

/** Atomic config write with flock. Merges $updates into current config. */
function zram_config_write(array $updates): bool {
    if (!is_dir(dirname(ZRAM_CONFIG_FILE))) {
        @mkdir(dirname(ZRAM_CONFIG_FILE), 0777, true);
    }
    $fp = @fopen(ZRAM_LOCK_FILE, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        return false;
    }
    $current = @parse_ini_file(ZRAM_CONFIG_FILE);
    $merged = array_merge(ZRAM_DEFAULTS, is_array($current) ? $current : [], $updates);
    $lines = [];
    foreach ($merged as $k => $v) {
        // One value is one line between double quotes. A quote, a line break or a
        // NUL byte in a value would break the file (every setting would then read
        // as the default) or add a key. Remove them.
        $v = str_replace(['"', "\r", "\n", "\0"], '', (string) $v);
        $lines[] = "$k=\"$v\"";
    }
    $ok = @file_put_contents(ZRAM_CONFIG_FILE, implode("\n", $lines) . "\n") !== false;
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

/** Cached debug flag to avoid flash reads on every log call. */
$_zram_debug_cached = null;

function zram_log(string $msg, string $level = 'DEBUG'): void {
    global $_zram_debug_cached;
    $level = strtoupper($level);
    if ($level === 'DEBUG') {
        if ($_zram_debug_cached === null) {
            $loaded = @parse_ini_file(ZRAM_CONFIG_FILE);
            $_zram_debug_cached = ($loaded['debug'] ?? 'no') === 'yes';
        }
        if (!$_zram_debug_cached) return;
    }
    $logMsg = date('[Y-m-d H:i:s] ') . "[$level] $msg\n";
    @file_put_contents(ZRAM_DEBUG_LOG, $logMsg, FILE_APPEND);
}

/** Reset cached debug flag (call after config change). */
function zram_debug_reset(): void {
    global $_zram_debug_cached;
    $_zram_debug_cached = null;
}

/** Append to command log (JSON-lines format). */
function zram_cmd_log(string $msg, string $type = ''): void {
    // 'time' (H:i:s) is the display value; 'dt' (full Y-m-d H:i:s) is the sort
    // key so the activity feed orders correctly across midnight — without the
    // date, yesterday's 23:xx sorts after today's 09:xx. See UNIFIED_ACTIVITY_LOG.md.
    $entry = ['time' => date('H:i:s'), 'dt' => date('Y-m-d H:i:s'), 'msg' => $msg, 'type' => $type];
    @file_put_contents(ZRAM_CMD_LOG, json_encode($entry) . "\n", FILE_APPEND);
}

/**
 * Run a shell command the way exec() does: $out gets the output lines
 * appended, $ret gets the exit code, and the return value is the last line
 * (or false). Every shell call in the plugin goes through here, so PHPUnit
 * can replace the shell with zram_set_exec() and test each command path
 * without a real zramctl, swapon or losetup. See docs/specs/TEST_SEAMS.md.
 *
 * @param array<int,string>|null $out
 * @return string|false
 */
function zram_exec(string $cmd, ?array &$out = null, ?int &$ret = null) {
    if ($out === null) $out = [];
    $fn = $GLOBALS['_zram_exec_override'] ?? null;
    if ($fn !== null) {
        $ret = 0;
        return $fn($cmd, $out, $ret);
    }
    return exec($cmd, $out, $ret);
}

/** Replace the shell for tests (null restores the real exec()). */
function zram_set_exec(?callable $fn): void {
    $GLOBALS['_zram_exec_override'] = $fn;
}

/**
 * Signal that stands in for exit() under PHPUnit. A test defines
 * ZRAM_TEST_EXIT_THROWS; zram_exit() then throws this instead of ending the
 * process. In production the constant is not defined and zram_exit() exits.
 */
final class ZramExitSignal extends RuntimeException {}

/** End the request. The one place the web entry code calls exit. */
function zram_exit(): void {
    if (defined('ZRAM_TEST_EXIT_THROWS') && ZRAM_TEST_EXIT_THROWS) {
        throw new ZramExitSignal();
    }
    exit;
}

/** Run a shell command, log it, return exit code. */
function zram_run(string $cmd, array &$logs): int {
    zram_exec($cmd . " 2>&1", $out, $ret);
    $output = implode(" ", $out);
    $logs[] = ['cmd' => $cmd, 'output' => $output, 'status' => $ret];
    zram_log("CMD: $cmd | Status: $ret | Output: $output", 'INFO');
    $status = $ret === 0 ? 'Success' : 'Fail';
    zram_cmd_log("$cmd -> $status", $ret === 0 ? '' : 'err');
    if ($output) zram_cmd_log("  > $output", 'debug');
    return $ret;
}

defined('ZRAM_THP_FILE')        || define('ZRAM_THP_FILE', '/sys/kernel/mm/transparent_hugepage/enabled');
defined('ZRAM_COMPACTION_FILE') || define('ZRAM_COMPACTION_FILE', '/proc/sys/vm/compaction_proactiveness');
defined('ZRAM_THP_ORIG_FILE')   || define('ZRAM_THP_ORIG_FILE', ZRAM_LOG_DIR . '/thp-original.conf');

/** Allowed values for the huge pages setting ('system' = leave the kernel value). */
function zram_thp_modes(): array {
    return ['system', 'madvise', 'always', 'never'];
}

/**
 * Is $v a valid background compaction setting? The page offers the kernel
 * number (vm.compaction_proactiveness, 0-100). 'system' (no value chosen yet,
 * the plugin changes nothing) and 'off' (older versions, same as 0) stay valid
 * so saved settings keep working.
 */
function zram_thp_compaction_valid(string $v): bool {
    if ($v === 'system' || $v === 'off') return true;
    return preg_match('/^\d{1,3}$/', $v) === 1 && intval($v) <= 100;
}

/** The kernel number for a compaction setting, or null for 'system'. */
function zram_thp_compaction_number(string $v): ?int {
    if ($v === 'off') return 0;
    if ($v !== 'system' && zram_thp_compaction_valid($v)) return intval($v);
    return null;
}

/**
 * Read the active huge pages mode from the sysfs text.
 * The kernel brackets the active word: "always [madvise] never" -> "madvise".
 * Returns '' when the file is missing or has no bracketed word.
 */
function zram_thp_current(string $thpFile = ZRAM_THP_FILE): string {
    $raw = @file_get_contents($thpFile);
    if ($raw === false) return '';
    if (preg_match('/\[(always|madvise|never)\]/', $raw, $m)) return $m[1];
    // Plain single word (no brackets) is accepted too.
    return in_array(trim($raw), ['always', 'madvise', 'never'], true) ? trim($raw) : '';
}

/**
 * Huge-page fault counters since boot, from /proc/vmstat: how many times a
 * program wanted a huge page and got one (alloc), and how many times the
 * kernel tried and fell back to small pages (fallback). A high fallback share
 * with THP "always" is the sign that the kernel wastes time on compaction.
 * Returns ['alloc' => int, 'fallback' => int, 'failed_pct' => ?int].
 */
function zram_thp_fault_stats(string $vmstatFile = ZRAM_PROC_VMSTAT): array {
    $raw = (string)@file_get_contents($vmstatFile);
    $alloc = preg_match('/^thp_fault_alloc (\d+)$/m', $raw, $m) ? intval($m[1]) : 0;
    $fallback = preg_match('/^thp_fault_fallback (\d+)$/m', $raw, $m) ? intval($m[1]) : 0;
    $total = $alloc + $fallback;
    return [
        'alloc'      => $alloc,
        'fallback'   => $fallback,
        'failed_pct' => $total > 0 ? intval(round($fallback * 100 / $total)) : null,
    ];
}

/**
 * Apply the huge pages mode and background compaction setting.
 *
 * 'system' leaves the kernel value alone. If the plugin changed the value
 * earlier in this boot, 'system' puts the original value back. The original
 * values are saved once, before the first change, in $origFile.
 * A missing sysfs file (kernel without THP) makes this a logged no-op.
 *
 * The file paths and $sysctl are parameters so PHPUnit can run this against
 * temp files. $sysctl takes (int $value, array &$logs) and returns an exit
 * code. Production callers pass only the first three arguments.
 */
function zram_apply_thp(string $mode, string $compaction, array &$logs,
                        string $thpFile = ZRAM_THP_FILE,
                        string $origFile = ZRAM_THP_ORIG_FILE,
                        ?callable $sysctl = null,
                        string $compFile = ZRAM_COMPACTION_FILE): void {
    if (!in_array($mode, zram_thp_modes(), true) || !zram_thp_compaction_valid($compaction)) {
        zram_log("THP: ignored invalid values mode=$mode compaction=$compaction", 'WARN');
        return;
    }
    if ($sysctl === null) {
        $sysctl = function (int $value, array &$l): int {
            return zram_run('sysctl -q -w vm.compaction_proactiveness=' . escapeshellarg((string)$value), $l);
        };
    }

    // Originals: written once, the first time a non-system value is requested.
    $orig = is_file($origFile) ? (@parse_ini_file($origFile) ?: []) : [];
    if (!$orig && ($mode !== 'system' || $compaction !== 'system')) {
        $lines = [];
        $curMode = zram_thp_current($thpFile);
        if ($curMode !== '') $lines[] = "enabled=$curMode";
        $curComp = trim((string)@file_get_contents($compFile));
        if (preg_match('/^\d+$/', $curComp)) $lines[] = "compaction=$curComp";
        if ($lines) {
            @file_put_contents($origFile, implode("\n", $lines) . "\n");
            $orig = @parse_ini_file($origFile) ?: [];
        }
    }

    // Huge pages mode
    if (!is_file($thpFile)) {
        zram_log('THP: ' . $thpFile . ' not found (kernel without THP), skipping', 'INFO');
    } else {
        $want = $mode;
        if ($mode === 'system') {
            $want = in_array($orig['enabled'] ?? '', ['always', 'madvise', 'never'], true) ? $orig['enabled'] : '';
        }
        $cur = zram_thp_current($thpFile);
        if ($want !== '' && $want !== $cur) {
            if (@file_put_contents($thpFile, $want) === false) {
                zram_log("THP: could not write $want to $thpFile", 'ERROR');
                zram_cmd_log("Huge pages: could not set $want", 'err');
            } else {
                zram_cmd_log("Huge pages: set to $want (was $cur)");
            }
        }
    }

    // Background compaction
    if (!is_file($compFile)) {
        zram_log('THP: ' . $compFile . ' not found (old kernel), skipping compaction setting', 'INFO');
    } else {
        $want = zram_thp_compaction_number($compaction);
        if ($want === null && isset($orig['compaction']) && preg_match('/^\d+$/', (string)$orig['compaction'])) {
            $want = intval($orig['compaction']);
        }
        $cur = trim((string)@file_get_contents($compFile));
        if ($want !== null && (string)$want !== $cur) {
            $sysctl($want, $logs);
        }
    }
}

/** Resolve an Unraid system binary without relying on the web-server PATH. */
function zram_find_binary(string $name): string {
    if (!preg_match('/^[a-z0-9_-]+$/i', $name)) return '';

    // ZRAM_SBIN_DIRS is /sbin and /usr/sbin in production. A test sets it to folders of stub binaries.
    foreach (ZRAM_SBIN_DIRS as $dir) {
        $candidate = $dir . "/$name";
        if (is_file($candidate) && is_executable($candidate)) return $candidate;
    }

    return '';
}

/**
 * Parse the fixed column contract requested by zram_status.php.
 *
 * Do not use --output-all here: optional empty columns (notably STREAMS) are
 * collapsed by whitespace splitting and shift TOTAL onto the wrong index.
 *
 * @return array{name:string,disksize:int,data:int,compr:int,algorithm:string,total:int}|null
 */
function zram_parse_status_row(string $line): ?array {
    $parts = preg_split('/\s+/', trim($line));
    if (!is_array($parts) || count($parts) !== 6) return null;

    foreach ([1, 2, 3, 5] as $index) {
        if (preg_match('/^\d+$/D', $parts[$index]) !== 1) return null;
    }

    return [
        'name'      => $parts[0],
        'disksize'  => intval($parts[1]),
        'data'      => intval($parts[2]),
        'compr'     => intval($parts[3]),
        'algorithm' => $parts[4],
        'total'     => intval($parts[5]),
    ];
}

/**
 * Get our labeled ZRAM device name (e.g., "zram1") or empty string.
 * Checks blkid for ZRAM_CARD label, falls back to device.conf cache.
 */
function zram_get_our_device(): string {
    // Primary: probe directly, bypassing /run/blkid/blkid.tab via -c /dev/null.
    // Without it, a freshly reset zram device can keep returning matches from
    // the cache because zramctl --reset fires no udev change event — that stale
    // window is what made the REMOVE button "stick" across reloads.
    //
    // Limit the probe to actual zram devices. Without a device list, blkid scans
    // every block device on the system to find the label match -- including
    // unassigned, spun-down disks -- which wakes them just to read their headers
    // on every Dashboard page load (forums.unraid.net/topic/196763, 2026-08-30).
    $candidates = glob(ZRAM_DEV_DIR . '/zram*') ?: [];
    if ($candidates) {
        $cmd = 'blkid -c /dev/null -t LABEL=' . escapeshellarg(ZRAM_LABEL) . ' -o device '
             . implode(' ', array_map('escapeshellarg', $candidates)) . ' 2>/dev/null';
        zram_exec($cmd, $out);
        foreach ($out as $line) {
            $dev = trim($line);
            if (strpos($dev, ZRAM_DEV_DIR . '/zram') === 0) {
                return basename($dev);
            }
        }
    }
    // Fallback: cached device file (written at creation time)
    if (file_exists(ZRAM_DEVICE_FILE)) {
        $cached = trim(@file_get_contents(ZRAM_DEVICE_FILE));
        if ($cached && file_exists(ZRAM_SYS_BLOCK . "/$cached")) {
            return $cached;
        }
    }
    return '';
}

/**
 * Resolve the name the kernel lists in /proc/swaps and `swapon --show` for
 * the configured Tier 2 swap, plus a short label for the dashboard.
 * Returns ['target' => string, 'dev' => string].
 *
 * - loop: the image path is attached as /dev/loopN (losetup -j).
 * - zvol: /dev/zvol/<pool>/<dataset> is a symlink to /dev/zdN; the kernel
 *   lists only /dev/zdN (Forgejo #27, docs/specs/TIER2_ZVOL_SWAP.md).
 * - file: the kernel lists the file path itself.
 */
function zram_ssd_swap_target(string $backing, string $path): array {
    if ($backing === 'loop') {
        $ljOut = [];
        zram_exec('losetup -j ' . escapeshellarg($path) . ' 2>/dev/null', $ljOut);
        foreach ($ljOut as $ljLine) {
            if (preg_match('#^(' . preg_quote(ZRAM_DEV_DIR, '#') . '/loop\d+):#', $ljLine, $ljm)) {
                return ['target' => $ljm[1], 'dev' => basename($ljm[1])];
            }
        }
    } elseif ($backing === 'zvol') {
        $real = realpath($path);
        if ($real !== false) {
            return ['target' => $real, 'dev' => basename($real)];
        }
    }
    return ['target' => $path, 'dev' => 'swap file'];
}

/**
 * Size in bytes of the Tier 2 backing: the file size for a swap file or loop
 * image, the device size for a block device (zvol). filesize() of a block
 * device is 0, so a block device reads /sys/class/block/<dev>/size (sectors).
 */
function zram_ssd_swap_backing_size(string $path): int {
    $real = realpath($path);
    if ($real === false) return 0;
    if (filetype($real) === 'block') {
        $sectors = @file_get_contents(ZRAM_SYS_CLASS_BLOCK . '/' . basename($real) . '/size');
        return $sectors === false ? 0 : intval(trim($sectors)) * 512;
    }
    return intval(@filesize($real));
}

/**
 * Get our SSD swap file path if active, or empty string.
 */
function zram_get_ssd_swap(): string {
    $cfg = zram_config_read();
    $path    = $cfg['ssd_swap_path'] ?? '';
    $backing = $cfg['ssd_swap_backing'] ?? 'file';
    if ($path && file_exists($path)) {
        $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
        if ($backing === 'loop') {
            $ljOut = [];
            zram_exec("losetup -j " . escapeshellarg($path) . " 2>/dev/null", $ljOut);
            $loopDev = '';
            foreach ($ljOut as $line) {
                if (preg_match('#^(' . preg_quote(ZRAM_DEV_DIR, '#') . '/loop\d+):#', $line, $m)) { $loopDev = $m[1]; break; }
            }
            if ($loopDev !== '' && preg_match('/^' . preg_quote($loopDev, '/') . '\s/m', $swaps) === 1) {
                return $path;
            }
        } elseif ($backing === 'zvol') {
            // /dev/zvol/<pool>/<dataset> is a symlink; /proc/swaps records the
            // resolved /dev/zdN alias, not the symlink path. See docs/specs/TIER2_ZVOL_SWAP.md.
            $zvolDev = realpath($path) ?: '';
            if ($zvolDev !== '' && preg_match('/^' . preg_quote($zvolDev, '/') . '\s/m', $swaps) === 1) {
                return $path;
            }
        } else {
            if (preg_match('/^' . preg_quote($path, '/') . '\s/m', $swaps) === 1) {
                return $path;
            }
        }
    }
    return '';
}

/**
 * Re-activate the Tier 2 disk swap file if it's configured-enabled, present on
 * disk, but not currently in /proc/swaps. Returns true iff it took action
 * (success or attempted). $nextTry is an in/out epoch-seconds back-off gate:
 * set to now+60 after a swapon failure, reset to 0 on success.
 *
 * Mirrors zram_init.sh's activate_disk_swap(). The only caller is
 * zram_activate_disk_swap_at_array_start(), run by event/disks_mounted.
 * There is no background re-activation (Forgejo #30): outside boot and array
 * start, a person controls the swap with the ACTIVATE and REMOVE buttons, and
 * a manual swapoff stays off. See docs/specs/TIER2_RECOVERY.md.
 *
 * @param array $cachedCfg The config to pre-check (re-read fresh before acting).
 * @param int   $nextTry   In/out: epoch seconds before which we won't retry.
 */
function zram_reactivate_disk_swap_if_needed(array $cachedCfg, int &$nextTry): bool {
    // Cheap pre-checks against the given config — no flash I/O, no fork.
    if (($cachedCfg['ssd_swap_enabled'] ?? 'no') !== 'yes') return false;
    $path = $cachedCfg['ssd_swap_path'] ?? '';
    if ($path === '' || !file_exists($path)) return false;

    $backing = $cachedCfg['ssd_swap_backing'] ?? 'file';

    // For loop-backed swap, /proc/swaps lists /dev/loopN — not the image path.
    if ($backing === 'loop') {
        zram_exec("losetup -j " . escapeshellarg($path) . " 2>/dev/null", $ljOut);
        $loopDev = '';
        foreach ($ljOut as $line) {
            if (preg_match('#^(' . preg_quote(ZRAM_DEV_DIR, '#') . '/loop\d+):#', $line, $m)) { $loopDev = $m[1]; break; }
        }
        if ($loopDev !== '') {
            $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
            if (preg_match('/^' . preg_quote($loopDev, '/') . '\s/m', $swaps) === 1) return false; // already active
        }
    } elseif ($backing === 'zvol') {
        // For zvol-backed swap, /proc/swaps lists the resolved /dev/zdN alias,
        // not the /dev/zvol/<pool>/<dataset> symlink path. See TIER2_ZVOL_SWAP.md.
        $zvolDev = realpath($path) ?: '';
        if ($zvolDev !== '') {
            $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
            if (preg_match('/^' . preg_quote($zvolDev, '/') . '\s/m', $swaps) === 1) return false; // already active
        }
    } else {
        $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
        // Match the whole name column: /swap.img.old must not count as /swap.img.
        if (preg_match('/^' . preg_quote($path, '/') . '\s/m', $swaps) === 1) return false; // already active
    }

    $now = time();
    if ($now < $nextTry) return false;                         // backing off after recent failure

    // Re-read config fresh before acting (stale cache could undo a user REMOVE).
    $fresh = @parse_ini_file(ZRAM_CONFIG_FILE) ?: [];
    if (($fresh['ssd_swap_enabled'] ?? 'no') !== 'yes') return false;
    $path    = $fresh['ssd_swap_path'] ?? $path;
    $backing = $fresh['ssd_swap_backing'] ?? 'file';
    if ($path === '' || !file_exists($path)) return false;

    $logs = [];
    $prio = max(0, min(32767, intval($fresh['ssd_swap_priority'] ?? 10)));

    if ($backing === 'loop') {
        // Resolve or attach loop device
        $ljOut2 = [];
        zram_exec("losetup -j " . escapeshellarg($path) . " 2>/dev/null", $ljOut2);
        $loopDev = '';
        foreach ($ljOut2 as $line) {
            if (preg_match('#^(' . preg_quote(ZRAM_DEV_DIR, '#') . '/loop\d+):#', $line, $m)) { $loopDev = $m[1]; break; }
        }
        if ($loopDev === '') {
            zram_exec('modprobe loop 2>/dev/null');
            $lfOut = [];
            zram_exec("losetup -f --show " . escapeshellarg($path) . " 2>/dev/null", $lfOut);
            $loopDev = trim(end($lfOut) ?: '');
        }
        if (!preg_match('#^' . preg_quote(ZRAM_DEV_DIR, '#') . '/loop\d+$#', $loopDev)) {
            zram_log("Tier 2 array start: losetup failed for $path — backing off 60s", 'WARN');
            $nextTry = $now + 60;
            return true;
        }
        // Check again in case it got activated between the pre-check and now
        $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
        if (preg_match('/^' . preg_quote($loopDev, '/') . '\s/m', $swaps) === 1) return false;

        if (zram_run('swapon ' . escapeshellarg($loopDev) . ' -p ' . $prio, $logs) === 0) {
            zram_log("Tier 2 array start: re-activated $path via $loopDev (priority $prio) after it was found inactive", 'INFO');
            zram_cmd_log("Auto-reactivated disk swap file $path", 'cmd');
            $nextTry = 0;
        } else {
            zram_log("Tier 2 array start: swapon $loopDev failed — backing off 60s", 'WARN');
            $nextTry = $now + 60;
        }
        return true;
    }

    if ($backing === 'zvol') {
        // No attach step (unlike loop) — the zvol block device exists for as
        // long as the ZFS dataset does. Just resolve the stable symlink.
        $zvolDev = realpath($path) ?: '';
        if (!preg_match('#^' . preg_quote(ZRAM_DEV_DIR, '#') . '/zd\d+$#', $zvolDev)) {
            zram_log("Tier 2 array start: zvol device not found for $path — backing off 60s", 'WARN');
            $nextTry = $now + 60;
            return true;
        }
        // Check again in case it got activated between the pre-check and now
        $swaps = @file_get_contents(ZRAM_PROC_SWAPS) ?: '';
        if (preg_match('/^' . preg_quote($zvolDev, '/') . '\s/m', $swaps) === 1) return false;

        if (zram_run('swapon ' . escapeshellarg($path) . ' -p ' . $prio, $logs) === 0) {
            zram_log("Tier 2 array start: re-activated $path via $zvolDev (priority $prio) after it was found inactive", 'INFO');
            zram_cmd_log("Auto-reactivated disk swap file $path", 'cmd');
            $nextTry = 0;
        } else {
            zram_log("Tier 2 array start: swapon $path failed — backing off 60s", 'WARN');
            $nextTry = $now + 60;
        }
        return true;
    }

    // --- Direct-file path ---
    // Normalise the label (idempotent, best-effort) then bring the swap online.
    zram_run('swaplabel -L ' . escapeshellarg(ZRAM_SSD_LABEL) . ' ' . escapeshellarg($path), $logs);
    if (zram_run('swapon ' . escapeshellarg($path) . ' -p ' . $prio, $logs) === 0) {
        zram_log("Tier 2 array start: re-activated $path (priority $prio) after it was found inactive", 'INFO');
        zram_cmd_log("Auto-reactivated disk swap file $path", 'cmd');
        $nextTry = 0;
    } else {
        zram_log("Tier 2 array start: swapon $path failed — backing off 60s", 'WARN');
        $nextTry = $now + 60;
    }
    return true;
}

/**
 * True when the Tier 2 swap at $path is in /proc/swaps. A loop image is listed
 * as /dev/loopN and a zvol as /dev/zdN, so resolve the target first.
 */
function zram_ssd_swap_is_active(string $backing, string $path, string $swapsFile = ZRAM_PROC_SWAPS): bool {
    $target = zram_ssd_swap_target($backing, $path)['target'];
    $swaps = @file_get_contents($swapsFile) ?: '';
    return preg_match('/^' . preg_quote($target, '/') . '\s/m', $swaps) === 1;
}

/**
 * Turn the Tier 2 disk swap on after an array start. event/disks_mounted runs
 * this in the background. The disks are mounted, but a zvol's /dev/zvol link
 * can appear some seconds later (udev), so wait up to $waitSeconds for the
 * configured path, then try once. Returns true when the swap is on at the end.
 * See docs/specs/TIER2_RECOVERY.md.
 */
function zram_activate_disk_swap_at_array_start(int $waitSeconds = 60, ?callable $sleep = null): bool {
    $sleep = $sleep ?? 'sleep';
    $cfg = @parse_ini_file(ZRAM_CONFIG_FILE) ?: [];
    if (($cfg['ssd_swap_enabled'] ?? 'no') !== 'yes') return false;
    $path = $cfg['ssd_swap_path'] ?? '';
    if ($path === '') return false;
    $backing = $cfg['ssd_swap_backing'] ?? 'file';

    for ($waited = 0; !file_exists($path) && $waited < $waitSeconds; $waited += 2) {
        $sleep(2);
        clearstatcache();
    }
    if (!file_exists($path)) {
        zram_log("Tier 2 array start: $path not found after {$waitSeconds}s — use ACTIVATE when it is available", 'WARN');
        return false;
    }

    $nextTry = 0;
    zram_reactivate_disk_swap_if_needed($cfg, $nextTry);
    $active = zram_ssd_swap_is_active($backing, $path);
    if (!$active) {
        zram_log("Tier 2 array start: swap on $path is not active — use ACTIVATE", 'WARN');
    }
    return $active;
}

/** Check if evacuation is safe before swapoff. */
function zram_evacuation_safe(string $target, array &$logs): array {
    $zram_data = 0;
    zram_exec("zramctl --bytes --noheadings --raw --output NAME,DATA 2>/dev/null", $z_out);
    foreach ($z_out as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) < 2) continue;
        $name = basename($p[0]);
        if (empty($target) || $name === basename($target) || "/dev/$name" === $target) {
            $zram_data += intval($p[1]);
        }
    }
    $mem = @file_get_contents(ZRAM_PROC_MEMINFO) ?: '';
    preg_match('/MemAvailable:\s+(\d+)/', $mem, $m);
    $avail = intval($m[1] ?? 0) * 1024;

    $other_swap = 0;
    zram_exec("swapon --bytes --noheadings --show=NAME,SIZE,USED 2>/dev/null", $s_out);
    foreach ($s_out as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) >= 3 && strpos($p[0], 'zram') === false) {
            $other_swap += intval($p[1]) - intval($p[2]);
        }
    }

    $capacity = $avail + $other_swap;
    $buffer = 104857600; // 100MB
    $logs[] = "Safety: ZRAM data=" . round($zram_data/1048576) . "MB, capacity=" . round($capacity/1048576) . "MB";

    if ($zram_data > ($capacity - $buffer)) {
        return ['safe' => false, 'error' => "Not enough memory to safely remove swap. " .
            round($zram_data/1048576) . "MB in swap, only " . round($avail/1048576) . "MB available RAM."];
    }
    return ['safe' => true];
}
