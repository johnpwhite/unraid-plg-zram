<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for TIER_OBSERVABILITY.md.
 *
 * Three text-level contracts that must survive future refactors:
 *
 *   1. zram_status.php emits ssd_swap.used so the dashboard can show
 *      live spillover.
 *   2. zram_collector.php captures ssd_used into the 's' history field
 *      so the dashboard can plot spillover over time.
 *   3. UnraidZramCard.page surfaces the swappiness "global, not per-tier"
 *      clarifier and the priority "used first / overflow only" explainers
 *      — these are the entire UX response to the user-asked questions
 *      about per-tier swappiness and priority customisation.
 *
 * These are textual guards (the actual code paths shell out to swapon/zramctl
 * and require a live kernel). They exist to fail CI if the observability UX
 * regresses to the pre-fix state where used numbers were invisible.
 */
final class TierObservabilityTest extends TestCase
{
    private string $statusSrc;
    private string $collectorSrc;
    private string $pageSrc;

    protected function setUp(): void
    {
        $base = __DIR__ . '/../../src';
        $this->statusSrc    = file_get_contents("$base/zram_status.php");
        $this->collectorSrc = file_get_contents("$base/zram_collector.php");
        $this->pageSrc      = file_get_contents("$base/UnraidZramCard.page");
        $this->assertNotEmpty($this->statusSrc);
        $this->assertNotEmpty($this->collectorSrc);
        $this->assertNotEmpty($this->pageSrc);
    }

    public function testStatusJsonExposesSsdSwapUsed(): void
    {
        // The dashboard's update path reads data.ssd_swap.used. Both keys
        // ('ssd_swap' and 'used') must appear in the JSON build for the live
        // readout to work. The arrow tokens guarantee they're map keys, not
        // incidental substrings.
        $this->assertMatchesRegularExpression(
            "/'ssd_swap'\s*=>/",
            $this->statusSrc,
            'zram_status.php must emit a ssd_swap key in the JSON response'
        );
        $this->assertMatchesRegularExpression(
            "/'used'\s*=>\s*intval/",
            $this->statusSrc,
            "ssd_swap.used must be derived as intval(...) so the dashboard can format it"
        );
    }

    public function testCollectorCapturesTier2UsedAsSField(): void
    {
        // History schema must include 's' alongside 'o', 'u', 'l'. Verifies
        // the daemon writes it on every tick, not just on first creation.
        $this->assertMatchesRegularExpression(
            "/'s'\s*=>\s*\\\$ssdUsed/",
            $this->collectorSrc,
            "zram_collector.php must add 's' => \$ssdUsed to each history entry"
        );
        // The variable must be sourced from swapon, not invented or hardcoded.
        $this->assertMatchesRegularExpression(
            "/swapon\s+--bytes[^']*USED/",
            $this->collectorSrc,
            'collector must source ssdUsed from `swapon --bytes ... --show=NAME,USED`'
        );
    }

    public function testCollectorDefaultsSToZeroWhenSsdSwapUnconfigured(): void
    {
        // The 's' value must be initialised before any branch so an unconfigured
        // SSD swap path produces s=0 rather than undefined-key warnings or a
        // missing key on the JSON shape.
        $this->assertMatchesRegularExpression(
            '/\$ssdUsed\s*=\s*0;/',
            $this->collectorSrc,
            'collector must initialise $ssdUsed = 0 so unconfigured SSD swap reports 0 (not undefined)'
        );
    }

    public function testPageShowsLiveTier1Used(): void
    {
        // The Tier 1 active-state status row must render the live "used"
        // number from zramctl's TOTAL column. Without this, the user only
        // sees allocated size and can't tell how full Tier 1 actually is.
        $this->assertMatchesRegularExpression(
            "/Used:\s*<\?php\s+echo\s+\\\$fmtB\(\\\$devUsedCompressed\)/",
            $this->pageSrc,
            'Tier 1 status row must show Used: <compressed-bytes> / <disksize>'
        );
        // Source for the number must come from extending the zramctl call to
        // include DATA + TOTAL. If the call shape regresses, this trips first.
        $this->assertMatchesRegularExpression(
            '/zramctl[^"]*--output[^"]*DISKSIZE,ALGORITHM,DATA,TOTAL/',
            $this->pageSrc,
            'page zramctl call must request DATA and TOTAL columns to populate the used readout'
        );
    }

    public function testPageShowsLiveTier2Used(): void
    {
        // Tier 2 active-state status row must show used / size. Inactive state
        // (file exists but not in /proc/swaps) only shows size — used has no
        // meaning when the swap is offline.
        $this->assertMatchesRegularExpression(
            "/Used:\s*<\?php\s+echo\s+\\\$fmtB\(\\\$ssdUsed\)/",
            $this->pageSrc,
            'Tier 2 status row must show Used: <bytes> / <size> when active'
        );
        $this->assertMatchesRegularExpression(
            "/swapon\s+--bytes[^']*NAME,SIZE,USED,PRIO/",
            $this->pageSrc,
            'page must source Tier 2 used/size/prio from a single swapon call so values are coherent'
        );
    }

    public function testPageContainsSwappinessGlobalClarifier(): void
    {
        // The clarifier line addresses the user's misconception that
        // swappiness can be set per-tier. Removing it would re-open the
        // documentation gap that prompted this release.
        $this->assertStringContainsString(
            'Global kernel setting',
            $this->pageSrc,
            'swappiness input must include the "Global kernel setting" clarifier line — addresses Ask 1 in TIER_OBSERVABILITY.md'
        );
        $this->assertStringContainsString(
            'not per-tier',
            $this->pageSrc,
            'clarifier must explicitly call out that swappiness is not per-tier (the user asked for a per-tier control)'
        );
    }

    public function testPageContainsPriorityExplainer(): void
    {
        // Priority semantics (used first / overflow only) must be inline in
        // the status rows. Without these phrases, a user has no UX-level
        // documentation of why priorities are 100/10 and why we don't expose
        // an editable control.
        $this->assertStringContainsString(
            'used first',
            $this->pageSrc,
            'Tier 1 status row must explain priority means "used first" (addresses Ask 3)'
        );
        $this->assertStringContainsString(
            'overflow only',
            $this->pageSrc,
            'Tier 2 status row must explain priority means "overflow only" (addresses Ask 3)'
        );
    }
}
