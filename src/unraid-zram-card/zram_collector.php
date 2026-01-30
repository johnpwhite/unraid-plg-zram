<?php
// zram_collector.php
// Background collector for ZRAM statistics history.
// Maintains a rolling history of ~300 points (1 hour at 12s interval).

$logDir = "/tmp/unraid-zram-card";
if (!is_dir($logDir)) mkdir($logDir, 0777, true);

$historyFile = "$logDir/history.json";
$pidFile = "$logDir/collector.pid";
$maxPoints = 300;
$interval = 12; // seconds
$configFile = "/boot/config/plugins/unraid-zram-card/settings.ini";
$debugLog = "$logDir/debug.log";

// Load Settings for Debug Flag
$settings = @parse_ini_file($configFile);
$debugEnabled = ($settings['debug'] ?? 'no') === 'yes';

if ($debugEnabled) {
    @file_put_contents($debugLog, date('[Y-m-d H:i:s] ') . "DEBUG: Collector service starting...\n", FILE_APPEND);
    @chmod($debugLog, 0666);
}

// PID Management - Check for running instance
if (file_exists($pidFile)) {
    $oldPid = trim(file_get_contents($pidFile));
    if (!empty($oldPid) && posix_kill($oldPid, 0)) {
        if ($debugEnabled) {
            @file_put_contents($debugLog, date('[Y-m-d H:i:s] ') . "DEBUG: Collector already running with PID $oldPid. Exiting.\n", FILE_APPEND);
        }
        exit;
    }
}
file_put_contents($pidFile, getmypid());

$lastTotalTicks = null;
$lastTime = null;

// Initialize history if exists
$history = [];
if (file_exists($historyFile)) {
    $content = file_get_contents($historyFile);
    $history = json_decode($content, true) ?: [];
}

while (true) {
    try {
        // --- 1. Collect Data (Mirroring zram_status.php logic) ---
        $zram_raw = [];
        exec('zramctl --bytes --noheadings --raw --output DATA,TOTAL 2>/dev/null', $zram_raw);
        
        $totalOriginal = 0;
        $totalUsed = 0;
        foreach ($zram_raw as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) >= 2) {
                $totalOriginal += intval($parts[0]);
                $totalUsed += intval($parts[1]);
            }
        }
        $memorySaved = max(0, $totalOriginal - $totalUsed);

        // --- 2. Calculate CPU Load ---
        $currentTotalTicks = 0;
        exec("cat /sys/block/zram*/stat 2>/dev/null", $stats_out);
        foreach ($stats_out as $line) {
            $stats = preg_split('/\s+/', trim($line));
            if (count($stats) >= 8) {
                // Indices 3 (read) and 7 (write)
                $currentTotalTicks += intval($stats[3]) + intval($stats[7]);
            }
        }

        $now = microtime(true) * 1000; // ms
        $loadPct = 0;
        if ($lastTotalTicks !== null && $lastTime !== null) {
            $deltaTicks = $currentTotalTicks - $lastTotalTicks;
            $deltaTime = $now - $lastTime;
            if ($deltaTime > 0) {
                $loadPct = ($deltaTicks / $deltaTime) * 100;
            }
        }
        if ($loadPct < 0) $loadPct = 0;

        $lastTotalTicks = $currentTotalTicks;
        $lastTime = $now;

        // --- 3. Update History ---
        $entry = [
            't' => date('H:i:s'), // Timestamp label
            's' => $memorySaved,
            'l' => round($loadPct, 1)
        ];

        $history[] = $entry;
        if (count($history) > $maxPoints) {
            array_shift($history);
        }

        if ($debugEnabled) {
            @file_put_contents($debugLog, date('[Y-m-d H:i:s] ') . "DEBUG: Polling complete. Saved=" . round($memorySaved/1024/1024) . "MB, Load=" . round($loadPct, 1) . "%\n", FILE_APPEND);
            @chmod($debugLog, 0666);
            
            // Simple Rotation: If log > 1MB, trim it
            if (filesize($debugLog) > 1048576) {
                file_put_contents($debugLog, "[LOG ROTATED]\n");
            }
        }

        file_put_contents($historyFile, json_encode($history));

    } catch (Exception $e) {
        // Log error and continue
        @file_put_contents("$logDir/collector_error.log", date('[Y-m-d H:i:s] ') . $e->getMessage() . "\n", FILE_APPEND);
    }

    sleep($interval);
}
