<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guards for OpenProject #422 (release 2026.05.06.15).
 *
 * The bug: dashboard "Compressed" chip rendered $totalUsed (zramctl TOTAL
 * column = COMPR + per-page metadata + slot rounding overhead). At small
 * data volumes overhead dominates so TOTAL > DATA, producing "Compressed
 * larger than Uncompressed" — counter-intuitive. The Ratio chip used
 * DATA/COMPR (algorithm ratio), so the displayed numbers didn't even line
 * up mathematically with the displayed Ratio.
 *
 * The fix (Option A — smallest blast radius):
 *   - Collector schema gains a 'c' field (COMPR bytes from zramctl --output COMPR)
 *   - ZramCard.php Compressed chip renders $totalCompressed
 *   - JS chip reads aggs.total_compressed
 *   - JS chart Compressed dataset reads entry.c, falls back to entry.u for
 *     pre-2026.05.06.15 entries (graceful — values within ~few% during the
 *     ~15-min transient until the rolling history window ages out)
 */
final class CompressedFieldFixTest extends TestCase
{
    private string $cardSrc;
    private string $collectorSrc;
    private string $jsSrc;

    protected function setUp(): void
    {
        $base = __DIR__ . '/../../src';
        $this->cardSrc      = file_get_contents("$base/ZramCard.php");
        $this->collectorSrc = file_get_contents("$base/zram_collector.php");
        $this->jsSrc        = file_get_contents("$base/js/zram-card.js");
        $this->assertNotEmpty($this->cardSrc);
        $this->assertNotEmpty($this->collectorSrc);
        $this->assertNotEmpty($this->jsSrc);
    }

    public function testCollectorQueriesCompr(): void
    {
        // The zramctl call must request COMPR alongside DATA and TOTAL so the
        // compressed dataset has a real source. Old call shape was just
        // NAME,DATA,TOTAL.
        $this->assertMatchesRegularExpression(
            '/zramctl[^"]+--output\s+NAME,DATA,COMPR,TOTAL/',
            $this->collectorSrc,
            'collector zramctl call must request COMPR column (was NAME,DATA,TOTAL — only DATA and TOTAL)'
        );
    }

    public function testCollectorWritesCField(): void
    {
        // Each history entry must include c => $totalCompressed
        $this->assertMatchesRegularExpression(
            "/'c'\s*=>\s*\\\$totalCompressed/",
            $this->collectorSrc,
            "collector must write 'c' => \$totalCompressed in each history entry"
        );
        // $totalCompressed must be sourced from the COMPR column of zramctl output
        $this->assertMatchesRegularExpression(
            '/\$totalCompressed\s*=\s*intval\(\$p\[2\]\)/',
            $this->collectorSrc,
            'collector must capture $totalCompressed from zramctl column 2 (COMPR)'
        );
    }

    public function testCardChipUsesTotalCompressed(): void
    {
        // The Compressed chip's <span id="zram-compressed"> must echo $totalCompressed,
        // not $totalUsed (which holds TOTAL = RAM cost incl. overhead).
        $this->assertMatchesRegularExpression(
            '/id="zram-compressed"[\s\S]+?\$fmt\(\$totalCompressed\)/',
            $this->cardSrc,
            'Compressed chip must render $totalCompressed (was $totalUsed — the bug)'
        );
        // And the chip must NOT regress to $totalUsed
        $this->assertDoesNotMatchRegularExpression(
            '/id="zram-compressed"[\s\S]+?\$fmt\(\$totalUsed\)/',
            $this->cardSrc,
            'Compressed chip must not source $totalUsed — that\'s the regression we just fixed'
        );
    }

    public function testJsChipReadsTotalCompressed(): void
    {
        // The live chip update path must read aggs.total_compressed, not aggs.total_used
        $this->assertMatchesRegularExpression(
            "/zram-compressed.*?formatBytes\(aggs\.total_compressed\)/s",
            $this->jsSrc,
            'JS Compressed chip must read aggs.total_compressed (was aggs.total_used — the bug)'
        );
    }

    public function testJsChartFallsBackForOldEntries(): void
    {
        // Backfill must read item.c with a fallback to item.u so pre-2026.05.06.15
        // entries don't render as zero
        $this->assertMatchesRegularExpression(
            "/typeof\s+item\.c\s*===\s*'number'\s*\?\s*item\.c\s*:\s*item\.u/",
            $this->jsSrc,
            'JS history backfill must read item.c with item.u as fallback for pre-c-field entries'
        );
        // Live tick path uses aggs.total_compressed not aggs.total_used
        $this->assertMatchesRegularExpression(
            '/historyData\.used\.push\(aggs\.total_compressed\)/',
            $this->jsSrc,
            'JS live-tick path must push aggs.total_compressed onto historyData.used'
        );
    }
}
