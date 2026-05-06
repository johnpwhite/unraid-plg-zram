<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guards for DASHBOARD_TIER2_VISIBILITY.md (release 2026.05.06.10).
 *
 * Three independently-failing surfaces these tests pin in place so a future
 * refactor can't quietly bring back the "Tier-2-only mode shows nothing"
 * symptom that the 'seamon' forum user reported:
 *
 *   1. ZramCard.php has tier1/tier2 detection and three-mode chip rendering
 *   2. The disk row is reachable when only $ssdPath is set (not gated behind
 *      $ourDev like before)
 *   3. The "no swap configured" fallback exists and matches the inactive-state
 *      smoke check
 *   4. The chart JS has a Disk dataset with the cyan colour and reads the
 *      `s` field forward-compatibly
 *   5. Stats/chip references are guarded so missing chip elements (in
 *      single-tier modes) don't throw
 */
final class DashboardTier2VisibilityTest extends TestCase
{
    private string $cardSrc;
    private string $jsSrc;

    protected function setUp(): void
    {
        $base = __DIR__ . '/../../src';
        $this->cardSrc = file_get_contents("$base/ZramCard.php");
        $this->jsSrc   = file_get_contents("$base/js/zram-card.js");
        $this->assertNotEmpty($this->cardSrc);
        $this->assertNotEmpty($this->jsSrc);
    }

    public function testTierFlagsComputedOnRender(): void
    {
        // The three-mode chip dispatch keys off these two booleans
        $this->assertMatchesRegularExpression(
            '/\$tier1\s*=\s*\(\$devCount\s*>\s*0\)/',
            $this->cardSrc,
            'tier1 must be computed from $devCount so chip rendering knows when ZRAM is active'
        );
        $this->assertMatchesRegularExpression(
            '/\$tier2\s*=\s*\(\$ssdActive\s*===\s*true\)/',
            $this->cardSrc,
            'tier2 must be computed from $ssdActive (file present AND in /proc/swaps), not just $ssdPath'
        );
    }

    public function testThreeModeChipRender(): void
    {
        // Outer guard: chip strip + chart only render when at least one tier is live
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$tier1 \|\| \$tier2\): \?>/',
            $this->cardSrc,
            'chip strip must be wrapped in if (tier1 || tier2)'
        );
        // Tier-1-only chips (Uncompressed, Compressed, Ratio, Load) must be inside if ($tier1)
        $tier1Pos = strpos($this->cardSrc, '<?php if ($tier1): ?>');
        $this->assertNotEquals(false, $tier1Pos, 'tier1 chip block must be guarded by if ($tier1)');
        $tier1End = strpos($this->cardSrc, '<?php endif; ?>', $tier1Pos);
        $tier1Block = substr($this->cardSrc, $tier1Pos, $tier1End - $tier1Pos);
        foreach (['zram-uncompressed', 'zram-compressed', 'zram-ratio', 'zram-load'] as $chip) {
            $this->assertStringContainsString(
                $chip,
                $tier1Block,
                "ZRAM-only chip $chip must be inside the tier1 conditional"
            );
        }
        // Disk chip must be inside if ($tier2)
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$tier2\): \?>[\s\S]+?id="zram-disk"[\s\S]+?<\?php endif; \?>/',
            $this->cardSrc,
            'Disk chip (zram-disk) must be inside its own if ($tier2) block'
        );
    }

    public function testSwappinessChipAlwaysVisibleWhenAnyTierLive(): void
    {
        // Swappiness is meaningful in every mode (controls when kernel pages out at all),
        // so it must sit between the tier1/tier2 inner blocks and the closing of the
        // chip-strip div — i.e. inside the outer if($tier1 || $tier2) but outside the
        // inner per-tier guards.
        $this->assertStringContainsString(
            'id="zram-swappiness"',
            $this->cardSrc,
            'Swappiness chip must exist'
        );
        $swPos = strpos($this->cardSrc, 'id="zram-swappiness"');
        $before = substr($this->cardSrc, 0, $swPos);

        // Walk backwards through every PHP open-close tag to find the most recent one.
        // It must be an "endif" closing the tier2 block — not an "if($tier1)" / "if($tier2)"
        // open which would mean swappiness is inside one of those inner conditionals.
        if (!preg_match_all('/<\?php\s+(if[^?]+|else|endif);?\s*\?>/', $before, $m)) {
            $this->fail('No PHP tags found before swappiness chip — page structure is unexpected');
        }
        $lastTag = end($m[1]);
        $this->assertSame(
            'endif',
            trim($lastTag),
            'Most recent PHP tag before swappiness chip must be "endif" (closing the tier2 inner block) — anything else means swappiness is inside an inner if-block and would not render in all modes'
        );
    }

    public function testDiskRowIndependentOfTier1(): void
    {
        // The disk row used to live inside if ($ourDev) — that's the bug seamon
        // reported. Now it must be a sibling of the ZRAM row, both inside
        // their own conditionals so either can render alone.
        $diskRowPos = strpos($this->cardSrc, 'id="zram-ssd-row"');
        $this->assertNotEquals(false, $diskRowPos, 'disk row (zram-ssd-row) must exist');

        // Walk backwards to find the most recent PHP if() block before the disk row.
        // It must be guarded by $ssdPath, not $ourDev (the old buggy gating).
        $before = substr($this->cardSrc, 0, $diskRowPos);
        // Find last PHP if() opening tag in $before
        if (preg_match_all('/<\?php if \(([^)]+)\): \?>/', $before, $m)) {
            $lastGuard = end($m[1]);
            $this->assertSame(
                '$ssdPath',
                trim($lastGuard),
                'disk row must be guarded by if ($ssdPath) alone, not if ($ourDev) — the latter was the bug'
            );
        } else {
            $this->fail('No <?php if(...) found before the disk row — guard is required');
        }
    }

    public function testDeviceListHeaderRendersWhenEitherTierLive(): void
    {
        // The header row (Tier | Dev | Size | Prio | Algo) used to be inside if ($ourDev).
        // Now must render whenever the chip strip renders — i.e. inside if ($tier1 || $tier2)
        // but OUTSIDE the inner if ($ourDev) / if ($ssdPath) blocks.
        $this->assertMatchesRegularExpression(
            '/<div\s+id="zram-device-list"[\s\S]+?<div[^>]*>\s*Tier\s*<\/div>[\s\S]+?<\?php if \(\$ourDev\)/',
            $this->cardSrc,
            'device-list header row must appear before the if ($ourDev) ZRAM row block (so it renders for Tier-2-only too)'
        );
    }

    public function testNoSwapConfiguredFallback(): void
    {
        $this->assertStringContainsString(
            'No swap configured',
            $this->cardSrc,
            'page must contain a "No swap configured" fallback for the no-tiers case'
        );
        // The fallback link points at the settings page so users can act on it
        $this->assertStringContainsString(
            '/Dashboard/Settings/UnraidZramCard',
            $this->cardSrc,
            'fallback must link to ZRAM Settings'
        );
    }

    public function testZramConfigExposesTierFlagsToJs(): void
    {
        // The JS gates dataset visibility on these flags — they must be in window.ZRAM_CONFIG
        $this->assertStringContainsString(
            'tier1Active:',
            $this->cardSrc,
            'window.ZRAM_CONFIG must expose tier1Active'
        );
        $this->assertStringContainsString(
            'tier2Active:',
            $this->cardSrc,
            'window.ZRAM_CONFIG must expose tier2Active'
        );
    }

    public function testChartHasDiskDataset(): void
    {
        // The chart's 4th dataset is Tier 2 disk used — cyan, layered front of compressed
        $this->assertMatchesRegularExpression(
            "/label:\s*'Disk'[\s\S]+?borderColor:\s*'#00a4d8'/",
            $this->jsSrc,
            'chart must define a Disk dataset with the cyan border colour'
        );
        $this->assertMatchesRegularExpression(
            "/data:\s*historyData\.disk/",
            $this->jsSrc,
            'Disk dataset must point at historyData.disk'
        );
        // hidden flag wired to tier2 from window.ZRAM_CONFIG
        $this->assertMatchesRegularExpression(
            '/tier2\s*=\s*cfg\.tier2Active\s*===\s*true/',
            $this->jsSrc,
            'JS must read tier2Active from window.ZRAM_CONFIG'
        );
        $this->assertMatchesRegularExpression(
            '/hidden:\s*!tier2/',
            $this->jsSrc,
            'Disk dataset must use hidden: !tier2 so it does not render when Tier 2 is inactive'
        );
    }

    public function testHistoryDiskFieldForwardCompatible(): void
    {
        // Older history entries don't have the s field — must coerce to 0
        $this->assertMatchesRegularExpression(
            "/typeof\s+item\.s\s*===\s*'number'\s*\?\s*item\.s\s*:\s*0/",
            $this->jsSrc,
            'JS history backfill must read item.s with a 0 fallback for forward/backward compat'
        );
    }

    public function testHistoryDataIncludesDiskArray(): void
    {
        // The historyData object must include a disk array alongside original/used/load
        $this->assertMatchesRegularExpression(
            '/const\s+historyData\s*=\s*\{[^}]*disk:\s*\[\s*\]/s',
            $this->jsSrc,
            'historyData must declare an empty disk: [] array'
        );
        // Live tick path appends ssdUsedNow to historyData.disk
        $this->assertStringContainsString(
            'historyData.disk.push(ssdUsedNow)',
            $this->jsSrc,
            'live tick must push the current ssd_swap.used value to historyData.disk'
        );
        // Trim path keeps disk synchronised with the other arrays
        $this->assertStringContainsString(
            'historyData.disk.shift()',
            $this->jsSrc,
            'history-trim loop must shift historyData.disk in lockstep with other series'
        );
    }
}
