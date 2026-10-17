<?php
/**
 * <module_context>
 *   <name>zram_drives</name>
 *   <description>Drive discovery API for Tier 2 SSD swap file placement</description>
 *   <dependencies>zram_config</dependencies>
 *   <consumers>UnraidZramCard.page (AJAX)</consumers>
 * </module_context>
 */


require_once dirname(__FILE__) . '/zram_config.php';

/**
 * Handle one request. $in is the query array (csrf_token). $serverCsrf is the
 * token that emhttp holds. Returns the HTTP status and the JSON body.
 *
 * @param array<string,mixed> $in
 * @return array{status:int,body:array<string,mixed>}
 */
function zram_drives_handle(array $in, string $serverCsrf): array {
    $csrf = (string)($in['csrf_token'] ?? '');
    if ($serverCsrf === '' || !hash_equals($serverCsrf, $csrf)) {
        return ['status' => 403, 'body' => ['error' => 'Missing CSRF token']];
    }

    $drives = [];
    $exclude_fs = ['tmpfs','proc','sysfs','devtmpfs','overlay','squashfs','fuse','devpts',
                   'cgroup','cgroup2','securityfs','pstore','bpf','debugfs','tracefs',
                   'hugetlbfs','mqueue','configfs','fusectl','autofs','nsfs','ramfs'];

    // Parse /proc/mounts for real filesystems
    $mounts = @file(ZRAM_PROC_MOUNTS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $cfg      = zram_config_read();
    $allowZfs = (($cfg['ssd_swap_allow_zfs'] ?? 'no') === 'yes');
    foreach ($mounts as $line) {
        $p = preg_split('/\s+/', $line);
        if (count($p) < 3) continue;
        [$dev, $mount, $fstype] = $p;

        if (in_array($fstype, $exclude_fs)) continue;
        if ($mount === '/' || $mount === '/boot') continue;
        // Skip Unraid array mounts (parity, data disks managed by mdcmd)
        if (preg_match('#^/mnt/disk\d+$#', $mount)) continue;
        // Skip /mnt/user (FUSE share aggregator) and /mnt/user0
        if (strpos($mount, '/mnt/user') === 0) continue;
        // Skip /mnt/remotes (network share parents) and /mnt/addons /mnt/rootshare
        if (strpos($mount, '/mnt/remotes') === 0 && $mount !== '/mnt/remotes') continue;
        if ($mount === '/mnt/addons' || $mount === '/mnt/rootshare') continue;
        // Skip system/service mounts — not suitable for swap files
        if (strpos($mount, '/var/lib/docker') === 0) continue;  // Docker storage
        if (strpos($mount, '/etc/libvirt') === 0) continue;      // VM config
        if (strpos($mount, '/tmp/') === 0) continue;              // RAM-backed tmpfs, zram upper dirs
        // Skip nested mounts under another candidate (e.g. /mnt/cache/system/...)
        if (preg_match('#^/mnt/[^/]+/#', $mount)) continue;
        // Skip zram-backed mounts (putting swap on zram defeats the purpose)
        if (strpos($dev, '/dev/zram') === 0) continue;
        // Only allow top-level pools/UD mounts under /mnt/
        if (strpos($mount, '/mnt/') !== 0) continue;

        // ZFS reports the dataset/pool name (not /dev/...) as the device. Allow
        // these through so they show up in the picker — but they get classified
        // as 'blocked' below because the kernel rejects swap files on ZFS.
        $isZfs = ($fstype === 'zfs');
        if (!$isZfs && strpos($dev, '/dev/') !== 0) continue;

        // Resolve to base block device (strip partition number for sysfs lookup).
        // ZFS pools have no single block device — sysfs lookups skipped.
        $base = '';
        if (!$isZfs) {
            // A partition (sda1, nvme0n1p1) maps to its disk. A whole disk (nvme0n1)
            // is already a sysfs block device: keep its name, or its digit would be cut off.
            $devName = basename(realpath(ZRAM_DEV_DIR . substr($dev, 4)) ?: '');
            $base = ($devName !== '' && file_exists(ZRAM_SYS_BLOCK . "/$devName"))
                ? $devName
                : preg_replace('/p?\d+$/', '', $devName);
            if (empty($base)) continue;
        }

        $rotational = '0';
        $removable = '0';
        $model = 'Unknown';
        $transport = '';

        if ($isZfs) {
            // ZFS pool — pool name is the "device", filesystem type identifies it
            $model = $dev;
            $transport = 'zfs';
        } else {
            // NVMe: /sys/block/nvme0n1/...
            // SATA/SAS: /sys/block/sda/...
            if (file_exists(ZRAM_SYS_BLOCK . "/$base/queue/rotational")) {
                $rotational = trim(@file_get_contents(ZRAM_SYS_BLOCK . "/$base/queue/rotational"));
            }
            if (file_exists(ZRAM_SYS_BLOCK . "/$base/removable")) {
                $removable = trim(@file_get_contents(ZRAM_SYS_BLOCK . "/$base/removable"));
            }
            if (file_exists(ZRAM_SYS_BLOCK . "/$base/device/model")) {
                $model = trim(@file_get_contents(ZRAM_SYS_BLOCK . "/$base/device/model"));
            } elseif (file_exists(ZRAM_SYS_BLOCK . "/$base/device/subsystem_device")) {
                $model = $base;
            }

            // Detect transport type
            if (strpos($base, 'nvme') === 0) {
                $transport = 'nvme';
            } elseif ($removable === '1') {
                $transport = 'usb';
            } elseif ($rotational === '1') {
                $transport = 'hdd';
            } else {
                $transport = 'ssd';
            }
        }

        // Hide USB entirely (boot drive, flash sticks)
        if ($transport === 'usb') continue;

        // Get free space
        $free = @disk_free_space($mount);
        $total = @disk_total_space($mount);

        // Check btrfs RAID (swap files not supported on multi-device btrfs)
        $btrfsRaid = false;
        if ($fstype === 'btrfs') {
            // Start empty on each mount: zram_exec() appends to $devCount, so a
            // value left from the previous btrfs mount would be read as this one.
            $devCount = [];
            zram_exec("btrfs filesystem show " . escapeshellarg($mount) . " 2>/dev/null | grep -c 'devid'", $devCount);
            if (intval($devCount[0] ?? 0) > 1) $btrfsRaid = true;
        }

        // Resolve backing mode: 'file' for filesystems that accept direct swap
        // files; 'loop' for those that do not (loop devices interpose a block
        // device between the image and the swap subsystem, dodging all FS
        // restrictions — see docs/specs/TIER2_LOOP_DEVICE_SWAP.md); 'zvol' for
        // ZFS specifically, which is already a real block device and so is
        // strictly better than loop for ZFS (no CoW/deadlock caveat) — see
        // docs/specs/TIER2_ZVOL_SWAP.md.
        $backing  = 'file';
        if ($btrfsRaid) {
            $backing = 'loop';
        } elseif ($isZfs && $allowZfs) {
            $backing = 'zvol';
        }
        // User can force a specific backing for any FS via ssd_swap_backing in
        // config. This is an expert override; for most users the auto-detection
        // above is correct.
        $explicitBacking = $cfg['ssd_swap_backing'] ?? 'auto';
        if ($explicitBacking === 'loop') {
            $backing = 'loop';
        } elseif ($explicitBacking === 'zvol') {
            $backing = 'zvol';
        }

        // classify: recommended | ok | warn | blocked | loop | zvol
        //   blocked = kernel will reject swap-file creation; row shown but not clickable
        //   loop    = kernel restriction bypassed via loop device; clickable with note
        //   zvol    = ZFS-only: swap via a dedicated ZFS volume; clickable with note
        //   warn    = allowed but slow / user should know
        $classify  = 'ok';
        $clickable = true;
        $warning   = '';

        if ($isZfs && !$allowZfs) {
            $classify  = 'blocked';
            $clickable = false;
            $warning   = 'ZFS — kernel does not support swap files on ZFS datasets';
        } elseif ($isZfs) {
            // Reached only when allow_zfs=yes (the !$allowZfs case is handled above).
            // Prefer a ZFS volume (zvol) over a loop-backed image: a zvol is
            // already a real block device (no CoW, no NOCOW/fallocate dance, no
            // documented ZFS-loop deadlock caveat). See TIER2_ZVOL_SWAP.md.
            $classify  = 'zvol';
            $clickable = true;
            $warning   = 'ZFS — swap via a dedicated volume (zvol), no loop device needed';
        } elseif ($btrfsRaid) {
            $classify  = 'loop';
            $clickable = true;
            $warning   = 'Btrfs multi-device pool — swap via loop device (NOCOW image)';
        } elseif ($transport === 'nvme') {
            $classify  = 'recommended';
        } elseif ($transport === 'hdd') {
            $classify  = 'warn';
            $warning   = 'HDD random-IO is slow; expect significant performance impact, but still better than OOM if no faster disk is available';
        }

        // If loop backing is in effect but the FS-based classification didn't already
        // mark it loop (e.g. force-loop override on XFS/NVMe), reflect loop in the UI so
        // the user sees a "via loop device" note. Never override a 'blocked' row — ZFS
        // without the allow_zfs opt-in stays blocked regardless of a forced backing.
        if ($backing === 'loop' && $classify !== 'loop' && $classify !== 'blocked') {
            $classify  = 'loop';
            $clickable = true;
            $warning   = ($warning === '') ? 'Forced loop backing — swap via loop device' : $warning;
        } elseif ($backing === 'zvol' && $classify !== 'zvol' && $classify !== 'blocked') {
            // Force-zvol override — only meaningful on a ZFS mount (zvol creation
            // requires a ZFS dataset), but reflect it in the UI the same way.
            $classify  = 'zvol';
            $clickable = true;
            $warning   = ($warning === '') ? 'Forced zvol backing — swap via a ZFS volume (zvol)' : $warning;
        }

        $drives[] = [
            'device'     => $dev,
            'mount'      => $mount,
            'fstype'     => $fstype,
            'model'      => $model,
            'transport'  => $transport,
            'classify'   => $classify,
            'clickable'  => $clickable,
            'warning'    => $warning,
            'backing'    => $backing,
            'free_bytes' => intval($free ?: 0),
            'total_bytes'=> intval($total ?: 0),
        ];
    }

    // Sort: recommended → ok → zvol → loop → warn → blocked
    usort($drives, function($a, $b) {
        $order = ['recommended' => 0, 'ok' => 1, 'zvol' => 2, 'loop' => 3, 'warn' => 4, 'blocked' => 5];
        return ($order[$a['classify']] ?? 9) - ($order[$b['classify']] ?? 9);
    });

    return ['status' => 200, 'body' => ['drives' => $drives]];
}

/**
 * Web entry: send the drive list as JSON. $get is the query array ($_GET).
 *
 * @param array<string,mixed> $get
 */
function zram_drives_main(array $get): void {
    header('Content-Type: application/json');
    $_var = @parse_ini_file(ZRAM_VAR_INI, false, INI_SCANNER_RAW) ?: [];
    $csrf = $get['csrf_token'] ?? '';
    $_r = zram_drives_handle(['csrf_token' => is_string($csrf) && $csrf !== '0' ? $csrf : ''], (string)($_var['csrf_token'] ?? ''));
    http_response_code($_r['status']);
    echo json_encode($_r['body']);
}

if (!defined('ZRAM_DRIVES_LIBRARY_ONLY')) {
    zram_drives_main($_GET);
}
