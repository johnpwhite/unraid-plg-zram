<?php
/**
 * PHPUnit bootstrap — redirect config paths to a writable temp dir so tests
 * don't touch /boot or /tmp/unraid-zram-card. The plugin code reads absolute
 * paths via defined constants; we define them FIRST so zram_config.php's
 * `define(...)` calls become no-ops.
 */

$tmp = sys_get_temp_dir() . '/zram-test-' . getmypid();
if (!is_dir($tmp)) mkdir($tmp, 0777, true);
register_shutdown_function(function() use ($tmp) {
    // Best-effort cleanup
    if (is_dir($tmp)) {
        array_map('unlink', glob("$tmp/*") ?: []);
        @rmdir($tmp);
    }
});

define('ZRAM_LABEL', 'ZRAM_CARD');
define('ZRAM_SSD_LABEL', 'ZRAM_CARD_SSD');
define('ZRAM_CONFIG_FILE', "$tmp/settings.ini");
define('ZRAM_LOG_DIR', $tmp);
define('ZRAM_DEBUG_LOG', "$tmp/debug.log");
define('ZRAM_CMD_LOG', "$tmp/cmd.log");
define('ZRAM_LOCK_FILE', "$tmp/config.lock");
define('ZRAM_DEVICE_FILE', "$tmp/device.conf");
define('ZRAM_HISTORY_FILE', "$tmp/history.json");
define('ZRAM_PID_FILE', "$tmp/collector.pid");

define('ZRAM_DEFAULTS', [
    'enabled'             => 'yes',
    'refresh_interval'    => '3000',
    'collection_interval' => '3',
    'swappiness'          => '100',
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

define('ZRAM_TEST_TMP', $tmp);
