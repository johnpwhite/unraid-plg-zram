<?php
// zram_swap.php
// Backend logic with Safe Evacuation Check

// header('Content-Type: application/json'); // Moved below view_log to prevent conflicts

$action = $_REQUEST['action'] ?? '';
$size = $_REQUEST['size'] ?? '1G';
$algo = $_REQUEST['algo'] ?? 'zstd';
$device = $_REQUEST['device'] ?? '';

$configFile = "/boot/config/plugins/unraid-zram-card/settings.ini";
$logDir = "/tmp/unraid-zram-card";
if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
$debugLog = $logDir . "/debug.log";

$logs = [];

function run_cmd($cmd, &$logs, $debugLog) {
    exec($cmd . " 2>&1", $out, $ret);
    $entry = ['cmd' => $cmd, 'output' => implode(" ", $out), 'status' => $ret];
    $logs[] = $entry;
    $logMsg = date('[Y-m-d H:i:s] ') . "CMD: $cmd | Status: $ret | Output: " . $entry['output'] . "\n";
    @file_put_contents($debugLog, $logMsg, FILE_APPEND);
    @chmod($debugLog, 0666);
    return $ret;
}

function debug_log($msg, $debugLog) {
    @file_put_contents($debugLog, date('[Y-m-d H:i:s] ') . "DEBUG: $msg\n", FILE_APPEND);
    @chmod($debugLog, 0666);
}

// Check if it's safe to remove ZRAM (prevents OOM crashes)
function is_evacuation_safe($target_device, &$logs) {
    // 1. Get data currently in ZRAM (Original size, not compressed)
    // If target_device is empty, we check ALL ZRAM devices
    $zram_data_bytes = 0;
    exec("zramctl --bytes --noheadings --raw --output NAME,DATA", $z_out);
    foreach ($z_out as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (empty($target_device) || strpos($p[0], str_replace('/dev/', '', $target_device)) !== false) {
            $zram_data_bytes += intval($p[1] ?? 0);
        }
    }

    // 2. Get Available System RAM (from /proc/meminfo)
    $mem_info = file_get_contents("/proc/meminfo");
    preg_match('/MemAvailable:\s+(\d+)/', $mem_info, $matches);
    $available_ram_bytes = intval($matches[1] ?? 0) * 1024;

    // 3. Get Free space in OTHER swap areas
    $other_swap_free_bytes = 0;
    exec("swapon --bytes --noheadings --show", $s_out);
    foreach ($s_out as $line) {
        $p = preg_split('/\s+/', trim($line));
        // Only count if it's NOT a zram device
        if (strpos($p[0], 'zram') === false) {
            // Columns: NAME TYPE SIZE USED PRIO -> We need (SIZE - USED)
            $size = intval($p[2] ?? 0);
            $used = intval($p[3] ?? 0);
            $other_swap_free_bytes += ($size - $used);
        }
    }

    $total_safe_capacity = $available_ram_bytes + $other_swap_free_bytes;
    $buffer = 104857600; // 100MB safety buffer
    
    $logs[] = "Safety Check: ZRAM Data=" . round($zram_data_bytes/1024/1024) . "MB, System Capacity=" . round($total_safe_capacity/1024/1024) . "MB";

    if ($zram_data_bytes > ($total_safe_capacity - $buffer)) {
        return [
            'safe' => false, 
            'error' => "DANGER: Not enough memory to remove ZRAM. You have " . round($zram_data_bytes/1024/1024) . "MB in swap, but only " . round($available_ram_bytes/1024/1024) . "MB available RAM. System would likely crash!"
        ];
    }
    return ['safe' => true];
}

if ($action === 'view_log') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    if (file_exists($debugLog)) {
        if (is_readable($debugLog)) {
            readfile($debugLog);
        } else {
            echo "Error: Debug log exists but is not readable by the web server.\n";
            echo "Permissions: " . substr(sprintf('%o', fileperms($debugLog)), -4) . "\n";
        }
    } else {
        echo "Debug log not found at $debugLog\n";
    }
    exit;
}

// For all other actions, we return JSON
header('Content-Type: application/json');

// New API: Check if it's safe to modify a device (for UI warnings)
if ($action === 'check_safety') {
    $dev = $_POST['device'] ?? '';
    $devPath = (strpos($dev, '/dev/') === 0) ? $dev : "/dev/$dev";
    $safety = is_evacuation_safe($devPath, $logs);
    echo json_encode(['safe' => $safety['safe'], 'message' => $safety['error'] ?? '', 'logs' => $logs]);
    exit;
}

if ($action === 'update_swappiness') {
    $val = intval($_POST['val'] ?? 100);
    $val = max(0, min(100, $val)); // Clamp 0-100
    
    run_cmd("sysctl vm.swappiness=$val", $logs, $debugLog);
    
    // Persist
    $loaded = @parse_ini_file($configFile);
    $loaded['swappiness'] = $val;
    $res = []; foreach($loaded as $k => $v) $res[] = "$k=\"$v\"";
    file_put_contents($configFile, implode("\n", $res));
    
    echo json_encode(['success' => true, 'message' => "Swappiness set to $val", 'logs' => $logs]);
}

elseif ($action === 'update_debug') {
    $val = $_POST['val'] ?? 'no';
    
    // Persist
    $loaded = @parse_ini_file($configFile);
    $loaded['debug'] = $val;
    $res = []; foreach($loaded as $k => $v) $res[] = "$k=\"$v\"";
    file_put_contents($configFile, implode("\n", $res));
    
    debug_log("Debug mode set to $val", $debugLog);
    echo json_encode(['success' => true, 'message' => "Debug logging set to $val", 'logs' => $logs]);
}

elseif ($action === 'update_priority') {
    $dev = $_POST['device'] ?? '';
    $prio = intval($_POST['prio'] ?? 100);
    $prio = max(-1, min(32767, $prio)); // Clamp valid range
    $devPath = (strpos($dev, '/dev/') === 0) ? $dev : "/dev/$dev";
    
    // Safety Check
    $safety = is_evacuation_safe($devPath, $logs);
    if (!$safety['safe']) {
        echo json_encode(['success' => false, 'message' => $safety['error'], 'logs' => $logs]);
        exit;
    }

    // Apply Live
    if (run_cmd("swapoff $devPath", $logs, $debugLog) === 0) {
        if (run_cmd("swapon $devPath -p $prio", $logs, $debugLog) === 0) {
            
            // Persist (Update INI)
            $devName = str_replace('/dev/', '', $devPath); // zram0
            $index = intval(str_replace('zram', '', $devName)); // 0
            
            $loaded = @parse_ini_file($configFile);
            $devs = array_filter(explode(',', $loaded['zram_devices'] ?? ''));
            
            if (isset($devs[$index])) {
                // Entry format: size:algo or size:algo:prio
                $parts = explode(':', $devs[$index]);
                $parts[2] = $prio; // Set/Update prio
                $devs[$index] = implode(':', $parts);
                
                $loaded['zram_devices'] = implode(',', $devs);
                $res = []; foreach($loaded as $k => $v) $res[] = "$k=\"$v\"";
                file_put_contents($configFile, implode("\n", $res));
            }
            
            echo json_encode(['success' => true, 'message' => "Priority updated to $prio", 'logs' => $logs]);
        } else {
            echo json_encode(['success' => false, 'message' => "Failed to reactivate swap", 'logs' => $logs]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => "Failed to deactivate swap (Busy?)", 'logs' => $logs]);
    }
}

elseif ($action === 'create') {
    run_cmd('modprobe zram', $logs, $debugLog);
    $cmd = "zramctl --find --size " . escapeshellarg($size) . " --algorithm " . escapeshellarg($algo);
    exec($cmd . " 2>&1", $find_out, $ret);
    $logs[] = ['cmd' => $cmd, 'output' => implode(" ", $find_out), 'status' => $ret];
    if ($ret === 0) {
        $dev = trim(end($find_out));
        run_cmd("mkswap $dev", $logs, $debugLog);
        $s_ret = run_cmd("swapon $dev -p 100", $logs, $debugLog);
        if ($s_ret === 0) {
            $loaded = @parse_ini_file($configFile);
            $devs = array_filter(explode(',', $loaded['zram_devices'] ?? ''));
            $devs[] = "$size:$algo";
            $loaded['zram_devices'] = implode(',', $devs);
            $res = []; foreach($loaded as $k => $v) $res[] = "$k=\"$v\"";
            file_put_contents($configFile, implode("\n", $res));
            echo json_encode(['success' => true, 'message' => "Created $dev", 'logs' => $logs]);
        } else {
            echo json_encode(['success' => false, 'message' => "Swap activation failed", 'logs' => $logs]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => "No free ZRAM device", 'logs' => $logs]);
    }
} 

elseif ($action === 'remove') {
    // RUN SAFETY CHECK
    $safety = is_evacuation_safe($device, $logs);
    if (!$safety['safe']) {
        echo json_encode(['success' => false, 'message' => $safety['error'], 'logs' => $logs]);
        exit;
    }

    if (empty($device)) {
        exec('zramctl --noheadings --raw --output NAME', $devs);
        foreach ($devs as $d) {
            $d = trim($d); if (!$d) continue;
            $devPath = (strpos($d, '/dev/') === 0) ? $d : "/dev/$d";
            run_cmd("swapoff $devPath", $logs, $debugLog);
            run_cmd("zramctl --reset $devPath", $logs, $debugLog);
        }
        $loaded = @parse_ini_file($configFile);
        $loaded['zram_devices'] = '';
        $res = []; foreach($loaded as $k => $v) $res[] = "$k=\"$v\"";
        file_put_contents($configFile, implode("\n", $res));
        echo json_encode(['success' => true, 'message' => "Cleared all", 'logs' => $logs]);
    } else {
        $devPath = (strpos($device, '/dev/') === 0) ? $device : "/dev/$device";
        run_cmd("swapoff $devPath", $logs, $debugLog);
        run_cmd("zramctl --reset $devPath", $logs, $debugLog);
        $loaded = @parse_ini_file($configFile);
        $devs = array_filter(explode(',', $loaded['zram_devices'] ?? ''));
        array_pop($devs); 
        $loaded['zram_devices'] = implode(',', $devs);
        $res = []; foreach($loaded as $k => $v) $res[] = "$k=\"$v\"";
        file_put_contents($configFile, implode("\n", $res));
        echo json_encode(['success' => true, 'message' => "Removed $device", 'logs' => $logs]);
    }
}
if ($action === 'clear_log') {
    if (file_exists($debugLog)) {
        if (@file_put_contents($debugLog, date('[Y-m-d H:i:s] ') . "DEBUG: Log cleared by user.\n") !== false) {
            @chmod($debugLog, 0666);
            echo json_encode(['success' => true, 'message' => "Debug log cleared"]);
        } else {
            echo json_encode(['success' => false, 'message' => "Failed to write to log file. Check permissions."]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => "Log file not found"]);
    }
    exit;
}
?>