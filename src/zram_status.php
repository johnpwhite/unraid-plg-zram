<?php
// zram_status.php
// Returns ZRAM statistics in JSON format.
// Security: Ensure this is only accessible to authenticated users (Unraid handles this if placed in plugins dir and accessed via authenticated session, but explicit check is good)

header('Content-Type: application/json');

// Execute zramctl
// We prefer JSON output if available
$output = [];
$return_var = 0;

// Try JSON format first
exec('zramctl --output-all --bytes --json 2>/dev/null', $output, $return_var);

$data = [];

if ($return_var === 0 && !empty($output)) {
    // JSON supported
    $jsonString = implode("\n", $output);
    $parsed = json_decode($jsonString, true);
    if (isset($parsed['zramctl'])) {
        $data['devices'] = $parsed['zramctl'];
    } else {
        $data['devices'] = [];
    }
} else {
    // Fallback: Parse raw text
    // Format usually: NAME DISKSIZE DATA COMPR ALGORITHM STREAMS ZERO-PAGES TOTAL MEM-LIMIT MEM-USED MIGRATED MOUNTPOINT
    // We use --raw --noheadings --bytes --output-all to get predictable columns
    unset($output);
    exec('zramctl --output-all --bytes --noheadings --raw 2>/dev/null', $output, $return_var);
    
    $devices = [];
    foreach ($output as $line) {
        // Raw output is space separated, but might have empty fields? 
        // Best to use specific columns if --output-all varies.
        // Let's rely on standard columns: NAME, DISKSIZE, DATA, COMPR, ALGORITHM, STREAMS, ZERO-PAGES, TOTAL, MEM-LIMIT, MEM-USED, MIGRATED, MOUNTPOINT
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) >= 5) {
            $devices[] = [
                'name' => $parts[0] ?? '',
                'disksize' => $parts[1] ?? 0,
                'data' => $parts[2] ?? 0,
                'compr' => $parts[3] ?? 0,
                'algorithm' => $parts[4] ?? '',
                'streams' => $parts[5] ?? 0,
                'zero-pages' => $parts[6] ?? 0,
                'total' => $parts[7] ?? 0, // Total memory used
                // Add others as needed
            ];
        }
    }
    $data['devices'] = $devices;
}

// Get Priority Map from swapon
// Note: swapon --show=NAME,PRIO outputs name and numeric priority
$prioMap = [];
exec('swapon --noheadings --show=NAME,PRIO 2>/dev/null', $swap_out);
foreach ($swap_out as $line) {
    $parts = preg_split('/\s+/', trim($line));
    // Validate: NAME should start with /dev, PRIO should be numeric
    if (count($parts) >= 2 && strpos($parts[0], '/dev') === 0) {
        $prio = $parts[count($parts) - 1]; // Priority is last column
        if (is_numeric($prio) || $prio === '-1') {
            $prioMap[$parts[0]] = $prio;
        }
    }
}

// Get Global Swappiness
$globalSwappiness = trim(@file_get_contents('/proc/sys/vm/swappiness') ?: '60');

// Enrich with CPU Ticks and Priority
foreach ($data['devices'] as &$device) {
    $devName = $device['name'];
    $basename = basename($devName); 
    $fullPath = (strpos($devName, '/dev/') === 0) ? $devName : "/dev/$devName";
    
    $device['prio'] = $prioMap[$fullPath] ?? '100';
    
    $statFile = "/sys/block/$basename/stat";
    $device['total_ticks'] = 0;
    
    if (file_exists($statFile)) {
        $content = @file_get_contents($statFile);
        if ($content) {
            // Debug Log
            // $debugLine = date('H:i:s') . " Dev: $devName | Raw: $content";
            // file_put_contents('/tmp/unraid-zram-card/stat_debug.log', $debugLine, FILE_APPEND);
            
            // Content format: read_ios read_merges read_sectors read_ticks write_ios ...
            // We want indices 3 (read ticks) and 7 (write ticks) - 0-indexed split
            $stats = preg_split('/\s+/', trim($content));
            if (count($stats) >= 8) {
                $readTicks = intval($stats[3]);
                $writeTicks = intval($stats[7]);
                $device['total_ticks'] = $readTicks + $writeTicks;
            }
        }
    }
}
unset($device); // Break reference

// Calculate Aggregates
$totalOriginal = 0;
$totalCompressed = 0;
$totalUsed = 0; // Total physical memory used by zram
$diskSizeTotal = 0;

foreach ($data['devices'] as $dev) {
    // Keys might vary between JSON and raw parsing. 
    // JSON keys usually: "name", "disksize", "data", "compr", "algorithm", "streams", "zero-pages", "total", "mem-limit", "mem-used", "migrated", "mountpoint"
    // Adjust values to integers
    $original = intval($dev['data'] ?? 0);
    $compressed = intval($dev['compr'] ?? 0);
    $totalMem = intval($dev['total'] ?? 0); // specific zram metadata + compressed data
    $diskSize = intval($dev['disksize'] ?? 0);

    $totalOriginal += $original;
    $totalCompressed += $compressed;
    $totalUsed += $totalMem;
    $diskSizeTotal += $diskSize;
}

// Calculate "Memory Saved" (Original Data Size - Compressed Size)
// Or more accurately: (Original Data Size - Total ZRAM Usage)
$memorySaved = max(0, $totalOriginal - $totalUsed);

// Compression Ratio
$ratio = ($totalCompressed > 0) ? round($totalOriginal / $totalCompressed, 2) : 0;

// Include Rolling History from collector
$history = [];
$historyFile = "/tmp/unraid-zram-card/history.json";
if (file_exists($historyFile)) {
    $history = json_decode(file_get_contents($historyFile), true) ?: [];
}

$response = [
    'timestamp' => time(),
    'devices' => $data['devices'],
    'aggregates' => [
        'total_original' => $totalOriginal,
        'total_compressed' => $totalCompressed,
        'total_used' => $totalUsed,
        'disk_size_total' => $diskSizeTotal,
        'memory_saved' => $memorySaved,
        'compression_ratio' => $ratio,
        'swappiness' => $globalSwappiness
    ],
    'history' => $history
];

echo json_encode($response);
?>
