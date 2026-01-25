<?php
// zram_swap.php
// Backend logic for managing ZRAM swap devices with persistence

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$size = $_POST['size'] ?? '1G';
$device = $_POST['device'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action'];

$configFile = "/boot/config/plugins/unraid-zram-card/settings.ini";

// Helper: Read Settings
function get_zram_settings($file) {
    $defaults = ['enabled' => 'yes', 'refresh_interval' => '3000', 'zram_devices' => '', 'swap_size' => '1G'];
    if (file_exists($file)) {
        $loaded = @parse_ini_file($file);
        return array_merge($defaults, $loaded ?: []);
    }
    return $defaults;
}

// Helper: Save Settings
function save_zram_settings($file, $settings) {
    $res = [];
    foreach($settings as $key => $val) {
        $res[] = "$key=\"$val\"";
    }
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
    file_put_contents($file, implode("\n", $res));
}

if ($action === 'create') {
    exec('modprobe zram 2>&1', $out, $ret);
    $cmd = "zramctl --find --size " . escapeshellarg($size);
    exec($cmd, $out, $ret);
    
    if ($ret === 0) {
        $dev = trim(end($out));
        exec("mkswap $dev 2>&1");
        exec("swapon $dev -p 100 2>&1");
        
        // Update Persistence
        $settings = get_zram_settings($configFile);
        $devices = array_filter(explode(',', $settings['zram_devices']));
        $devices[] = $size;
        $settings['zram_devices'] = implode(',', $devices);
        save_zram_settings($configFile, $settings);
        
        $response = ['success' => true, 'message' => "Created ZRAM swap on $dev ($size)"];
    } else {
        $response = ['success' => false, 'message' => "Failed: " . implode(" ", $out)];
    }
} 

elseif ($action === 'remove') {
    if (empty($device)) {
        // Remove ALL and clear persistence
        exec('zramctl --noheadings --raw --output NAME', $devs);
        foreach ($devs as $d) {
            $d = trim($d);
            if ($d) {
                exec("swapoff /dev/$d 2>&1");
                exec("zramctl --reset /dev/$d 2>&1");
            }
        }
        $settings = get_zram_settings($configFile);
        $settings['zram_devices'] = '';
        save_zram_settings($configFile, $settings);
        $response = ['success' => true, 'message' => "Removed all ZRAM devices"];
    } else {
        // Remove SPECIFIC device
        $devPath = (strpos($device, '/dev/') === false) ? "/dev/$device" : $device;
        exec("swapoff $devPath 2>&1", $out, $ret);
        exec("zramctl --reset $devPath 2>&1", $out, $ret);
        
        // Update Persistence (This is tricky because we don't know which size entry corresponds to which device index perfectly, 
        // but we'll remove the first matching size or just the last entry as a best effort for now.)
        $settings = get_zram_settings($configFile);
        $devices = array_filter(explode(',', $settings['zram_devices']));
        // We remove one entry from the persistent list.
        array_pop($devices); 
        $settings['zram_devices'] = implode(',', $devices);
        save_zram_settings($configFile, $settings);
        
        $response = ['success' => true, 'message' => "Removed $device"];
    }
}

echo json_encode($response);
?>
