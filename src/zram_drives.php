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
header('Content-Type: application/json');

$csrf        = filter_input(INPUT_GET, 'csrf_token', FILTER_UNSAFE_RAW) ?: '';
$_var        = @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW) ?: [];
$_serverCsrf = (string)($_var['csrf_token'] ?? '');
if ($_serverCsrf === '' || !hash_equals($_serverCsrf, $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Missing CSRF token']);
    exit;
}

$drives = [];
$exclude_fs = ['tmpfs','proc','sysfs','devtmpfs','overlay','squashfs','fuse','devpts',
               'cgroup','cgroup2','securityfs','pstore','bpf','debugfs','tracefs',
               'hugetlbfs','mqueue','configfs','fusectl','autofs','nsfs','ramfs'];

// Parse /proc/mounts for real filesystems
$mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
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
        $base = preg_replace('/p?\d+$/', '', basename(realpath($dev) ?: ''));
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
        if (file_exists("/sys/block/$base/queue/rotational")) {
            $rotational = trim(@file_get_contents("/sys/block/$base/queue/rotational"));
        }
        if (file_exists("/sys/block/$base/removable")) {
            $removable = trim(@file_get_contents("/sys/block/$base/removable"));
        }
        if (file_exists("/sys/block/$base/device/model")) {
            $model = trim(@file_get_contents("/sys/block/$base/device/model"));
        } elseif (file_exists("/sys/block/$base/device/subsystem_device")) {
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
        exec("btrfs filesystem show " . escapeshellarg($mount) . " 2>/dev/null | grep -c 'devid'", $devCount);
        if (intval($devCount[0] ?? 0) > 1) $btrfsRaid = true;
    }

    // Resolve backing mode: 'file' for filesystems that accept direct swap
    // files; 'loop' for those that do not. Loop devices interpose a block
    // device between the image and the swap subsystem, dodging all FS
    // restrictions. See docs/specs/TIER2_LOOP_DEVICE_SWAP.md.
    $backing  = 'file';
    if ($btrfsRaid) {
        $backing = 'loop';
    } elseif ($isZfs && $allowZfs) {
        $backing = 'loop';
    }
    // User can force loop backing for any FS by setting ssd_swap_backing=loop in config.
    // This is an expert override; for most users the auto-detection above is correct.
    if (($cfg['ssd_swap_backing'] ?? 'auto') === 'loop') {
        $backing = 'loop';
    }

    // classify: recommended | ok | warn | blocked | loop
    //   blocked = kernel will reject swap-file creation; row shown but not clickable
    //   loop    = kernel restriction bypassed via loop device; clickable with note
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
        $classify  = 'loop';
        $clickable = true;
        $warning   = 'ZFS — swap via loop device (opt-in). See caveat in plugin docs.';
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

// Sort: recommended → ok → loop → warn → blocked
usort($drives, function($a, $b) {
    $order = ['recommended' => 0, 'ok' => 1, 'loop' => 2, 'warn' => 3, 'blocked' => 4];
    return ($order[$a['classify']] ?? 9) - ($order[$b['classify']] ?? 9);
});

echo json_encode(['drives' => $drives]);
