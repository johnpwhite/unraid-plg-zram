<?php
/**
 * <module_context>
 *   <name>zram_status</name>
 *   <description>JSON status API returning tiered ZRAM + SSD swap statistics, filtered to our devices</description>
 *   <dependencies>zram_config</dependencies>
 *   <consumers>zram-card.js (dashboard polling), UnraidZramCard.page</consumers>
 * </module_context>
 */

require_once dirname(__FILE__) . '/zram_config.php';
header('Content-Type: application/json');

$ourDev = zram_get_our_device();

// --- Tier 1: ZRAM device stats ---
$zramDevice = null;
$totalOriginal = 0;
$totalCompressed = 0;
$totalUsed = 0;
$diskSize = 0;

if ($ourDev) {
    // Get device details via zramctl
    $out = [];
    exec('zramctl --output-all --bytes --json 2>/dev/null', $out, $ret);
    $devices = [];
    if ($ret === 0 && !empty($out)) {
        $parsed = json_decode(implode("\n", $out), true);
        $devices = $parsed['zramctl'] ?? [];
    } else {
        unset($out);
        $out = [];
        exec('zramctl --output-all --bytes --noheadings --raw 2>/dev/null', $out);
        foreach ($out as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) >= 8) {
                $devices[] = [
                    'name' => $p[0], 'disksize' => $p[1], 'data' => $p[2],
                    'compr' => $p[3], 'algorithm' => $p[4], 'total' => $p[7],
                ];
            }
        }
    }

    // Find our device
    foreach ($devices as $d) {
        $name = basename($d['name'] ?? '');
        if ($name === $ourDev) {
            $zramDevice = $d;
            $totalOriginal = intval($d['data'] ?? 0);
            $totalCompressed = intval($d['compr'] ?? 0);
            $totalUsed = intval($d['total'] ?? 0);
            $diskSize = intval($d['disksize'] ?? 0);

            // Enrich with priority
            $prio = '100';
            exec('swapon --noheadings --show=NAME,PRIO 2>/dev/null', $sw_out);
            foreach ($sw_out as $sl) {
                $sp = preg_split('/\s+/', trim($sl));
                if (count($sp) >= 2 && basename($sp[0]) === $ourDev) {
                    $prio = $sp[count($sp) - 1];
                    break;
                }
            }
            $zramDevice['prio'] = $prio;

            // Enrich with IO ticks
            $statFile = "/sys/block/$ourDev/stat";
            $zramDevice['total_ticks'] = 0;
            if (file_exists($statFile)) {
                $stats = preg_split('/\s+/', trim(@file_get_contents($statFile)));
                if (count($stats) >= 8) {
                    $zramDevice['total_ticks'] = intval($stats[3]) + intval($stats[7]);
                }
            }
            break;
        }
    }
}

$memorySaved = max(0, $totalOriginal - $totalUsed);
$ratio = ($totalCompressed > 0) ? round($totalOriginal / $totalCompressed, 2) : 0;

// --- Tier 2: SSD swap stats ---
$ssdSwap = null;
$cfg = zram_config_read();
$ssdPath = $cfg['ssd_swap_path'] ?? '';
if ($ssdPath) {
    exec('swapon --bytes --noheadings --show=NAME,SIZE,USED,PRIO 2>/dev/null', $ssd_out);
    foreach ($ssd_out as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) >= 4 && $p[0] === $ssdPath) {
            $ssdSwap = [
                'path' => $ssdPath,
                'mount' => $cfg['ssd_swap_mount'],
                'size' => intval($p[1]),
                'used' => intval($p[2]),
                'prio' => $p[3],
                'active' => true,
            ];
            break;
        }
    }
    if (!$ssdSwap && file_exists($ssdPath)) {
        $ssdSwap = [
            'path' => $ssdPath,
            'mount' => $cfg['ssd_swap_mount'],
            'size' => filesize($ssdPath),
            'used' => 0,
            'prio' => '10',
            'active' => false,
        ];
    }
}

// --- System memory context ---
$meminfo = @file_get_contents('/proc/meminfo') ?: '';
preg_match('/MemTotal:\s+(\d+)/', $meminfo, $mt);
preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $ma);
$memTotal = intval($mt[1] ?? 0) * 1024;
$memAvail = intval($ma[1] ?? 0) * 1024;

// Effective memory: physical RAM + ZRAM effective capacity + SSD swap
$zramEffective = $diskSize > 0 ? $diskSize : 0; // Virtual size of ZRAM (what apps see)
$ssdSize = $ssdSwap ? $ssdSwap['size'] : 0;

// History from collector
$history = [];
if (file_exists(ZRAM_HISTORY_FILE)) {
    $h = json_decode(@file_get_contents(ZRAM_HISTORY_FILE), true);
    if (is_array($h)) $history = $h;
}

$swappiness = trim(@file_get_contents('/proc/sys/vm/swappiness') ?: '60');

echo json_encode([
    'timestamp' => time(),
    'zram_device' => $zramDevice,
    'ssd_swap' => $ssdSwap,
    'aggregates' => [
        'total_original'    => $totalOriginal,
        'total_compressed'  => $totalCompressed,
        'total_used'        => $totalUsed,
        'disk_size'         => $diskSize,
        'memory_saved'      => $memorySaved,
        'compression_ratio' => $ratio,
        'swappiness'        => $swappiness,
    ],
    'memory' => [
        'physical'  => $memTotal,
        'available' => $memAvail,
        'effective' => $memTotal + $zramEffective + $ssdSize,
        'zram_tier' => $zramEffective,
        'ssd_tier'  => $ssdSize,
    ],
    'history' => $history,
]);
