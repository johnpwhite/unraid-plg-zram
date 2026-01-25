<?php
// zram_swap.php
// Backend logic for managing ZRAM swap devices

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$size = $_POST['size'] ?? '1G'; // Default 1GB
$response = ['success' => false, 'message' => 'Invalid action'];

if ($action === 'create') {
    // 1. Ensure module is loaded
    exec('modprobe zram 2>&1', $out, $ret);
    
    // 2. Find and setup device
    $cmd = "zramctl --find --size " . escapeshellarg($size);
    exec($cmd, $out, $ret);
    
    if ($ret === 0) {
        $dev = trim(end($out));
        // 3. Make Swap
        exec("mkswap $dev 2>&1", $out, $ret);
        // 4. Swap On (Priority 100)
        exec("swapon $dev -p 100 2>&1", $out, $ret);
        
        $response = ['success' => true, 'message' => "Created ZRAM swap on $dev ($size)"];
    } else {
        $response = ['success' => false, 'message' => "Failed to create ZRAM device: " . implode(" ", $out)];
    }
} 

elseif ($action === 'remove') {
    $device = $_POST['device'] ?? '';
    if (empty($device)) {
        // Find first zram device if none specified
        exec('zramctl --noheadings --raw --output NAME', $out);
        $device = trim($out[0] ?? '');
    }

    if ($device && strpos($device, 'zram') !== false) {
        if (strpos($device, '/dev/') === false) $device = "/dev/$device";
        
        exec("swapoff $device 2>&1", $out, $ret);
        exec("zramctl --reset $device 2>&1", $out, $ret);
        
        $response = ['success' => true, 'message' => "Removed ZRAM device $device"];
    } else {
        $response = ['success' => false, 'message' => "No ZRAM device found to remove"];
    }
}

echo json_encode($response);
?>