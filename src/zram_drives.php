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

$csrf = filter_input(INPUT_GET, 'csrf_token', FILTER_UNSAFE_RAW);
if (empty($csrf)) {
    echo json_encode(['error' => 'Missing CSRF token']);
    exit;
}

$drives = [];
$exclude_fs = ['tmpfs','proc','sysfs','devtmpfs','overlay','squashfs','fuse','devpts',
               'cgroup','cgroup2','securityfs','pstore','bpf','debugfs','tracefs',
               'hugetlbfs','mqueue','configfs','fusectl','autofs','nsfs','ramfs'];

// Parse /proc/mounts for real filesystems
$mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
foreach ($mounts as $line) {
    $p = preg_split('/\s+/', $line);
    if (count($p) < 3) continue;
    [$dev, $mount, $fstype] = $p;

    if (in_array($fstype, $exclude_fs)) continue;
    if (strpos($dev, '/dev/') !== 0) continue;
    if ($mount === '/' || $mount === '/boot') continue;
    // Skip Unraid array mounts (parity, data disks managed by mdcmd)
    if (preg_match('#^/mnt/disk\d+$#', $mount)) continue;
    // Skip /mnt/user (FUSE share aggregator)
    if (strpos($mount, '/mnt/user') === 0) continue;
    // Skip system/service mounts — not suitable for swap files
    if (strpos($mount, '/var/lib/docker') === 0) continue;  // Docker storage
    if (strpos($mount, '/etc/libvirt') === 0) continue;      // VM config
    if (strpos($mount, '/tmp/') === 0) continue;              // RAM-backed tmpfs, zram upper dirs
    // Skip zram-backed mounts (putting swap on zram defeats the purpose)
    if (strpos($dev, '/dev/zram') === 0) continue;
    // Only allow mounts under /mnt/disks/ (Unassigned Devices) or /mnt/cache
    if (strpos($mount, '/mnt/') !== 0) continue;

    // Resolve to base block device (strip partition number for sysfs lookup)
    $base = preg_replace('/p?\d+$/', '', basename(realpath($dev)));
    if (empty($base)) continue;

    $rotational = '0';
    $removable = '0';
    $model = 'Unknown';
    $transport = '';

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

    $classify = 'ok';
    $warning = '';
    if ($btrfsRaid) {
        $classify = 'warn';
        $warning = 'Btrfs RAID pool — swap files not supported by kernel on multi-device btrfs';
    } elseif ($transport === 'nvme') {
        $classify = 'recommended';
    } elseif ($transport === 'hdd') {
        $classify = 'warn';
        $warning = 'HDD swap causes severe performance degradation and may interfere with parity operations';
    }

    $drives[] = [
        'device'     => $dev,
        'mount'      => $mount,
        'fstype'     => $fstype,
        'model'      => $model,
        'transport'  => $transport,
        'classify'   => $classify,
        'warning'    => $warning,
        'free_bytes' => intval($free ?: 0),
        'total_bytes'=> intval($total ?: 0),
    ];
}

// Sort: recommended first, then ok, then warn
usort($drives, function($a, $b) {
    $order = ['recommended' => 0, 'ok' => 1, 'warn' => 2];
    return ($order[$a['classify']] ?? 9) - ($order[$b['classify']] ?? 9);
});

echo json_encode(['drives' => $drives]);
