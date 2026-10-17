<?php
/**
 * ARCHIVED — superseded by zram_actions.php + zram_status.php.
 * Original content preserved in git history.
 * Do not add back to the PLG installer or reference from any module.
 * It stays on purpose: an old URL gets "410 Gone".
 */
require_once dirname(__FILE__) . '/zram_config.php';
http_response_code(410);
zram_exit();
