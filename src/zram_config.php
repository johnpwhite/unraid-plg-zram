<?php
/**
 * <module_context>
 *   <name>zram_config</name>
 *   <description>Shared configuration, logging, device filtering, and label constants for ZRAM Card plugin</description>
 *   <dependencies>None (foundation module)</dependencies>
 *   <consumers>zram_actions, zram_status, zram_collector, ZramCard, UnraidZramCard.page</consumers>
 * </module_context>
 */

defined('ZRAM_LABEL')        || define('ZRAM_LABEL', 'ZRAM_CARD');
defined('ZRAM_SSD_LABEL')    || define('ZRAM_SSD_LABEL', 'ZRAM_CARD_DISK');
defined('ZRAM_LEGACY_SSD_LABEL') || define('ZRAM_LEGACY_SSD_LABEL', 'ZRAM_CARD_SSD');
defined('ZRAM_CONFIG_FILE')  || define('ZRAM_CONFIG_FILE', '/boot/config/plugins/unraid-zram-card/settings.ini');
defined('ZRAM_LOG_DIR')      || define('ZRAM_LOG_DIR', '/tmp/unraid-zram-card');
defined('ZRAM_DEBUG_LOG')    || define('ZRAM_DEBUG_LOG', ZRAM_LOG_DIR . '/debug.log');
defined('ZRAM_CMD_LOG')      || define('ZRAM_CMD_LOG', ZRAM_LOG_DIR . '/cmd.log');
defined('ZRAM_LOCK_FILE')    || define('ZRAM_LOCK_FILE', ZRAM_LOG_DIR . '/config.lock');
defined('ZRAM_DEVICE_FILE')  || define('ZRAM_DEVICE_FILE', ZRAM_LOG_DIR . '/device.conf');
defined('ZRAM_HISTORY_FILE') || define('ZRAM_HISTORY_FILE', ZRAM_LOG_DIR . '/history.json');
defined('ZRAM_PID_FILE')     || define('ZRAM_PID_FILE', ZRAM_LOG_DIR . '/collector.pid');

defined('ZRAM_DEFAULTS') || define('ZRAM_DEFAULTS', [
    'enabled'             => 'yes',
    'refresh_interval'    => '3000',
    'collection_interval' => '3',
    'swappiness'          => '150',
    'debug'               => 'no',
    'console_visible'     => 'yes',
    'zram_size'           => 'auto',
    'zram_percent'        => '50',
    'zram_algo'           => 'zstd',
    'ssd_swap_enabled'    => 'no',
    'ssd_swap_path'       => '',
    'ssd_swap_size'       => '16G',
    'ssd_swap_mount'      => '',
    'zram_priority'       => '100',
    'ssd_swap_priority'   => '10',
    'ssd_swap_backing'    => 'auto',
    'ssd_swap_allow_zfs'  => 'no',
    'oom_protect_enabled' => 'no',
    'oom_levels'          => '',
    'oom_default_level'   => 'normal',
    'oom_proc_patterns'   => '',
    'oom_auto_deps'       => '',
    'oom_oom_group'       => 'yes',
    'vm_memory_min'       => 'no',
]);

if (!is_dir(ZRAM_LOG_DIR)) @mkdir(ZRAM_LOG_DIR, 0777, true);

/** Read config merged with defaults. Never returns false. */
function zram_config_read(): array {
    $loaded = @parse_ini_file(ZRAM_CONFIG_FILE);
    return is_array($loaded) ? array_merge(ZRAM_DEFAULTS, $loaded) : ZRAM_DEFAULTS;
}

/** Atomic config write with flock. Merges $updates into current config. */
function zram_config_write(array $updates): bool {
    if (!is_dir(dirname(ZRAM_CONFIG_FILE))) {
        @mkdir(dirname(ZRAM_CONFIG_FILE), 0777, true);
    }
    $fp = fopen(ZRAM_LOCK_FILE, 'c');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if ($fp) fclose($fp);
        return false;
    }
    $current = @parse_ini_file(ZRAM_CONFIG_FILE);
    $merged = array_merge(ZRAM_DEFAULTS, is_array($current) ? $current : [], $updates);
    $lines = [];
    foreach ($merged as $k => $v) $lines[] = "$k=\"$v\"";
    $ok = file_put_contents(ZRAM_CONFIG_FILE, implode("\n", $lines) . "\n") !== false;
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

/** Cached debug flag to avoid flash reads on every log call. */
$_zram_debug_cached = null;

function zram_log(string $msg, string $level = 'DEBUG'): void {
    global $_zram_debug_cached;
    $level = strtoupper($level);
    if ($level === 'DEBUG') {
        if ($_zram_debug_cached === null) {
            $loaded = @parse_ini_file(ZRAM_CONFIG_FILE);
            $_zram_debug_cached = ($loaded['debug'] ?? 'no') === 'yes';
        }
        if (!$_zram_debug_cached) return;
    }
    $logMsg = date('[Y-m-d H:i:s] ') . "[$level] $msg\n";
    @file_put_contents(ZRAM_DEBUG_LOG, $logMsg, FILE_APPEND);
}

/** Reset cached debug flag (call after config change). */
function zram_debug_reset(): void {
    global $_zram_debug_cached;
    $_zram_debug_cached = null;
}

/** Append to command log (JSON-lines format). */
function zram_cmd_log(string $msg, string $type = ''): void {
    // 'time' (H:i:s) is the display value; 'dt' (full Y-m-d H:i:s) is the sort
    // key so the activity feed orders correctly across midnight — without the
    // date, yesterday's 23:xx sorts after today's 09:xx. See UNIFIED_ACTIVITY_LOG.md.
    $entry = ['time' => date('H:i:s'), 'dt' => date('Y-m-d H:i:s'), 'msg' => $msg, 'type' => $type];
    @file_put_contents(ZRAM_CMD_LOG, json_encode($entry) . "\n", FILE_APPEND);
}

/** Run a shell command, log it, return exit code. */
function zram_run(string $cmd, array &$logs): int {
    exec($cmd . " 2>&1", $out, $ret);
    $output = implode(" ", $out);
    $logs[] = ['cmd' => $cmd, 'output' => $output, 'status' => $ret];
    zram_log("CMD: $cmd | Status: $ret | Output: $output", 'INFO');
    $status = $ret === 0 ? 'Success' : 'Fail';
    zram_cmd_log("$cmd -> $status", $ret === 0 ? '' : 'err');
    if ($output) zram_cmd_log("  > $output", 'debug');
    return $ret;
}

/**
 * Get our labeled ZRAM device name (e.g., "zram1") or empty string.
 * Checks blkid for ZRAM_CARD label, falls back to device.conf cache.
 */
function zram_get_our_device(): string {
    // Primary: probe directly, bypassing /run/blkid/blkid.tab via -c /dev/null.
    // Without it, a freshly reset zram device can keep returning matches from
    // the cache because zramctl --reset fires no udev change event — that stale
    // window is what made the REMOVE button "stick" across reloads.
    exec('blkid -c /dev/null -t LABEL=' . escapeshellarg(ZRAM_LABEL) . ' -o device 2>/dev/null', $out);
    foreach ($out as $line) {
        $dev = trim($line);
        if (strpos($dev, '/dev/zram') === 0) {
            return basename($dev);
        }
    }
    // Fallback: cached device file (written at creation time)
    if (file_exists(ZRAM_DEVICE_FILE)) {
        $cached = trim(@file_get_contents(ZRAM_DEVICE_FILE));
        if ($cached && file_exists("/sys/block/$cached")) {
            return $cached;
        }
    }
    return '';
}

/**
 * Get our SSD swap file path if active, or empty string.
 */
function zram_get_ssd_swap(): string {
    $cfg = zram_config_read();
    $path    = $cfg['ssd_swap_path'] ?? '';
    $backing = $cfg['ssd_swap_backing'] ?? 'file';
    if ($path && file_exists($path)) {
        $swaps = @file_get_contents('/proc/swaps') ?: '';
        if ($backing === 'loop') {
            $ljOut = [];
            exec("losetup -j " . escapeshellarg($path) . " 2>/dev/null", $ljOut);
            $loopDev = '';
            foreach ($ljOut as $line) {
                if (preg_match('#^(/dev/loop\d+):#', $line, $m)) { $loopDev = $m[1]; break; }
            }
            if ($loopDev !== '' && preg_match('/^' . preg_quote($loopDev, '/') . '\s/m', $swaps) === 1) {
                return $path;
            }
        } else {
            if (strpos($swaps, $path) !== false) {
                return $path;
            }
        }
    }
    return '';
}

/**
 * Re-activate the Tier 2 disk swap file if it's configured-enabled, present on
 * disk, but not currently in /proc/swaps. Returns true iff it took action
 * (success or attempted). $nextTry is an in/out epoch-seconds back-off gate —
 * set to now+60 after a swapon failure so a genuinely-unswappable file is not
 * retried on every collector tick; reset to 0 on success.
 *
 * Covers the state zram_init.sh's boot-retry poller (60 x 5s) can leave behind
 * when the swap file's mount comes up later than 5 minutes after plugin start
 * — a long array outage, a USB-stick replacement, parity-check-then-mount, etc.
 * Mirrors zram_init.sh's activate_disk_swap(). Called once per collector loop
 * iteration. See docs/specs/TIER2_RECOVERY.md.
 *
 * @param array $cachedCfg The collector's cached config (may be ~60s stale).
 * @param int   $nextTry   In/out: epoch seconds before which we won't retry.
 */
function zram_reactivate_disk_swap_if_needed(array $cachedCfg, int &$nextTry): bool {
    // Cheap pre-checks against the cached config — no flash I/O, no fork.
    if (($cachedCfg['ssd_swap_enabled'] ?? 'no') !== 'yes') return false;
    $path = $cachedCfg['ssd_swap_path'] ?? '';
    if ($path === '' || !file_exists($path)) return false;

    $backing = $cachedCfg['ssd_swap_backing'] ?? 'file';

    // For loop-backed swap, /proc/swaps lists /dev/loopN — not the image path.
    if ($backing === 'loop') {
        exec("losetup -j " . escapeshellarg($path) . " 2>/dev/null", $ljOut);
        $loopDev = '';
        foreach ($ljOut as $line) {
            if (preg_match('#^(/dev/loop\d+):#', $line, $m)) { $loopDev = $m[1]; break; }
        }
        if ($loopDev !== '') {
            $swaps = @file_get_contents('/proc/swaps') ?: '';
            if (preg_match('/^' . preg_quote($loopDev, '/') . '\s/m', $swaps) === 1) return false; // already active
        }
    } else {
        $swaps = @file_get_contents('/proc/swaps') ?: '';
        if (strpos($swaps, $path) !== false) return false;     // already active
    }

    $now = time();
    if ($now < $nextTry) return false;                         // backing off after recent failure

    // Re-read config fresh before acting (stale cache could undo a user REMOVE).
    $fresh = @parse_ini_file(ZRAM_CONFIG_FILE) ?: [];
    if (($fresh['ssd_swap_enabled'] ?? 'no') !== 'yes') return false;
    $path    = $fresh['ssd_swap_path'] ?? $path;
    $backing = $fresh['ssd_swap_backing'] ?? 'file';
    if ($path === '' || !file_exists($path)) return false;

    $logs = [];
    $prio = max(0, min(32767, intval($fresh['ssd_swap_priority'] ?? 10)));

    if ($backing === 'loop') {
        // Resolve or attach loop device
        $ljOut2 = [];
        exec("losetup -j " . escapeshellarg($path) . " 2>/dev/null", $ljOut2);
        $loopDev = '';
        foreach ($ljOut2 as $line) {
            if (preg_match('#^(/dev/loop\d+):#', $line, $m)) { $loopDev = $m[1]; break; }
        }
        if ($loopDev === '') {
            exec('modprobe loop 2>/dev/null');
            $lfOut = [];
            exec("losetup -f --show " . escapeshellarg($path) . " 2>/dev/null", $lfOut);
            $loopDev = trim(end($lfOut) ?: '');
        }
        if (!preg_match('#^/dev/loop\d+$#', $loopDev)) {
            zram_log("Tier 2 self-heal: losetup failed for $path — backing off 60s", 'WARN');
            $nextTry = $now + 60;
            return true;
        }
        // Check again in case it got activated between the pre-check and now
        $swaps = @file_get_contents('/proc/swaps') ?: '';
        if (preg_match('/^' . preg_quote($loopDev, '/') . '\s/m', $swaps) === 1) return false;

        if (zram_run('swapon ' . escapeshellarg($loopDev) . ' -p ' . $prio, $logs) === 0) {
            zram_log("Tier 2 self-heal: re-activated $path via $loopDev (priority $prio) after it was found inactive", 'INFO');
            zram_cmd_log("Auto-reactivated disk swap file $path", 'cmd');
            $nextTry = 0;
        } else {
            zram_log("Tier 2 self-heal: swapon $loopDev failed — backing off 60s", 'WARN');
            $nextTry = $now + 60;
        }
        return true;
    }

    // --- Direct-file path ---
    // Normalise the label (idempotent, best-effort) then bring the swap online.
    zram_run('swaplabel -L ' . escapeshellarg(ZRAM_SSD_LABEL) . ' ' . escapeshellarg($path), $logs);
    if (zram_run('swapon ' . escapeshellarg($path) . ' -p ' . $prio, $logs) === 0) {
        zram_log("Tier 2 self-heal: re-activated $path (priority $prio) after it was found inactive", 'INFO');
        zram_cmd_log("Auto-reactivated disk swap file $path", 'cmd');
        $nextTry = 0;
    } else {
        zram_log("Tier 2 self-heal: swapon $path failed — backing off 60s", 'WARN');
        $nextTry = $now + 60;
    }
    return true;
}

/** Check if evacuation is safe before swapoff. */
function zram_evacuation_safe(string $target, array &$logs): array {
    $zram_data = 0;
    exec("zramctl --bytes --noheadings --raw --output NAME,DATA 2>/dev/null", $z_out);
    foreach ($z_out as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) < 2) continue;
        $name = basename($p[0]);
        if (empty($target) || $name === basename($target) || "/dev/$name" === $target) {
            $zram_data += intval($p[1]);
        }
    }
    $mem = @file_get_contents('/proc/meminfo') ?: '';
    preg_match('/MemAvailable:\s+(\d+)/', $mem, $m);
    $avail = intval($m[1] ?? 0) * 1024;

    $other_swap = 0;
    exec("swapon --bytes --noheadings --show=NAME,SIZE,USED 2>/dev/null", $s_out);
    foreach ($s_out as $line) {
        $p = preg_split('/\s+/', trim($line));
        if (count($p) >= 3 && strpos($p[0], 'zram') === false) {
            $other_swap += intval($p[1]) - intval($p[2]);
        }
    }

    $capacity = $avail + $other_swap;
    $buffer = 104857600; // 100MB
    $logs[] = "Safety: ZRAM data=" . round($zram_data/1048576) . "MB, capacity=" . round($capacity/1048576) . "MB";

    if ($zram_data > ($capacity - $buffer)) {
        return ['safe' => false, 'error' => "Not enough memory to safely remove swap. " .
            round($zram_data/1048576) . "MB in swap, only " . round($avail/1048576) . "MB available RAM."];
    }
    return ['safe' => true];
}
