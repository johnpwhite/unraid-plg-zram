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

/** Build the status array that the endpoint sends as JSON. */
function zram_status_build(): array {
    $ourDev = zram_get_our_device();

    // --- Tier 1: ZRAM device stats ---
    $zramDevice = null;
    $totalOriginal = 0;
    $totalCompressed = 0;
    $totalUsed = 0;
    $diskSize = 0;
    $swapon = zram_find_binary('swapon');

    if ($ourDev) {
        // Request only the fields we consume. `--output-all` is unsafe to parse
        // positionally because an empty optional STREAMS field is collapsed by
        // whitespace splitting and shifts TOTAL onto the MEM-LIMIT position.
        $out = [];
        $devices = [];
        $zramctl = zram_find_binary('zramctl');
        if ($zramctl !== '') {
            $command = escapeshellarg($zramctl)
                . ' --bytes --noheadings --raw --output NAME,DISKSIZE,DATA,COMPR,ALGORITHM,TOTAL '
                . escapeshellarg("/dev/$ourDev") . ' 2>/dev/null';
            zram_exec($command, $out, $ret);
        } else {
            $ret = 127;
        }

        if ($ret === 0) {
            foreach ($out as $line) {
                $device = zram_parse_status_row($line);
                if ($device !== null) $devices[] = $device;
            }
        }

        // Find our device
        foreach ($devices as $d) {
            $name = basename($d['name']);
            if ($name === $ourDev) {
                $zramDevice = $d;
                $totalOriginal = $d['data'];
                $totalCompressed = $d['compr'];
                $totalUsed = $d['total'];
                $diskSize = $d['disksize'];

                // Enrich with priority
                $prio = '100';
                $sw_out = [];
                if ($swapon !== '') {
                    zram_exec(escapeshellarg($swapon) . ' --noheadings --show=NAME,PRIO 2>/dev/null', $sw_out);
                }
                foreach ($sw_out as $sl) {
                    $sp = preg_split('/\s+/', trim($sl));
                    if (count($sp) >= 2 && basename($sp[0]) === $ourDev) {
                        $prio = $sp[count($sp) - 1];
                        break;
                    }
                }
                $zramDevice['prio'] = $prio;

                // Enrich with IO ticks
                $statFile = ZRAM_SYS_BLOCK . "/$ourDev/stat";
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
        // The kernel lists /dev/loopN (loop) or /dev/zdN (zvol) in /proc/swaps
        // and swapon --show, not the configured path. Resolve that name first so
        // the match below works for every backing (Forgejo #27).
        $ssdBacking = $cfg['ssd_swap_backing'] ?? 'file';
        $resolved = zram_ssd_swap_target($ssdBacking, $ssdPath);
        $swapTarget = $resolved['target'];
        $loopDevName = $resolved['dev'];

        $ssd_out = [];
        if ($swapon !== '') {
            zram_exec(escapeshellarg($swapon) . ' --bytes --noheadings --show=NAME,SIZE,USED,PRIO 2>/dev/null', $ssd_out);
        }
        foreach ($ssd_out as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) >= 4 && $p[0] === $swapTarget) {
                $ssdSwap = [
                    'path'    => $ssdPath,
                    'mount'   => $cfg['ssd_swap_mount'],
                    'size'    => intval($p[1]),
                    'used'    => intval($p[2]),
                    'prio'    => $p[3],
                    'active'  => true,
                    'backing' => $ssdBacking,
                    'dev'     => $loopDevName,
                ];
                break;
            }
        }
        if (!$ssdSwap && file_exists($ssdPath)) {
            $ssdSwap = [
                'path'    => $ssdPath,
                'mount'   => $cfg['ssd_swap_mount'],
                'size'    => zram_ssd_swap_backing_size($ssdPath),
                'used'    => 0,
                'prio'    => (string)max(0, min(32767, intval($cfg['ssd_swap_priority'] ?? 10))),
                'active'  => false,
                'backing' => $ssdBacking,
                'dev'     => $loopDevName,
            ];
        }
    }

    // --- System memory context ---
    $meminfo = @file_get_contents(ZRAM_PROC_MEMINFO) ?: '';
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

    $swappiness = trim(@file_get_contents(ZRAM_SWAPPINESS_FILE) ?: '60');

    return [
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
    ];
}

if (!defined('ZRAM_STATUS_LIBRARY_ONLY')) {
    header('Content-Type: application/json');
    echo json_encode(zram_status_build());
}
