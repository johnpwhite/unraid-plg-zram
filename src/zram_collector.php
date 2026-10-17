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

/** Make command output safe and compact enough for the rolling debug log. */
function zram_collector_log_value(string $value, int $maxLength = 512): string {
    $value = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value) ?? '');
    if (strlen($value) > $maxLength) return substr($value, 0, $maxLength) . '...';
    return $value === '' ? 'none' : $value;
}

/** Exact bytes first keeps sub-MiB activity visible instead of rounding it to zero. */
function zram_collector_format_bytes(int $bytes): string {
    return $bytes . 'B (' . number_format($bytes / 1048576, 6, '.', '') . 'MiB)';
}

/** Resolve the plugin-owned device with one direct, cache-bypassing blkid probe. */
function zram_collector_find_labelled_device(string &$error): string {
    $error = '';
    $candidates = array_values(array_filter(glob(ZRAM_DEV_DIR . '/zram*') ?: [], static function ($path): bool {
        return preg_match('#^' . preg_quote(ZRAM_DEV_DIR, '#') . '/zram\d+$#', $path) === 1;
    }));
    if (!$candidates) return '';

    $blkidBinary = zram_find_binary('blkid');
    if ($blkidBinary === '') {
        $error = 'blkid binary not found in /sbin or /usr/sbin';
        return '';
    }

    $output = [];
    $exitCode = 127;
    $command = escapeshellarg($blkidBinary) . ' -c /dev/null -t LABEL='
        . escapeshellarg(ZRAM_LABEL) . ' -o device '
        . implode(' ', array_map('escapeshellarg', $candidates)) . ' 2>&1';
    zram_exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        // blkid uses 2 for a clean no-match result; that means missing/unowned,
        // not that the ownership probe itself failed.
        if ($exitCode !== 2 || array_filter($output, static fn($line): bool => trim((string) $line) !== '')) {
            $error = "blkid exit_code=$exitCode output="
                . zram_collector_log_value(implode(' | ', $output));
        }
        return '';
    }

    foreach ($output as $line) {
        $devicePath = trim((string) $line);
        if (preg_match('#^' . preg_quote(ZRAM_DEV_DIR, '#') . '/zram\d+$#', $devicePath) === 1) return basename($devicePath);
    }
    $error = 'blkid returned no valid zram device path';
    return '';
}

/** A configured zram device is usable by the plugin only while active as swap. */
function zram_collector_device_is_active_swap(string $device, string &$error): bool {
    $error = '';
    $swaps = @file_get_contents(ZRAM_PROC_SWAPS);
    if ($swaps === false) {
        $error = 'unable to read /proc/swaps';
        return false;
    }
    foreach (preg_split('/\R/', trim($swaps)) ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (is_array($parts) && isset($parts[0]) && basename($parts[0]) === $device) return true;
    }
    return false;
}

/**
 * Pure classification of one zramctl result. Host I/O (exec and /proc/swaps)
 * stays outside so every failure mode can be covered by regression tests.
 *
 * @return array{status:string,disk_size:int,data:int,compr:int,total:int,valid:bool,details:string}
 */
function zram_collector_classify_zramctl_result(
    string $device,
    array $rows,
    int $exitCode,
    bool $activeSwap,
    string $swapStateError = '',
    bool $cachePointsToDevice = false
): array {
    $result = [
        'status' => '',
        'disk_size' => 0,
        'data' => 0,
        'compr' => 0,
        'total' => 0,
        'valid' => false,
        'details' => '',
    ];

    if ($exitCode !== 0) {
        $result['status'] = 'zramctl_command_failure';
        $result['details'] = "selected_device=$device exit_code=$exitCode error="
            . zram_collector_log_value(implode(' | ', $rows));
        return $result;
    }

    $nonEmptyRows = array_values(array_filter($rows, static function ($line): bool {
        return trim((string) $line) !== '';
    }));
    if (!$nonEmptyRows) {
        $result['status'] = 'zramctl_empty_output';
        $result['details'] = "selected_device=$device exit_code=0";
        return $result;
    }

    $matchingRowFound = false;
    $malformedRow = '';
    $returnedDevices = [];
    foreach ($nonEmptyRows as $line) {
        $parsed = zram_parse_status_row((string) $line);
        if ($parsed === null) {
            if ($malformedRow === '') $malformedRow = (string) $line;
            continue;
        }
        $rowDevice = basename($parsed['name']);
        if ($rowDevice !== $device) {
            $returnedDevices[] = $rowDevice;
            continue;
        }
        $matchingRowFound = true;
        $result['disk_size'] = $parsed['disksize'];
        $result['data'] = $parsed['data'];
        $result['compr'] = $parsed['compr'];
        $result['total'] = $parsed['total'];
        break;
    }

    if ($malformedRow !== '') {
        $result['status'] = 'zramctl_malformed_row';
        $result['details'] = "selected_device=$device row=" . zram_collector_log_value($malformedRow);
        return $result;
    }
    if (!$matchingRowFound) {
        $result['status'] = 'zramctl_nonmatching_row';
        $result['details'] = "selected_device=$device returned_devices="
            . zram_collector_log_value(implode(',', array_unique($returnedDevices)));
        return $result;
    }
    if ($result['disk_size'] === 0) {
        $result['status'] = $cachePointsToDevice ? 'stale_cached_device' : 'unconfigured_device';
        $result['details'] = 'cached_device=' . ($cachePointsToDevice ? $device : 'none')
            . " selected_device=$device disksize=0B reason=device is reset or unconfigured";
        return $result;
    }
    if (!$activeSwap) {
        $result['status'] = $swapStateError === '' ? 'inactive_device' : 'swap_state_unreadable';
        $result['details'] = "selected_device=$device disksize={$result['disk_size']}B reason="
            . ($swapStateError === '' ? 'device is not listed in /proc/swaps' : $swapStateError);
        return $result;
    }

    $result['valid'] = true;
    $result['status'] = ($result['data'] === 0 && $result['compr'] === 0 && $result['total'] === 0)
        ? 'healthy_idle'
        : 'healthy_active';
    $result['details'] = "selected_device=$device disksize={$result['disk_size']}B data={$result['data']}B"
        . " compr={$result['compr']}B total={$result['total']}B";
    return $result;
}

/**
 * Log diagnostics on state changes, then rate-limit unchanged states. Separate
 * channels prevent a stale cache warning and a healthy sample from alternating
 * into log spam on every poll.
 */
function zram_collector_log_diagnostic(
    string $channel,
    string $status,
    string $details,
    string $level = 'WARN',
    int $repeatAfter = 300
): void {
    global $zramCollectorDiagnosticStates;
    $now = time();
    $previous = $zramCollectorDiagnosticStates[$channel] ?? null;
    // Device/exit-code changes are meaningful state changes; live byte counters
    // are not, otherwise an active device would emit an INFO line every poll.
    preg_match_all('/\b(?:cached_device|selected_device|exit_code)=\S+/', $details, $identityMatches);
    $stateKey = $status . '|' . implode('|', $identityMatches[0]);
    if ($previous === null || $previous['key'] !== $stateKey || ($now - $previous['logged_at']) >= $repeatAfter) {
        zram_log("Collector diagnostics: status=$status $details", $level);
        $zramCollectorDiagnosticStates[$channel] = ['key' => $stateKey, 'logged_at' => $now];
    }
}

/** How many history points the collector keeps (the chart shows these). */
const ZRAM_COLLECTOR_MAX_POINTS = 300;

/**
 * Build the collector state: settings, counters and the loaded history. The
 * daemon calls this once. A test calls it, then changes the state it needs.
 *
 * @return array<string,mixed>
 */
function zram_collector_state_init(): array {
    global $zramCollectorDiagnosticStates;
    /** @var array<string, array{key: string, logged_at: int}> $zramCollectorDiagnosticStates */
    $zramCollectorDiagnosticStates = [];

    // Load settings (cached — only re-read periodically)
    $settings = zram_config_read();
    $interval = max(1, intval($settings['collection_interval'] ?? 3));

    zram_log("Collector starting (interval={$interval}s)...", 'INFO');

    $zramctlBinary = zram_find_binary('zramctl');
    if ($zramctlBinary !== '') {
        zram_log("Collector using zramctl binary: $zramctlBinary", 'INFO');
    }

    // Load existing history. A missing, corrupt or non-array file starts empty.
    $history = [];
    if (file_exists(ZRAM_HISTORY_FILE)) {
        $h = json_decode((string) @file_get_contents(ZRAM_HISTORY_FILE), true);
        if (is_array($h)) $history = array_values($h);
    }

    return [
        'maxPoints'            => ZRAM_COLLECTOR_MAX_POINTS,
        'settings'             => $settings,
        'interval'             => $interval,
        'configRefreshCounter' => 0,
        'configRefreshEvery'   => max(1, intval(60 / $interval)), // Re-read config ~once per minute
        'zramctlBinary'        => $zramctlBinary,
        'lastTotalTicks'       => null,
        'lastTime'             => null,
        'validatedDev'         => '',
        'deviceResolutionNext' => 0,
        'lastCacheSignature'   => null,
        'cacheIsStale'         => false,
        'resolutionStatus'     => '',
        'resolutionDetails'    => '',
        'lastCollectorStatus'  => '',
        'history'              => $history,
    ];
}

/**
 * PID file handling. Returns false when another collector is already alive
 * (the caller then exits); otherwise writes our PID and returns true. A stale
 * PID (process gone) is replaced. $isAlive takes a PID and returns bool; tests
 * pass a fake, the daemon uses posix_kill($pid, 0).
 */
function zram_collector_claim_pid(?callable $isAlive = null, ?int $myPid = null): bool {
    $isAlive = $isAlive ?? static fn(int $pid): bool => posix_kill($pid, 0);
    if (file_exists(ZRAM_PID_FILE)) {
        $oldPid = intval(trim((string) @file_get_contents(ZRAM_PID_FILE)));
        if ($oldPid > 0 && $isAlive($oldPid)) {
            zram_log("Collector already running (PID $oldPid). Exiting.", 'INFO');
            return false;
        }
    }
    file_put_contents(ZRAM_PID_FILE, $myPid ?? getmypid());
    return true;
}

/**
 * One collection tick: refresh settings now and then, resolve the device,
 * run zramctl, sample Tier 2, append to the history file and write the poll
 * log line. All loop state lives in $state. $nowSec and $nowMs (epoch seconds
 * and milliseconds) are for tests; the daemon passes nothing.
 *
 * @param array<string,mixed> $state from zram_collector_state_init()
 */
function zram_collector_tick(array &$state, ?int $nowSec = null, ?float $nowMs = null): void {
    global $zramCollectorDiagnosticStates;
    try {
        // Periodically refresh config from disk (not every iteration)
        $state['configRefreshCounter']++;
        if ($state['configRefreshCounter'] >= $state['configRefreshEvery']) {
            $state['configRefreshCounter'] = 0;
            $state['settings'] = zram_config_read();
            $state['interval'] = max(1, intval($state['settings']['collection_interval'] ?? 3));
            zram_debug_reset();
        }
        $settings = $state['settings'];

        // No Tier 2 re-activation here (Forgejo #30). The collector turned the
        // swap on again during a long array stop, and it undid a manual
        // swapoff. Tier 2 turns on at boot (zram_init.sh) and at array start
        // (event/disks_mounted) only. See docs/specs/TIER2_RECOVERY.md.

        // Validate ownership at startup/about once a minute, or immediately when
        // device.conf/sysfs changes. Common polls reuse the validated device and
        // avoid forking blkid every collection interval.
        clearstatcache();
        $cacheExists = file_exists(ZRAM_DEVICE_FILE);
        $cachedDev = $cacheExists ? trim((string) @file_get_contents(ZRAM_DEVICE_FILE)) : '';
        $cacheSignature = ($cacheExists ? (string) @filemtime(ZRAM_DEVICE_FILE) . ':' . $cachedDev : 'absent');
        $validatedMissing = $state['validatedDev'] !== '' && !file_exists(ZRAM_SYS_BLOCK . "/{$state['validatedDev']}");
        if (($nowSec ?? time()) >= $state['deviceResolutionNext'] || $cacheSignature !== $state['lastCacheSignature'] || $validatedMissing) {
            $ownershipError = '';
            $resolvedDev = zram_collector_find_labelled_device($ownershipError);
            $state['validatedDev'] = preg_match('/^zram\d+$/', $resolvedDev) === 1
                && file_exists(ZRAM_SYS_BLOCK . "/$resolvedDev") ? $resolvedDev : '';
            $state['cacheIsStale'] = $cacheExists && (
                preg_match('/^zram\d+$/', $cachedDev) !== 1
                || !file_exists(ZRAM_SYS_BLOCK . "/$cachedDev")
                || $state['validatedDev'] === ''
                || $cachedDev !== $state['validatedDev']
            );
            if ($ownershipError !== '') {
                $state['resolutionStatus'] = 'device_ownership_unverified';
                $state['resolutionDetails'] = 'cached_device=' . ($cachedDev === '' ? 'none' : zram_collector_log_value($cachedDev))
                    . ' selected_device=none reason=' . zram_collector_log_value($ownershipError);
            } elseif ($state['validatedDev'] === '') {
                $state['resolutionStatus'] = $state['cacheIsStale'] ? 'stale_cached_device' : 'missing_device';
                $state['resolutionDetails'] = 'cached_device=' . ($cachedDev === '' ? 'none' : zram_collector_log_value($cachedDev))
                    . ' selected_device=none reason=no plugin-labelled live ZRAM device was found';
            } else {
                $state['resolutionStatus'] = '';
                $state['resolutionDetails'] = '';
            }
            $state['lastCacheSignature'] = $cacheSignature;
            $state['deviceResolutionNext'] = ($nowSec ?? time()) + 60;
        }
        $cacheIsStale = $state['cacheIsStale'];

        $ourDev = $state['validatedDev'];
        $candidateDev = $ourDev !== '' ? $ourDev
            : (preg_match('/^zram\d+$/', $cachedDev) === 1 ? $cachedDev : '');

        if ($cacheIsStale && $ourDev !== '') {
            zram_collector_log_diagnostic(
                'device_cache',
                'stale_cached_device',
                'cached_device=' . ($cachedDev === '' ? 'empty' : zram_collector_log_value($cachedDev))
                    . " selected_device=$ourDev reason=cache does not identify the labelled live device",
                'WARN'
            );
        } elseif (!$cacheIsStale) {
            // Allow a future stale transition to be logged immediately, even if
            // an earlier stale cache was repaired less than five minutes ago.
            unset($zramCollectorDiagnosticStates['device_cache']);
        }

        $totalOriginal = 0;
        $totalCompressed = 0;
        $totalUsed = 0;
        $diskSize = 0;
        $currentTotalTicks = 0;
        $collectorStatus = '';
        $tier1SampleValid = false;

        if ($ourDev !== '') {
            // Collect stats for our device only.
            // DATA = uncompressed payload, COMPR = post-compression payload,
            // TOTAL = actual RAM occupied (COMPR + per-page metadata + slot rounding).
            // The chart's "Compressed" dataset wants COMPR; "Uncompressed" wants DATA.
            // TOTAL is captured for the aggregates JSON (memorySaved calc) only.
            if ($state['zramctlBinary'] === '') $state['zramctlBinary'] = zram_find_binary('zramctl');
            $zramctlBinary = $state['zramctlBinary'];
            $raw = [];
            $zramctlExit = 127;
            if ($zramctlBinary === '') {
                $raw = ['zramctl binary not found in /sbin or /usr/sbin'];
            } else {
                $command = escapeshellarg($zramctlBinary)
                    . ' --bytes --noheadings --raw --output NAME,DISKSIZE,DATA,COMPR,ALGORITHM,TOTAL '
                    . escapeshellarg(ZRAM_DEV_DIR . "/$ourDev") . ' 2>&1';
                zram_exec($command, $raw, $zramctlExit);
            }

            $swapStateError = '';
            $activeSwap = zram_collector_device_is_active_swap($ourDev, $swapStateError);
            $classification = zram_collector_classify_zramctl_result(
                $ourDev,
                $raw,
                $zramctlExit,
                $activeSwap,
                $swapStateError,
                $cacheExists && $cachedDev === $ourDev
            );
            $collectorStatus = $classification['status'];
            $diskSize = $classification['disk_size'];
            $totalOriginal = $classification['data'];
            $totalCompressed = $classification['compr'];
            $totalUsed = $classification['total'];
            $tier1SampleValid = $classification['valid'];
            zram_collector_log_diagnostic(
                'collection',
                $collectorStatus,
                $classification['details'],
                $tier1SampleValid ? 'INFO' : 'WARN',
                $tier1SampleValid ? 3600 : 300
            );

            if ($zramctlExit === 126 || $zramctlExit === 127 || ($zramctlBinary !== '' && !is_executable($zramctlBinary))) {
                // Re-run discovery next poll in case util-linux moved or became
                // available after the collector started.
                $state['zramctlBinary'] = '';
            }

            // IO ticks for our device
            $statFile = ZRAM_SYS_BLOCK . "/$ourDev/stat";
            if ($tier1SampleValid && file_exists($statFile)) {
                $stats = preg_split('/\s+/', trim((string) @file_get_contents($statFile)));
                if (is_array($stats) && count($stats) >= 8) {
                    $currentTotalTicks = intval($stats[3]) + intval($stats[7]);
                }
            }
        } elseif (($settings['enabled'] ?? 'yes') === 'no') {
            // Tier 2-only setups have no ZRAM device by design. Keep that poll
            // informational instead of warning about a "missing" device.
            $collectorStatus = 'tier1_disabled';
            $details = 'cached_device=' . ($cachedDev === '' ? 'none' : zram_collector_log_value($cachedDev))
                . ' selected_device=none reason=ZRAM tier is disabled in settings';
            zram_collector_log_diagnostic('collection', $collectorStatus, $details, 'INFO', 3600);
        } else {
            $collectorStatus = $state['resolutionStatus'] !== ''
                ? $state['resolutionStatus']
                : ($cacheIsStale ? 'stale_cached_device' : 'missing_device');
            $details = $state['resolutionDetails'] !== ''
                ? $state['resolutionDetails']
                : ($cacheIsStale
                    ? 'cached_device=' . ($cachedDev === '' ? 'empty' : zram_collector_log_value($cachedDev))
                        . ' selected_device=none reason=cached device is invalid or absent from sysfs and no labelled device was found'
                    : 'cached_device=none selected_device=none reason=no labelled or cached ZRAM device was found');
            zram_collector_log_diagnostic('collection', $collectorStatus, $details, 'WARN');
        }

        if (!$tier1SampleValid && $state['lastCollectorStatus'] !== '' && $collectorStatus !== $state['lastCollectorStatus']) {
            // Revalidate ownership on the next poll when a previously stable
            // collection path changes to a zramctl/inactive/device fault.
            $state['deviceResolutionNext'] = 0;
        }
        $state['lastCollectorStatus'] = $collectorStatus;

        // Calculate load
        $now = $nowMs ?? microtime(true) * 1000;
        $loadPct = 0;
        if ($tier1SampleValid && $state['lastTotalTicks'] !== null && $state['lastTime'] !== null) {
            $dt = $now - $state['lastTime'];
            if ($dt > 0) {
                $loadPct = max(0, (($currentTotalTicks - $state['lastTotalTicks']) / $dt) * 100);
            }
        }
        $state['lastTotalTicks'] = $tier1SampleValid ? $currentTotalTicks : null;
        $state['lastTime'] = $tier1SampleValid ? $now : null;

        // Sample Tier 2 (SSD swap) used bytes so the dashboard can plot
        // spillover historically. Sourced from `swapon --bytes` filtered to
        // our configured ssd_swap_path. 0 when unconfigured, inactive, or
        // unreadable — the schema treats absent === zero.
        // Backing-aware: loop-backed swap appears in swapon as /dev/loopN and
        // zvol-backed swap as the resolved /dev/zdN, not as the configured
        // path — resolve the name the kernel lists first.
        $ssdUsed = 0;
        $ssdPath = $settings['ssd_swap_path'] ?? '';
        if ($ssdPath !== '') {
            $swapTarget = zram_ssd_swap_target($settings['ssd_swap_backing'] ?? 'file', $ssdPath)['target'];
            $swapRows = [];
            zram_exec('swapon --bytes --noheadings --show=NAME,USED 2>/dev/null', $swapRows);
            foreach ($swapRows as $row) {
                $parts = preg_split('/\s+/', trim($row));
                if (is_array($parts) && count($parts) >= 2 && $parts[0] === $swapTarget) {
                    $ssdUsed = intval($parts[1]);
                    break;
                }
            }
        }

        // Append to history. Schema:
        //   t = timestamp (HH:MM:SS)
        //   o = original uncompressed payload bytes (DATA)
        //   c = compressed payload bytes (COMPR — algorithm output, what the chart shows)
        //   u = total RAM occupied bytes (COMPR + per-page metadata + slot rounding)
        //   l = CPU load %
        //   s = Tier 2 disk swap used bytes
        //
        // 'c' was added 2026.05.06.15 — fixes a long-standing bug where 'u' was
        // labelled "compressed" but actually held the post-overhead RAM cost,
        // making the chart's Compressed dataset > Uncompressed at small data
        // volumes. Older entries without 'c' fall back to 'u' on the dashboard
        // (graceful — values are within the per-page metadata band, ~few%).
        // A selected device whose read failed is not a zero sample. Leaving
        // history unchanged stops a command/device fault from drawing a
        // misleading flat ZRAM graph. With no ZRAM device at all (Tier 2-only,
        // or Tier 1 not active yet) zero ZRAM bytes is the true value, and the
        // Tier 2 's' series must keep advancing so the Disk chart stays live.
        $historySampleValid = $tier1SampleValid || $ourDev === '';
        if ($historySampleValid) {
            $state['history'][] = [
                't' => date('H:i:s'),
                'o' => $totalOriginal,
                'c' => $totalCompressed,
                'u' => $totalUsed,
                'l' => round($loadPct, 1),
                's' => $ssdUsed,
            ];
            // Keep the newest maxPoints. A file that is already too long (a lower
            // limit, or a hand-edited file) shrinks at once, not one point per tick.
            if (count($state['history']) > $state['maxPoints']) {
                $state['history'] = array_slice($state['history'], -$state['maxPoints']);
            }

            // Atomic-ish write (write to tmp then rename)
            $tmp = ZRAM_HISTORY_FILE . '.tmp';
            if (@file_put_contents($tmp, json_encode($state['history'])) !== false) {
                rename($tmp, ZRAM_HISTORY_FILE);
            }
        }

        zram_log(
            "Poll: status=$collectorStatus selected_device=" . ($candidateDev === '' ? 'none' : $candidateDev)
                . ' disksize=' . zram_collector_format_bytes($diskSize)
                . ' data=' . zram_collector_format_bytes($totalOriginal)
                . ' compr=' . zram_collector_format_bytes($totalCompressed)
                . ' total=' . zram_collector_format_bytes($totalUsed)
                . " load=" . round($loadPct, 1) . "%"
        );

        // Log rotation: truncate if > 1MB
        if (file_exists(ZRAM_DEBUG_LOG) && filesize(ZRAM_DEBUG_LOG) > 1048576) {
            zram_log("LOG ROTATED", 'INFO');
            file_put_contents(ZRAM_DEBUG_LOG, "[LOG ROTATED]\n");
        }

    } catch (Throwable $e) {
        @file_put_contents(ZRAM_DEBUG_LOG, date('[Y-m-d H:i:s] ') . "[ERROR] Collector: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

// PHPUnit loads the functions above without starting the infinite daemon loop.
if (defined('ZRAM_COLLECTOR_LIBRARY_ONLY') && ZRAM_COLLECTOR_LIBRARY_ONLY) return;

$zramCollectorState = zram_collector_state_init();
if (!zram_collector_claim_pid()) exit;

while (true) {
    zram_collector_tick($zramCollectorState);
    sleep($zramCollectorState['interval']);
}
