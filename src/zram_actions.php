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

if ($action === 'view_log') {
    if (empty($csrf)) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); echo "Missing CSRF token\n"; exit; }
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
    if (empty($csrf)) { http_response_code(403); header('Content-Type: application/json'); echo json_encode([]); exit; }
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

    // Live-form parameters from the CREATE click. Without these, the action
    // would use only saved-config values, which is what produced the user
    // bug "I moved the slider to 75% but it always creates 16G — turns out
    // I had to APPLY & SAVE first". Now CREATE uses what the user sees on
    // screen and persists those values for next-boot init.
    $sizeMode = filter_input(INPUT_GET, 'size_mode', FILTER_UNSAFE_RAW);
    $sizeIn   = filter_input(INPUT_GET, 'size', FILTER_UNSAFE_RAW);
    $pctIn    = filter_input(INPUT_GET, 'percent', FILTER_VALIDATE_INT,
                             ['options' => ['min_range' => 25, 'max_range' => 75]]);
    $algoIn   = filter_input(INPUT_GET, 'algo', FILTER_UNSAFE_RAW) ?: '';

    // Validate algorithm against the kernel's live allow-list, then static
    // fallback. Anything else falls through to saved config (no error toast
    // — a malformed algo means our own dropdown lied, not that the user is
    // attacking us).
    $allowedAlgos = ['zstd', 'lz4', 'lzo', 'deflate'];
    foreach (glob('/sys/block/zram*/comp_algorithm') as $af) {
        $raw = str_replace(['[', ']'], '', @file_get_contents($af));
        $allowedAlgos = preg_split('/\s+/', trim($raw));
        break;
    }
    $algo = (in_array($algoIn, $allowedAlgos, true)) ? $algoIn : $cfg['zram_algo'];

    // Resolve size source. Custom requires a well-formed unit string; an
    // invalid custom size is rejected explicitly rather than silently
    // falling through to the previously-saved value.
    if ($sizeMode === 'custom') {
        if (!preg_match('/^\d+\s*[GMT]$/i', (string)$sizeIn)) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid custom size format (use e.g. 4G, 512M, 1T)',
                'logs'    => $logs,
            ]);
            exit;
        }
        $size = $sizeIn;
    } elseif ($sizeMode === 'auto') {
        $size = 'auto';
    } else {
        $size = $cfg['zram_size'];
    }

    $pct = ($pctIn !== false && $pctIn !== null) ? $pctIn : intval($cfg['zram_percent']);

    // Auto-size calculation — uses resolved $pct so a live slider value wins
    // over the previously-saved one.
    if ($size === 'auto') {
        $memKb = 0;
        $meminfo = @file_get_contents('/proc/meminfo') ?: '';
        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) $memKb = intval($m[1]);
        $pct = max(25, min(75, $pct));
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

    // Persist what we actually used so next-boot init.sh reproduces the same
    // device, and so the form on the next page render reflects reality. The
    // pre-fix code wrote `zram_size: $cfg['zram_size']` (the stale value)
    // and never persisted `zram_percent` at all — so a slider tweak was lost
    // on every CREATE.
    zram_config_write([
        'zram_algo'    => $algo,
        'zram_size'    => ($sizeMode === 'custom') ? $sizeIn : 'auto',
        'zram_percent' => max(25, min(75, $pct)),
    ]);
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

    // Each step must succeed. Silent failure here was the root cause of the
    // "REMOVE button persists across reloads" bug: under memory pressure
    // swapoff can fail, zramctl --reset then also fails, and we used to
    // unlink device.conf and report success regardless — leaving the kernel
    // device active while the UI lost track of it.
    if (zram_run("swapoff " . escapeshellarg($devPath), $logs) !== 0) {
        echo json_encode([
            'success' => false,
            'message' => 'swapoff failed — pages may not have evacuated. Retry once memory pressure subsides.',
            'logs' => $logs,
        ]);
        exit;
    }

    // Wipe the swap signature so blkid (and its /run/blkid/blkid.tab cache)
    // immediately stops reporting our label on this device. Without this
    // step a stale cache entry can survive `zramctl --reset` (which fires
    // no udev change event) and make the next page load think the device
    // is still active. Logged but not fatal: the cache-bypassing probe in
    // zram_get_our_device() is the second line of defence.
    zram_run("wipefs -a " . escapeshellarg($devPath), $logs);

    if (zram_run("zramctl --reset " . escapeshellarg($devPath), $logs) !== 0) {
        echo json_encode([
            'success' => false,
            'message' => 'zramctl --reset failed (device may still be allocated)',
            'logs' => $logs,
        ]);
        exit;
    }
    @unlink(ZRAM_DEVICE_FILE);
    echo json_encode(['success' => true, 'message' => "Removed $devPath", 'logs' => $logs]);
    exit;
}

// --- DISK SWAP FILE ACTIONS ---
// 'create_ssd_swap' / 'remove_ssd_swap' are accepted as legacy aliases so a
// settings tab loaded from a pre-upgrade page asset (cached JS) keeps working
// for one session post-upgrade. The cache-buster on the .page render bumps
// next time, so subsequent requests use the new identifiers.

if ($action === 'create_disk_swap' || $action === 'create_ssd_swap') {
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

if ($action === 'remove_disk_swap' || $action === 'remove_ssd_swap') {
    $cfg = zram_config_read();
    $swapFile = $cfg['ssd_swap_path'] ?? '';

    if (empty($swapFile) || !file_exists($swapFile)) {
        echo json_encode(['success' => false, 'message' => 'No disk swap file found', 'logs' => $logs]);
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

    echo json_encode(['success' => true, 'message' => 'Disk swap file removed', 'logs' => $logs]);
    exit;
}

// --- SETTINGS ACTIONS ---

// Generic per-key settings update for the auto-save UI. The whitelist gates
// what keys are writable from the page; per-key validation normalises the
// value before it lands in settings.ini; targeted side effects fire only
// when the saved key requires them, so most blurs are a single config write.
// See docs/specs/SETTINGS_AUTO_SAVE.md.
if ($action === 'update_setting') {
    $key = filter_input(INPUT_GET, 'key', FILTER_UNSAFE_RAW) ?: '';
    $rawValue = filter_input(INPUT_GET, 'value', FILTER_UNSAFE_RAW);
    if ($rawValue === null) $rawValue = '';

    $allowed = ['enabled','refresh_interval','collection_interval','swappiness',
                'debug','console_visible','zram_size','zram_percent','zram_algo'];
    if (!in_array($key, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid setting key', 'logs' => $logs]);
        exit;
    }

    // Per-key validation
    switch ($key) {
        case 'enabled':
        case 'debug':
        case 'console_visible':
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
        case 'zram_percent':
            $value = max(25, min(75, intval($rawValue)));
            break;
        case 'zram_size':
            if ($rawValue === 'auto' || preg_match('/^\d+\s*[GMT]$/i', $rawValue)) {
                $value = $rawValue === 'auto' ? 'auto' : strtoupper(preg_replace('/\s+/', '', $rawValue));
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid size — use "auto" or e.g. 16G', 'logs' => $logs]);
                exit;
            }
            break;
        case 'zram_algo':
            $algos = ['zstd', 'lz4', 'lzo', 'deflate'];
            if (!in_array($rawValue, $algos, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid algorithm', 'logs' => $logs]);
                exit;
            }
            $value = $rawValue;
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Validation missing for key', 'logs' => $logs]);
            exit;
    }

    zram_config_write([$key => $value]);

    // Targeted side effects — only fire for keys that need immediate kernel/daemon impact.
    if ($key === 'swappiness') {
        zram_run("sysctl -q vm.swappiness=" . escapeshellarg((string)$value), $logs);
    }
    if ($key === 'debug') {
        zram_debug_reset();
    }
    if ($key === 'collection_interval' || $key === 'debug') {
        $docroot = $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
        $initScript = "$docroot/plugins/unraid-zram-card/zram_init.sh";
        if (file_exists($initScript)) {
            zram_run("nohup $initScript > /dev/null 2>&1 & disown", $logs);
        }
    }

    echo json_encode(['success' => true, 'message' => "Saved $key", 'key' => $key, 'value' => $value, 'logs' => $logs]);
    exit;
}

if ($action === 'update_swappiness') {
    $val = filter_input(INPUT_GET, 'val', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 200]]);
    if ($val === false || $val === null) $val = 150;
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
