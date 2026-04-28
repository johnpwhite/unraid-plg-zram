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
defined('ZRAM_SSD_LABEL')    || define('ZRAM_SSD_LABEL', 'ZRAM_CARD_SSD');
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
    $entry = ['time' => date('H:i:s'), 'msg' => $msg, 'type' => $type];
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
    // Primary: check blkid for our label
    exec('blkid -t LABEL=' . escapeshellarg(ZRAM_LABEL) . ' -o device 2>/dev/null', $out);
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
    $path = $cfg['ssd_swap_path'] ?? '';
    if ($path && file_exists($path)) {
        // Check if active in /proc/swaps
        $swaps = @file_get_contents('/proc/swaps') ?: '';
        if (strpos($swaps, $path) !== false) {
            return $path;
        }
    }
    return '';
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
