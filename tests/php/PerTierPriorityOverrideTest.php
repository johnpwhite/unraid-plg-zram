<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guards for PER_TIER_PRIORITY_OVERRIDE.md (release 2026.05.06.08).
 *
 * The priority feature has a load-bearing safety property: ZRAM (Tier 1) must
 * always stay strictly greater than Disk (Tier 2). Inverting the values
 * silently destroys the entire two-tier design. These tests lock in the
 * gates that prevent that:
 *
 *   1. Config defaults define both keys with the safe values
 *   2. update_priorities action exists and rejects equal/inverted values
 *   3. The single-key update_setting action does NOT accept the priority keys
 *      (defence in depth — must go through the paired endpoint)
 *   4. zram_init.sh and zram_actions.php read the configured priority from
 *      cfg/$settings instead of using literals
 *   5. Page renders the configured values in the inline explainer text
 *      (so changing them flows through the UX immediately)
 *   6. Page contains the Advanced details panel + SAVE/RESET buttons
 */
final class PerTierPriorityOverrideTest extends TestCase
{
    private string $configSrc;
    private string $actionsSrc;
    private string $initSrc;
    private string $pageSrc;
    private string $jsSrc;

    protected function setUp(): void
    {
        $base = __DIR__ . '/../../src';
        $this->configSrc  = file_get_contents("$base/zram_config.php");
        $this->actionsSrc = file_get_contents("$base/zram_actions.php");
        $this->initSrc    = file_get_contents("$base/zram_init.sh");
        $this->pageSrc    = file_get_contents("$base/UnraidZramCard.page");
        $this->jsSrc      = file_get_contents("$base/js/zram-settings.js");
        $this->assertNotEmpty($this->configSrc);
        $this->assertNotEmpty($this->actionsSrc);
        $this->assertNotEmpty($this->initSrc);
        $this->assertNotEmpty($this->pageSrc);
        $this->assertNotEmpty($this->jsSrc);
    }

    public function testDefaultsIncludePriorityKeys(): void
    {
        $this->assertMatchesRegularExpression(
            "/'zram_priority'\s*=>\s*'100'/",
            $this->configSrc,
            'ZRAM_DEFAULTS must include zram_priority=100'
        );
        $this->assertMatchesRegularExpression(
            "/'ssd_swap_priority'\s*=>\s*'10'/",
            $this->configSrc,
            'ZRAM_DEFAULTS must include ssd_swap_priority=10'
        );
    }

    public function testUpdatePrioritiesActionExists(): void
    {
        $this->assertMatchesRegularExpression(
            "/if\s*\(\\\$action\s*===\s*'update_priorities'\)/",
            $this->actionsSrc,
            'zram_actions.php must define update_priorities action'
        );
        // Range validation
        $this->assertMatchesRegularExpression(
            "/'min_range'\s*=>\s*1,\s*'max_range'\s*=>\s*32767/",
            $this->actionsSrc,
            'Tier 1 priority must be range-checked 1-32767'
        );
        $this->assertMatchesRegularExpression(
            "/'min_range'\s*=>\s*0,\s*'max_range'\s*=>\s*32767/",
            $this->actionsSrc,
            'Tier 2 priority must be range-checked 0-32767'
        );
        // Strict-greater rule
        $this->assertMatchesRegularExpression(
            '/if\s*\(\$z\s*<=\s*\$s\)/',
            $this->actionsSrc,
            'update_priorities must reject zram <= ssd to preserve tier ordering'
        );
    }

    public function testSingleKeyEndpointExcludesPriorityKeys(): void
    {
        // The single-key update_setting whitelist must NOT include priorities.
        // Otherwise an attacker (or a confused JS path) could bypass the
        // comparison rule by saving zram_priority=1 alone, then ssd_swap_priority=2.
        // Locate the whitelist array literal and assert the priority keys are absent from it.
        $pos = strpos($this->actionsSrc, "if (\$action === 'update_setting')");
        $this->assertNotEquals(false, $pos, 'update_setting handler must exist');
        $window = substr($this->actionsSrc, $pos, 800);
        $this->assertMatchesRegularExpression(
            "/\\\$allowed\s*=\s*\[[^\]]*\]/s",
            $window,
            'update_setting must declare a $allowed whitelist'
        );
        // Extract the whitelist array literal
        preg_match('/\$allowed\s*=\s*\[([^\]]*)\]/s', $window, $m);
        $this->assertNotEmpty($m, 'whitelist must be parseable');
        $whitelist = $m[1];
        $this->assertStringNotContainsString(
            "'zram_priority'",
            $whitelist,
            'update_setting whitelist must NOT include zram_priority — it has to go through update_priorities so the comparison rule is enforced'
        );
        $this->assertStringNotContainsString(
            "'ssd_swap_priority'",
            $whitelist,
            'update_setting whitelist must NOT include ssd_swap_priority for the same reason'
        );
    }

    public function testCreatePathsUseConfiguredPriority(): void
    {
        // create_zram must read zram_priority from config, not hard-code 100
        $this->assertStringContainsString(
            "'zram_priority'] ?? 100",
            $this->actionsSrc,
            'create_zram must read zram_priority from config (with 100 as fallback)'
        );
        $this->assertStringNotContainsString(
            'swapon " . escapeshellarg($dev) . " -p 100',
            $this->actionsSrc,
            'create_zram must not hard-code priority 100 in the swapon call anymore'
        );
        // create_disk_swap likewise
        $this->assertStringContainsString(
            "'ssd_swap_priority'] ?? 10",
            $this->actionsSrc,
            'create_disk_swap must read ssd_swap_priority from config (with 10 as fallback)'
        );
        $this->assertStringNotContainsString(
            'swapon " . escapeshellarg($swapFile) . " -p 10',
            $this->actionsSrc,
            'create_disk_swap must not hard-code priority 10 in the swapon call anymore'
        );
    }

    public function testInitScriptReadsConfiguredPriority(): void
    {
        // zram_init.sh must source priority from config, not hard-code -p 100 / -p 10
        $this->assertMatchesRegularExpression(
            '/cfg_val\s+"zram_priority"/',
            $this->initSrc,
            'init.sh must read zram_priority via cfg_val'
        );
        $this->assertMatchesRegularExpression(
            '/cfg_val\s+"ssd_swap_priority"/',
            $this->initSrc,
            'init.sh must read ssd_swap_priority via cfg_val'
        );
        $this->assertMatchesRegularExpression(
            '/SWAPON.*"\$DEV"\s+-p\s+"\$ZRAM_PRIO"/',
            $this->initSrc,
            'init.sh must use $ZRAM_PRIO variable instead of literal 100 on the ZRAM swapon'
        );
        $this->assertMatchesRegularExpression(
            '/SWAPON.*"\$SSD_PATH"\s+-p\s+"\$SSD_PRIO"/',
            $this->initSrc,
            'init.sh must use $SSD_PRIO variable instead of literal 10 on the disk swapon'
        );
    }

    public function testInlineExplainersUseConfiguredValues(): void
    {
        // Tier 2 status row must echo the configured priority instead of "10"
        $this->assertMatchesRegularExpression(
            "/priority\s+<\?php\s+echo\s+intval\(\\\$settings\['ssd_swap_priority'\]/",
            $this->pageSrc,
            'Tier 2 priority text must render configured ssd_swap_priority'
        );
        // Swappiness clarifier line should also reflect both configured values
        $this->assertMatchesRegularExpression(
            "/Tier 1 = <\?php\s+echo\s+intval\(\\\$settings\['zram_priority'\]/",
            $this->pageSrc,
            'swappiness clarifier must render configured zram_priority'
        );
        // No remaining hardcoded "priority 10 — overflow only" with literal 10
        $this->assertStringNotContainsString(
            'priority 10 &mdash; overflow only',
            $this->pageSrc,
            'no remaining hardcoded "priority 10 — overflow only" — must use the configured value'
        );
    }

    public function testAdvancedPanelPresentInPage(): void
    {
        $this->assertStringContainsString(
            'class="zram-advanced"',
            $this->pageSrc,
            'page must contain the Advanced details panel'
        );
        $this->assertStringContainsString(
            'id="zram-advanced-priorities"',
            $this->pageSrc,
            'Advanced details element must have a stable id for JS to wire toggle handler'
        );
        $this->assertStringContainsString(
            'id="zram_priority_input"',
            $this->pageSrc,
            'Tier 1 priority input must be present'
        );
        $this->assertStringContainsString(
            'id="ssd_priority_input"',
            $this->pageSrc,
            'Tier 2 priority input must be present'
        );
        $this->assertStringContainsString(
            'id="btn-save-priorities"',
            $this->pageSrc,
            'SAVE button must be present with stable id'
        );
        $this->assertStringContainsString(
            'RESET TO DEFAULTS',
            $this->pageSrc,
            'RESET TO DEFAULTS button must be present'
        );
        // Inputs must start disabled (locked until user acknowledges the warning).
        // The input tag embeds an inline PHP block whose closing delimiter
        // contains a literal greater-than character, so a [^>]* match stops
        // early. Use [\s\S] to span across that boundary.
        $this->assertMatchesRegularExpression(
            '/id="zram_priority_input"[\s\S]*?disabled\s*>/',
            $this->pageSrc,
            'Tier 1 input must start disabled'
        );
        $this->assertMatchesRegularExpression(
            '/id="ssd_priority_input"[\s\S]*?disabled\s*>/',
            $this->pageSrc,
            'Tier 2 input must start disabled'
        );
    }

    public function testJsHasPriorityHandlers(): void
    {
        foreach (['savePriorities', 'resetPriorities', 'unlockPriorityInputs', 'bootstrapPriorityOverride'] as $fn) {
            $this->assertMatchesRegularExpression(
                "/function\s+$fn\s*\(/",
                $this->jsSrc,
                "JS must define $fn"
            );
        }
        // Client-side guard mirrors the server-side comparison rule
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*z\s*<=\s*s\s*\)/',
            $this->jsSrc,
            'JS savePriorities must reject zram <= ssd before sending the request'
        );
        // Toggle handler unlocks inputs only after swal acknowledgement
        $this->assertStringContainsString(
            'priorityUnlocked',
            $this->jsSrc,
            'JS must track an unlock flag so the swal warning is shown the first time'
        );
    }
}
