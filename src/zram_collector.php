<?php
/**
 * <module_context>
 *   <name>zram_collector</name>
 *   <description>Background daemon collecting rolling ZRAM stats history, filtered to our labeled device</description>
 *   <dependencies>zram_config</dependencies>
 *   <consumers>zram_status.php (reads history.json)</consumers>
 * </module_context>
 */

require_once dirname(__FILE__) . '/zram_config.php';

$maxPoints = 300;

// Load settings (cached — only re-read periodically)
$settings = zram_config_read();
$interval = max(1, intval($settings['collection_interval'] ?? 3));
$configRefreshCounter = 0;
$configRefreshEvery = max(1, intval(60 / $interval)); // Re-read config ~once per minute

zram_log("Collector starting (interval={$interval}s)...", 'INFO');

// PID management
if (file_exists(ZRAM_PID_FILE)) {
    $oldPid = intval(trim(@file_get_contents(ZRAM_PID_FILE)));
    if ($oldPid > 0 && posix_kill($oldPid, 0)) {
        zram_log("Collector already running (PID $oldPid). Exiting.", 'INFO');
        exit;
    }
}
file_put_contents(ZRAM_PID_FILE, getmypid());

$lastTotalTicks = null;
$lastTime = null;

// Load existing history
$history = [];
if (file_exists(ZRAM_HISTORY_FILE)) {
    $h = json_decode(@file_get_contents(ZRAM_HISTORY_FILE), true);
    if (is_array($h)) $history = $h;
}

while (true) {
    try {
        // Periodically refresh config from disk (not every iteration)
        $configRefreshCounter++;
        if ($configRefreshCounter >= $configRefreshEvery) {
            $configRefreshCounter = 0;
            $settings = zram_config_read();
            $interval = max(1, intval($settings['collection_interval'] ?? 3));
            zram_debug_reset();
        }

        // Find our device
        $ourDev = '';
        if (file_exists(ZRAM_DEVICE_FILE)) {
            $ourDev = trim(@file_get_contents(ZRAM_DEVICE_FILE));
        }
        if (empty($ourDev)) {
            $ourDev = zram_get_our_device();
        }

        $totalOriginal = 0;
        $totalUsed = 0;
        $currentTotalTicks = 0;

        if ($ourDev && file_exists("/sys/block/$ourDev")) {
            // Collect stats for our device only
            $raw = [];
            exec("zramctl --bytes --noheadings --raw --output NAME,DATA,TOTAL /dev/$ourDev 2>/dev/null", $raw);
            foreach ($raw as $line) {
                $p = preg_split('/\s+/', trim($line));
                if (count($p) >= 3 && basename($p[0]) === $ourDev) {
                    $totalOriginal = intval($p[1]);
                    $totalUsed = intval($p[2]);
                    break;
                }
            }

            // IO ticks for our device
            $statFile = "/sys/block/$ourDev/stat";
            if (file_exists($statFile)) {
                $stats = preg_split('/\s+/', trim(@file_get_contents($statFile)));
                if (count($stats) >= 8) {
                    $currentTotalTicks = intval($stats[3]) + intval($stats[7]);
                }
            }
        }

        // Calculate load
        $now = microtime(true) * 1000;
        $loadPct = 0;
        if ($lastTotalTicks !== null && $lastTime !== null) {
            $dt = $now - $lastTime;
            if ($dt > 0) {
                $loadPct = max(0, (($currentTotalTicks - $lastTotalTicks) / $dt) * 100);
            }
        }
        $lastTotalTicks = $currentTotalTicks;
        $lastTime = $now;

        // Append to history (schema: t=timestamp, o=original uncompressed, u=used compressed, l=load%)
        $history[] = ['t' => date('H:i:s'), 'o' => $totalOriginal, 'u' => $totalUsed, 'l' => round($loadPct, 1)];
        if (count($history) > $maxPoints) array_shift($history);

        // Atomic-ish write (write to tmp then rename)
        $tmp = ZRAM_HISTORY_FILE . '.tmp';
        if (file_put_contents($tmp, json_encode($history)) !== false) {
            rename($tmp, ZRAM_HISTORY_FILE);
        }

        zram_log("Poll: orig=" . round($totalOriginal/1048576) . "MB, used=" . round($totalUsed/1048576) . "MB, load=" . round($loadPct, 1) . "%");

        // Log rotation: truncate if > 1MB
        if (file_exists(ZRAM_DEBUG_LOG) && filesize(ZRAM_DEBUG_LOG) > 1048576) {
            zram_log("LOG ROTATED", 'INFO');
            file_put_contents(ZRAM_DEBUG_LOG, "[LOG ROTATED]\n");
        }

    } catch (Exception $e) {
        @file_put_contents(ZRAM_DEBUG_LOG, date('[Y-m-d H:i:s] ') . "[ERROR] Collector: " . $e->getMessage() . "\n", FILE_APPEND);
    }

    sleep($interval);
}
