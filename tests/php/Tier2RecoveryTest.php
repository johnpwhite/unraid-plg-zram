<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guards for TIER2_RECOVERY.md (OpenProject #749).
 *
 * Tier 2 could land in "File exists, not active" with no UI recovery path:
 * zram_init.sh's boot-retry poller (60 x 5s) gives up if the mount appears
 * later than 5 minutes (long array outage / USB-stick replacement), and the
 * settings card only shows CREATE (when no file) or REMOVE (when active) —
 * never an "activate the existing file" affordance.
 *
 * Fix: (1) an ACTIVATE button + activate_disk_swap action; (2) collector
 * self-heal that re-activates Tier 2 on its next tick, re-reading config
 * fresh so it can't undo a user REMOVE, with a 60s back-off after failure.
 * The init.sh boot poller is retained unchanged as the fast path.
 */
final class Tier2RecoveryTest extends TestCase
{
    private string $actionsSrc;
    private string $configSrc;
    private string $collectorSrc;
    private string $pageSrc;
    private string $settingsJs;

    protected function setUp(): void
    {
        $base = __DIR__ . '/../../src';
        $this->actionsSrc   = file_get_contents("$base/zram_actions.php");
        $this->configSrc    = file_get_contents("$base/zram_config.php");
        $this->collectorSrc = file_get_contents("$base/zram_collector.php");
        $this->pageSrc      = file_get_contents("$base/UnraidZramCard.page");
        $this->settingsJs   = file_get_contents("$base/js/zram-settings.js");
        foreach ([$this->actionsSrc, $this->configSrc, $this->collectorSrc, $this->pageSrc, $this->settingsJs] as $s) {
            $this->assertNotEmpty($s);
        }
    }

    // --- ACTIVATE action handler ---------------------------------------------

    public function testActivateActionHandlerExists(): void
    {
        $this->assertMatchesRegularExpression(
            "/\\\$action\s*===\s*'activate_disk_swap'\s*\|\|\s*\\\$action\s*===\s*'activate_ssd_swap'/",
            $this->actionsSrc,
            "zram_actions.php must handle 'activate_disk_swap' (with 'activate_ssd_swap' legacy alias, mirroring create/remove)"
        );
    }

    public function testActivateActionRunsSwaponWithPriority(): void
    {
        $this->assertMatchesRegularExpression(
            '/zram_run\(\s*[\'"]swapon [\'"]\s*\.\s*escapeshellarg\(\$swapFile\)\s*\.\s*[\'"] -p [\'"]\s*\.\s*\$prio/',
            $this->actionsSrc,
            'activate handler must swapon the existing file at the configured priority via zram_run + escapeshellarg'
        );
        $this->assertMatchesRegularExpression(
            '/\$prio\s*=\s*max\(0,\s*min\(32767,\s*intval\(\$cfg\[[\'"]ssd_swap_priority[\'"]\]/',
            $this->actionsSrc,
            'activate handler must clamp priority to 0..32767'
        );
    }

    public function testActivateActionIsIdempotent(): void
    {
        // When already present in /proc/swaps, return success WITHOUT re-running swapon.
        $this->assertMatchesRegularExpression(
            "/activate_disk_swap[\s\S]+?\/proc\/swaps[\s\S]+?strpos\(\\\$swaps,\s*\\\$swapFile\)\s*!==\s*false[\s\S]+?[\'\"]success[\'\"]\s*=>\s*true/",
            $this->actionsSrc,
            'activate handler must short-circuit to success when the file is already active in /proc/swaps'
        );
    }

    public function testActivateActionReassertsEnabledFlag(): void
    {
        $this->assertMatchesRegularExpression(
            "/activate_disk_swap[\s\S]+?zram_config_write\(\[[\'\"]ssd_swap_enabled[\'\"]\s*=>\s*[\'\"]yes[\'\"]\]\)/",
            $this->actionsSrc,
            'activate handler must persist ssd_swap_enabled=yes (config may have been toggled off)'
        );
    }

    public function testActivateActionNormalisesLabel(): void
    {
        // Mirrors zram_init.sh activate_disk_swap()'s legacy-label migration.
        // Implemented as an unconditional (idempotent) relabel to the canonical
        // ZRAM_SSD_LABEL via zram_run — cheaper than reading the current label
        // and re-labelling to the same value is a no-op.
        $this->assertMatchesRegularExpression(
            '/activate_disk_swap[\s\S]+?swaplabel -L[\s\S]+?escapeshellarg\(ZRAM_SSD_LABEL\)/',
            $this->actionsSrc,
            'activate handler must normalise the on-disk swap label to ZRAM_SSD_LABEL (legacy-label migration parity with zram_init.sh)'
        );
    }

    public function testActivateActionRejectsMissingFile(): void
    {
        $this->assertMatchesRegularExpression(
            "/activate_disk_swap[\s\S]+?(empty\(\\\$swapFile\)|!\s*file_exists\(\\\$swapFile\))[\s\S]+?[\'\"]success[\'\"]\s*=>\s*false/",
            $this->actionsSrc,
            'activate handler must fail cleanly when there is no swap file to activate'
        );
    }

    // --- ACTIVATE button (settings page) -------------------------------------

    public function testActivateButtonRenderedWhenInactiveFileExists(): void
    {
        $this->assertMatchesRegularExpression(
            '/elseif\s*\(\s*\$ssdPath\s*&&\s*file_exists\(\$ssdPath\)\s*\)\s*:[\s\S]+?zramAction\([\'"]activate_disk_swap[\'"]\)/',
            $this->pageSrc,
            'Tier 2 card must render an ACTIVATE button (zramAction(activate_disk_swap)) in an elseif gated on file_exists($ssdPath)'
        );
    }

    public function testActivateButtonNotShownWhenActive(): void
    {
        // The ACTIVATE branch must come AFTER `if ($ssdActive):` (REMOVE), i.e. it
        // is the not-active-but-file-exists case — never rendered when active.
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*\\\$ssdActive\s*\)\s*:[\s\S]+?remove_disk_swap[\s\S]+?elseif\s*\(\s*\\\$ssdPath\s*&&\s*file_exists\(\\\$ssdPath\)\s*\)\s*:[\s\S]+?activate_disk_swap/",
            $this->pageSrc,
            'ACTIVATE button must be the elseif after the $ssdActive (REMOVE) branch'
        );
    }

    public function testJsHasFriendlyLabelForActivate(): void
    {
        $this->assertMatchesRegularExpression(
            '/ZRAM_ACTION_LABELS\s*=\s*\{[\s\S]+?activate_disk_swap\s*:/',
            $this->settingsJs,
            'zram-settings.js ZRAM_ACTION_LABELS must include activate_disk_swap for the action toast'
        );
    }

    // --- Collector self-heal -------------------------------------------------

    public function testSelfHealFunctionDefined(): void
    {
        $this->assertMatchesRegularExpression(
            '/function\s+zram_reactivate_disk_swap_if_needed\s*\(\s*array\s+\$cachedCfg\s*,\s*int\s*&\s*\$nextTry\s*\)\s*:\s*bool/',
            $this->configSrc,
            'zram_config.php must define zram_reactivate_disk_swap_if_needed(array, int&): bool'
        );
    }

    public function testSelfHealRespectsEnabledFlag(): void
    {
        // Cached pre-check AND the fresh re-read must both gate on ssd_swap_enabled === 'yes'.
        $this->assertGreaterThanOrEqual(
            2,
            preg_match_all(
                "/\[[\'\"]ssd_swap_enabled[\'\"]\]\s*\?\?\s*[\'\"]no[\'\"]\)\s*!==\s*[\'\"]yes[\'\"]/",
                $this->configSrc
            ),
            'self-heal must early-return unless ssd_swap_enabled===yes, checked against both the cached config and a fresh re-read'
        );
    }

    public function testSelfHealReReadsFreshConfig(): void
    {
        $this->assertMatchesRegularExpression(
            '/zram_reactivate_disk_swap_if_needed[\s\S]+?parse_ini_file\(\s*ZRAM_CONFIG_FILE\s*\)/',
            $this->configSrc,
            'self-heal must re-read config fresh (parse_ini_file) before acting, so a user REMOVE in the ~60s stale window is not undone'
        );
    }

    public function testSelfHealSkipsWhenAlreadyActive(): void
    {
        $this->assertMatchesRegularExpression(
            '/zram_reactivate_disk_swap_if_needed[\s\S]+?\/proc\/swaps[\s\S]+?return\s+false/',
            $this->configSrc,
            'self-heal must bail when the path is already present in /proc/swaps'
        );
    }

    public function testSelfHealBacksOffOnFailure(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$nextTry\s*=\s*(time\(\)|\$now)\s*\+\s*60/',
            $this->configSrc,
            'self-heal must set a 60s back-off (nextTry = now + 60) after a swapon failure'
        );
        $this->assertMatchesRegularExpression(
            '/\$nextTry\s*=\s*0/',
            $this->configSrc,
            'self-heal must clear the back-off (nextTry = 0) on success'
        );
    }

    public function testSelfHealLogsOutcome(): void
    {
        $this->assertMatchesRegularExpression(
            "/zram_log\([\'\"]Tier 2 self-heal:[^\'\"]*re-activated[^\'\"]*[\'\"]\s*,\s*[\'\"]INFO[\'\"]\)/",
            $this->configSrc,
            'self-heal must log an INFO line on successful re-activation'
        );
        $this->assertMatchesRegularExpression(
            "/zram_log\([\'\"]Tier 2 self-heal:[^\'\"]*[\'\"]\s*,\s*[\'\"]WARN[\'\"]\)/",
            $this->configSrc,
            'self-heal must log a WARN line on swapon failure'
        );
        $this->assertMatchesRegularExpression(
            "/zram_cmd_log\([\'\"]Auto-reactivated disk swap file/",
            $this->configSrc,
            'self-heal must write an Activity-feed (cmd.log) entry on success so the user sees it happened'
        );
    }

    public function testCollectorCallsSelfHeal(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$selfHealNextTry\s*=\s*0\s*;/',
            $this->collectorSrc,
            'collector must declare the $selfHealNextTry back-off accumulator before the loop'
        );
        $this->assertMatchesRegularExpression(
            '/while\s*\(\s*true\s*\)[\s\S]+?zram_reactivate_disk_swap_if_needed\(\s*\$settings\s*,\s*\$selfHealNextTry\s*\)/',
            $this->collectorSrc,
            'collector loop must call zram_reactivate_disk_swap_if_needed($settings, $selfHealNextTry) each iteration'
        );
    }

    // --- The boot poller stays put ------------------------------------------

    public function testBootPollerUnchanged(): void
    {
        $initSrc = file_get_contents(__DIR__ . '/../../src/zram_init.sh');
        $this->assertStringContainsString(
            'scheduling background retry',
            $initSrc,
            'zram_init.sh boot-retry poller must remain — the collector self-heal complements it, does not replace it'
        );
    }
}
