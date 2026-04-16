<?php
/**
 * <module_context>
 *   <name>zram_actions</name>
 *   <description>Action handlers for ZRAM device and SSD swap management</description>
 *   <dependencies>zram_config</dependencies>
 *   <consumers>UnraidZramCard.page (AJAX)</consumers>
 * </module_context>
 */

require_once dirname(__FILE__) . '/zram_config.php';

$action = filter_input(INPUT_GET, 'action', FILTER_UNSAFE_RAW) ?: '';
$csrf   = filter_input(INPUT_GET, 'csrf_token', FILTER_UNSAFE_RAW) ?: '';

// Non-mutating actions: view logs (no CSRF required)
if ($action === 'view_log') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache');
    if (file_exists(ZRAM_DEBUG_LOG) && is_readable(ZRAM_DEBUG_LOG)) {
        readfile(ZRAM_DEBUG_LOG);
    } else {
        echo "Debug log not found or not readable.\n";
    }
    exit;
}

if ($action === 'view_cmd_log') {
    header('Content-Type: application/json');
    $entries = [];
    if (file_exists(ZRAM_CMD_LOG)) {
        $lines = file(ZRAM_CMD_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $e = json_decode($line, true);
            if ($e) $entries[] = $e;
        }
    }
    echo json_encode($entries);
    exit;
}

// All mutating actions require CSRF
header('Content-Type: application/json');
if (empty($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Missing CSRF token']);
    exit;
}

$logs = [];

// --- ZRAM DEVICE ACTIONS ---

if ($action === 'create_zram') {
    $cfg = zram_config_read();
    $size = $cfg['zram_size'];
    $algo = filter_input(INPUT_GET, 'algo', FILTER_UNSAFE_RAW) ?: $cfg['zram_algo'];

    // Auto-size calculation
    if ($size === 'auto') {
        $memKb = 0;
        $meminfo = @file_get_contents('/proc/meminfo') ?: '';
        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) $memKb = intval($m[1]);
        $pct = max(25, min(75, intval($cfg['zram_percent'])));
        $sizeBytes = intval(($memKb * 1024) * ($pct / 100));
        $size = intval($sizeBytes / 1048576) . 'M';
    }

    // Check if we already have a device
    $existing = zram_get_our_device();
    if ($existing) {
        echo json_encode(['success' => false, 'message' => "ZRAM device already active: /dev/$existing", 'logs' => $logs]);
        exit;
    }

    zram_run('modprobe zram', $logs);
    $cmd = "zramctl --find --size " . escapeshellarg($size) . " --algorithm " . escapeshellarg($algo);
    exec($cmd . " 2>&1", $find_out, $ret);
    $logs[] = ['cmd' => $cmd, 'output' => implode(" ", $find_out), 'status' => $ret];

    if ($ret !== 0 || empty($find_out)) {
        echo json_encode(['success' => false, 'message' => 'Failed to allocate ZRAM device', 'logs' => $logs]);
        exit;
    }

    $dev = trim(end($find_out));
    $devName = basename($dev);

    // Label with mkswap -L
    if (zram_run("mkswap -L " . escapeshellarg(ZRAM_LABEL) . " " . escapeshellarg($dev), $logs) !== 0) {
        echo json_encode(['success' => false, 'message' => 'mkswap failed', 'logs' => $logs]);
        exit;
    }

    if (zram_run("swapon " . escapeshellarg($dev) . " -p 100", $logs) !== 0) {
        echo json_encode(['success' => false, 'message' => 'swapon failed', 'logs' => $logs]);
        exit;
    }

    // Cache device name for collector
    @file_put_contents(ZRAM_DEVICE_FILE, $devName);
    zram_config_write(['zram_algo' => $algo, 'zram_size' => $cfg['zram_size']]);
    echo json_encode(['success' => true, 'message' => "Created $dev ($size, $algo)", 'logs' => $logs]);
    exit;
}

if ($action === 'remove_zram') {
    $ourDev = zram_get_our_device();
    if (empty($ourDev)) {
        echo json_encode(['success' => false, 'message' => 'No ZRAM Card device found', 'logs' => $logs]);
        exit;
    }
    $devPath = "/dev/$ourDev";

    $safety = zram_evacuation_safe($devPath, $logs);
    if (!$safety['safe']) {
        echo json_encode(['success' => false, 'message' => $safety['error'], 'logs' => $logs]);
        exit;
    }

    zram_run("swapoff " . escapeshellarg($devPath), $logs);
    zram_run("zramctl --reset " . escapeshellarg($devPath), $logs);
    @unlink(ZRAM_DEVICE_FILE);
    echo json_encode(['success' => true, 'message' => "Removed $devPath", 'logs' => $logs]);
    exit;
}

// --- SSD SWAP FILE ACTIONS ---

if ($action === 'create_ssd_swap') {
    $mount = filter_input(INPUT_GET, 'mount', FILTER_UNSAFE_RAW) ?: '';
    $sizeStr = filter_input(INPUT_GET, 'size', FILTER_UNSAFE_RAW) ?: '16G';

    // Validate mount point exists and is mounted
    if (empty($mount) || !is_dir($mount)) {
        echo json_encode(['success' => false, 'message' => 'Invalid mount point', 'logs' => $logs]);
        exit;
    }

    // Parse size to MB
    $sizeMB = 0;
    if (preg_match('/^(\d+)\s*(G|M|T)$/i', $sizeStr, $sm)) {
        $num = intval($sm[1]);
        $unit = strtoupper($sm[2]);
        if ($unit === 'G') $sizeMB = $num * 1024;
        elseif ($unit === 'T') $sizeMB = $num * 1024 * 1024;
        else $sizeMB = $num;
    }
    if ($sizeMB < 256) {
        echo json_encode(['success' => false, 'message' => 'Minimum swap file size is 256M', 'logs' => $logs]);
        exit;
    }

    // Check free space (need size + 100MB headroom)
    $freeBytes = @disk_free_space($mount) ?: 0;
    $needBytes = $sizeMB * 1048576;
    if ($freeBytes < ($needBytes + 104857600)) {
        echo json_encode(['success' => false, 'message' => 'Insufficient free space on ' . $mount, 'logs' => $logs]);
        exit;
    }

    $swapDir = rtrim($mount, '/') . '/.swap';
    $swapFile = "$swapDir/zram-card.swap";

    if (!is_dir($swapDir)) @mkdir($swapDir, 0700, true);

    // Detect btrfs — swap files need NOCOW attribute
    $fsType = trim(exec("stat -f -c %T " . escapeshellarg($mount) . " 2>/dev/null"));
    $isBtrfs = ($fsType === 'btrfs');

    // Allocate swap file
    zram_cmd_log("Creating {$sizeStr} swap file on $mount" . ($isBtrfs ? " (btrfs NOCOW)" : "") . "...", 'cmd');

    if ($isBtrfs) {
        // btrfs requires: create empty file, set NOCOW, then fill
        zram_run("truncate -s 0 " . escapeshellarg($swapFile), $logs);
        zram_run("chattr +C " . escapeshellarg($swapFile), $logs);
    }

    $ddCmd = "dd if=/dev/zero of=" . escapeshellarg($swapFile) . " bs=1M count=$sizeMB status=none";
    if (zram_run($ddCmd, $logs) !== 0) {
        @unlink($swapFile);
        echo json_encode(['success' => false, 'message' => 'Failed to create swap file', 'logs' => $logs]);
        exit;
    }
    @chmod($swapFile, 0600);

    if (zram_run("mkswap -L " . escapeshellarg(ZRAM_SSD_LABEL) . " " . escapeshellarg($swapFile), $logs) !== 0) {
        @unlink($swapFile);
        echo json_encode(['success' => false, 'message' => 'mkswap failed', 'logs' => $logs]);
        exit;
    }

    if (zram_run("swapon " . escapeshellarg($swapFile) . " -p 10", $logs) !== 0) {
        @unlink($swapFile);
        @rmdir(dirname($swapFile)); // Clean up empty .swap dir
        $hint = $isBtrfs ? ' (btrfs RAID or compressed mount may not support swap files)' : '';
        echo json_encode(['success' => false, 'message' => 'swapon failed' . $hint, 'logs' => $logs]);
        exit;
    }

    zram_config_write([
        'ssd_swap_enabled' => 'yes',
        'ssd_swap_path'    => $swapFile,
        'ssd_swap_size'    => $sizeStr,
        'ssd_swap_mount'   => $mount,
    ]);

    echo json_encode(['success' => true, 'message' => "Created {$sizeStr} swap file on $mount", 'logs' => $logs]);
    exit;
}

if ($action === 'remove_ssd_swap') {
    $cfg = zram_config_read();
    $swapFile = $cfg['ssd_swap_path'] ?? '';

    if (empty($swapFile) || !file_exists($swapFile)) {
        echo json_encode(['success' => false, 'message' => 'No SSD swap file found', 'logs' => $logs]);
        exit;
    }

    // Check if active and safe to remove
    $swaps = @file_get_contents('/proc/swaps') ?: '';
    if (strpos($swaps, $swapFile) !== false) {
        $safety = zram_evacuation_safe('', $logs);
        if (!$safety['safe']) {
            echo json_encode(['success' => false, 'message' => $safety['error'], 'logs' => $logs]);
            exit;
        }
        zram_run("swapoff " . escapeshellarg($swapFile), $logs);
    }

    @unlink($swapFile);
    zram_config_write([
        'ssd_swap_enabled' => 'no',
        'ssd_swap_path'    => '',
        'ssd_swap_size'    => $cfg['ssd_swap_size'],
        'ssd_swap_mount'   => '',
    ]);

    echo json_encode(['success' => true, 'message' => 'SSD swap file removed', 'logs' => $logs]);
    exit;
}

// --- SETTINGS ACTIONS ---

if ($action === 'update_swappiness') {
    $val = filter_input(INPUT_GET, 'val', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
    if ($val === false || $val === null) $val = 100;
    zram_run("sysctl vm.swappiness=" . intval($val), $logs);
    zram_config_write(['swappiness' => $val]);
    echo json_encode(['success' => true, 'message' => "Swappiness set to $val", 'logs' => $logs]);
    exit;
}

if ($action === 'update_debug') {
    $val = filter_input(INPUT_GET, 'val', FILTER_UNSAFE_RAW) === 'yes' ? 'yes' : 'no';
    zram_config_write(['debug' => $val]);
    zram_debug_reset();
    zram_log("Debug mode set to $val", 'INFO');
    echo json_encode(['success' => true, 'message' => "Debug set to $val", 'logs' => $logs]);
    exit;
}

if ($action === 'check_safety') {
    $dev = filter_input(INPUT_GET, 'device', FILTER_UNSAFE_RAW) ?: '';
    $safety = zram_evacuation_safe($dev, $logs);
    echo json_encode(['safe' => $safety['safe'], 'message' => $safety['error'] ?? '', 'logs' => $logs]);
    exit;
}

// --- LOG ACTIONS ---

if ($action === 'clear_cmd_log') {
    @file_put_contents(ZRAM_CMD_LOG, "");
    zram_cmd_log("Console cleared.");
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'append_cmd_log') {
    $msg = filter_input(INPUT_GET, 'msg', FILTER_UNSAFE_RAW) ?: '';
    $type = filter_input(INPUT_GET, 'type', FILTER_UNSAFE_RAW) ?: '';
    zram_cmd_log($msg, $type);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'clear_log') {
    @file_put_contents(ZRAM_DEBUG_LOG, "");
    zram_log("Log cleared by user.", 'INFO');
    echo json_encode(['success' => true, 'message' => 'Debug log cleared']);
    exit;
}

// Unknown action
echo json_encode(['success' => false, 'message' => "Unknown action: $action"]);
