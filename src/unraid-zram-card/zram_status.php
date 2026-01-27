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

// Enrich with CPU Ticks from /sys/block/zramX/stat
foreach ($data['devices'] as &$device) {
    $devName = $device['name'];
    // zramctl output might be full path "/dev/zram0" or just "zram0"
    $devName = basename($devName); 
    
    $statFile = "/sys/block/$devName/stat";
    $device['total_ticks'] = 0;
    
    if (file_exists($statFile)) {
        $content = @file_get_contents($statFile);
        if ($content) {
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

$response = [
    'timestamp' => time(),
    'devices' => $data['devices'],
    'aggregates' => [
        'total_original' => $totalOriginal,
        'total_compressed' => $totalCompressed,
        'total_used' => $totalUsed,
        'disk_size_total' => $diskSizeTotal,
        'memory_saved' => $memorySaved,
        'compression_ratio' => $ratio
    ]
];

echo json_encode($response);
?>
