<?php
// zram_swap.php
// Backend logic with detailed execution logging

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$size = $_POST['size'] ?? '1G';
$algo = $_POST['algo'] ?? 'zstd';
$device = $_POST['device'] ?? '';

$configFile = "/boot/config/plugins/unraid-zram-card/settings.ini";
$logs = [];

function run_cmd($cmd, &$logs) {
    exec($cmd . " 2>&1", $out, $ret);
    $logs[] = [
        'cmd' => $cmd,
        'output' => implode(" ", $out),
        'status' => $ret
    ];
    return $ret;
}

function get_zram_settings($file) {
    $defaults = ['enabled' => 'yes', 'refresh_interval' => '3000', 'zram_devices' => '', 'swap_size' => '1G', 'compression_algo' => 'zstd'];
    if (file_exists($file)) {
        $loaded = @parse_ini_file($file);
        return array_merge($defaults, $loaded ?: []);
    }
    return $defaults;
}

function save_zram_settings($file, $settings) {
    $res = [];
    foreach($settings as $key => $val) {
        $res[] = "$key=\"$val\"";
    }
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
    file_put_contents($file, implode("\n", $res));
}

if ($action === 'create') {
    run_cmd('modprobe zram', $logs);
    
    // Combined command: find, set size, and set algorithm in one go
    $cmd = "zramctl --find --size " . escapeshellarg($size) . " --algorithm " . escapeshellarg($algo);
    exec($cmd . " 2>&1", $find_out, $ret);
    
    $logs[] = [
        'cmd' => $cmd,
        'output' => implode(" ", $find_out),
        'status' => $ret
    ];

    if ($ret === 0) {
        $dev = trim(end($find_out));
        
        // 2. Swap
        run_cmd("mkswap $dev", $logs);
        $s_ret = run_cmd("swapon $dev -p 100", $logs);
        
        if ($s_ret === 0) {
            $settings = get_zram_settings($configFile);
            $devices = array_filter(explode(',', $settings['zram_devices']));
            $devices[] = "$size:$algo";
            $settings['zram_devices'] = implode(',', $devices);
            save_zram_settings($configFile, $settings);
            
            echo json_encode(['success' => true, 'message' => "Created $dev", 'logs' => $logs]);
        } else {
            echo json_encode(['success' => false, 'message' => "Swap activation failed", 'logs' => $logs]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => "No free ZRAM device", 'logs' => $logs]);
    }
} 

elseif ($action === 'remove') {
    if (empty($device)) {
        exec('zramctl --noheadings --raw --output NAME', $devs);
        foreach ($devs as $d) {
            $d = trim($d);
            if ($d) {
                run_cmd("swapoff /dev/$d", $logs);
                run_cmd("zramctl --reset /dev/$d", $logs);
            }
        }
        $settings = get_zram_settings($configFile);
        $settings['zram_devices'] = '';
        save_zram_settings($configFile, $settings);
        echo json_encode(['success' => true, 'message' => "Cleared all", 'logs' => $logs]);
    } else {
        $devPath = (strpos($device, '/dev/') === false) ? "/dev/$device" : $device;
        run_cmd("swapoff $devPath", $logs);
        run_cmd("zramctl --reset $devPath", $logs);
        
        $settings = get_zram_settings($configFile);
        $devices = array_filter(explode(',', $settings['zram_devices']));
        array_pop($devices); 
        $settings['zram_devices'] = implode(',', $devices);
        save_zram_settings($configFile, $settings);
        
        echo json_encode(['success' => true, 'message' => "Removed $device", 'logs' => $logs]);
    }
}
?>