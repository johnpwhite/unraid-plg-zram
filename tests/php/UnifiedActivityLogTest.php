<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guards for UNIFIED_ACTIVITY_LOG.md (release 2026.05.06.07).
 *
 * Two surfaces locked in by these tests:
 *
 *   1. The PHP view_activity / clear_activity action handlers exist with the
 *      right merge semantics — cmd.log and debug.log normalised into one
 *      timeline, "CMD: ..." dupes from debug.log dropped, capped at 500.
 *   2. The page renders filter chips + a single #activity-log div (no more
 *      tabs, no more separate console-log / debug-log-view divs), and the JS
 *      has the matching fetchActivity / setActivityFilter / clearActivity /
 *      buildActivityRow shape.
 */
final class UnifiedActivityLogTest extends TestCase
{
    private string $actionsSrc;
    private string $pageSrc;
    private string $jsSrc;

    protected function setUp(): void
    {
        $base = __DIR__ . '/../../src';
        $this->actionsSrc = file_get_contents("$base/zram_actions.php");
        $this->pageSrc    = file_get_contents("$base/UnraidZramCard.page");
        $this->jsSrc      = file_get_contents("$base/js/zram-settings.js");
        $this->assertNotEmpty($this->actionsSrc);
        $this->assertNotEmpty($this->pageSrc);
        $this->assertNotEmpty($this->jsSrc);
    }

    public function testViewActivityActionExists(): void
    {
        $this->assertMatchesRegularExpression(
            "/if\s*\(\\\$action\s*===\s*'view_activity'\)/",
            $this->actionsSrc,
            'zram_actions.php must define a view_activity action handler'
        );
        // Both source files must be read
        $this->assertStringContainsString(
            'ZRAM_CMD_LOG',
            $this->actionsSrc,
            'view_activity must read cmd.log'
        );
        $this->assertStringContainsString(
            'ZRAM_DEBUG_LOG',
            $this->actionsSrc,
            'view_activity must read debug.log'
        );
        // Must dedup CMD: lines from debug.log
        $this->assertMatchesRegularExpression(
            "/strpos\(\\\$msg,\s*'CMD:\s+'\)\s*===\s*0\)\s*continue/",
            $this->actionsSrc,
            'view_activity must skip "CMD: ..." lines from debug.log to avoid dupes'
        );
        // Cap at 500 entries
        $this->assertMatchesRegularExpression(
            '/array_slice\(\$entries,\s*-500\)/',
            $this->actionsSrc,
            'view_activity must cap output at 500 most-recent entries'
        );
        // Returns success + entries shape
        $this->assertMatchesRegularExpression(
            "/'success'\s*=>\s*true.*?'entries'/s",
            $this->actionsSrc,
            'view_activity must return {success: true, entries: [...]}'
        );
    }

    public function testCmdLogJsonParsedIntoNormalisedShape(): void
    {
        // cmd.log entries map to level CMD/ERROR/OUT depending on type field
        $this->assertMatchesRegularExpression(
            "/\\\$type\s*===\s*'err'\)\s*\?\s*'ERROR'\s*:\s*\(\(\\\$type\s*===\s*'debug'\)\s*\?\s*'OUT'\s*:\s*'CMD'\)/",
            $this->actionsSrc,
            'cmd.log type field must map: err→ERROR, debug→OUT, default→CMD'
        );
    }

    public function testClearActivityActionTruncatesBothFiles(): void
    {
        $this->assertMatchesRegularExpression(
            "/if\s*\(\\\$action\s*===\s*'clear_activity'\)/",
            $this->actionsSrc,
            'zram_actions.php must define a clear_activity action handler'
        );
        // Must truncate BOTH log files
        $this->assertMatchesRegularExpression(
            '/file_put_contents\(ZRAM_CMD_LOG,\s*""\)/',
            $this->actionsSrc,
            'clear_activity must truncate cmd.log'
        );
        $this->assertMatchesRegularExpression(
            '/file_put_contents\(ZRAM_DEBUG_LOG,\s*""\)/',
            $this->actionsSrc,
            'clear_activity must truncate debug.log'
        );
    }

    public function testLegacyActionsRetainedAsBackwardCompat(): void
    {
        // Smoke test still invokes clear_cmd_log; external callers may use view_log
        foreach (['view_log', 'clear_log', 'clear_cmd_log', 'append_cmd_log'] as $legacy) {
            $this->assertMatchesRegularExpression(
                "/if\s*\(\\\$action\s*===\s*'$legacy'\)/",
                $this->actionsSrc,
                "legacy action $legacy must remain for backward compat"
            );
        }
    }

    public function testPageHasFilterChipsAndActivityLog(): void
    {
        // Four filter chips, each with a data-filter attribute
        foreach (['all', 'commands', 'events', 'errors'] as $filter) {
            $this->assertMatchesRegularExpression(
                '/<button[^>]*class="activity-chip[^"]*"[^>]*data-filter="' . $filter . '"|<button[^>]*data-filter="' . $filter . '"[^>]*class="activity-chip/',
                $this->pageSrc,
                "filter chip data-filter=\"$filter\" must be present"
            );
        }
        $this->assertStringContainsString(
            'id="activity-log"',
            $this->pageSrc,
            'page must contain the unified #activity-log div'
        );
        // Old tabs/divs must be gone
        $this->assertStringNotContainsString(
            'id="tab-cmd"',
            $this->pageSrc,
            'old Command History tab must be removed'
        );
        $this->assertStringNotContainsString(
            'id="tab-debug"',
            $this->pageSrc,
            'old System Debug Log tab must be removed'
        );
        $this->assertStringNotContainsString(
            'id="console-log"',
            $this->pageSrc,
            'old #console-log div must be removed'
        );
        $this->assertStringNotContainsString(
            'id="debug-log-view"',
            $this->pageSrc,
            'old #debug-log-view div must be removed'
        );
    }

    public function testPageHasActivityCss(): void
    {
        $this->assertStringContainsString(
            '.activity-row',
            $this->pageSrc,
            'page CSS must define .activity-row'
        );
        // Each level must have a corresponding badge colour class
        foreach (['cmd', 'info', 'error', 'debug', 'out'] as $level) {
            $this->assertStringContainsString(
                '.activity-badge-' . $level,
                $this->pageSrc,
                "page CSS must define a badge style for level=$level"
            );
        }
        $this->assertStringContainsString(
            '.activity-chip.active',
            $this->pageSrc,
            'page CSS must define an active state for the filter chips'
        );
    }

    public function testJsHasActivityFunctions(): void
    {
        foreach (['fetchActivity', 'clearActivity', 'setActivityFilter', 'applyActivityFilter', 'buildActivityRow', 'bootstrapActivity'] as $fn) {
            $this->assertMatchesRegularExpression(
                "/function\s+$fn\s*\(/",
                $this->jsSrc,
                "JS must define $fn"
            );
        }
        // Filter map shape
        $this->assertMatchesRegularExpression(
            '/ACTIVITY_FILTERS\s*=\s*\{[^}]*all:\s*null[^}]*commands:\s*\[[^\]]*CMD[^\]]*OUT/s',
            $this->jsSrc,
            'JS ACTIVITY_FILTERS map must define all/commands at minimum with CMD+OUT under commands'
        );
        // addLog must build an activity row, not the legacy log-entry div.
        // Locate the function body and search inside it (nested braces would
        // confuse a single regex; extract-then-grep avoids that.)
        $this->assertMatchesRegularExpression(
            '/function\s+addLog\s*\(/',
            $this->jsSrc,
            'JS must define addLog'
        );
        $this->assertNotEquals(
            false,
            $addLogPos = strpos($this->jsSrc, 'function addLog'),
            'addLog declaration must be locatable'
        );
        $addLogBody = substr($this->jsSrc, $addLogPos, 1500);
        $this->assertStringContainsString(
            'buildActivityRow',
            $addLogBody,
            'addLog must call buildActivityRow to render live entries with the same DOM shape as historical ones'
        );

        // fetchActivity called on bootstrap
        $bootstrapPos = strpos($this->jsSrc, 'function bootstrapActivity');
        $this->assertNotEquals(false, $bootstrapPos, 'bootstrapActivity declaration must be locatable');
        $bootstrapBody = substr($this->jsSrc, $bootstrapPos, 600);
        $this->assertStringContainsString(
            'fetchActivity()',
            $bootstrapBody,
            'bootstrapActivity must call fetchActivity() on page load'
        );
    }
}
