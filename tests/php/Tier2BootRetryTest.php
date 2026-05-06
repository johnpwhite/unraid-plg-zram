<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guards for TIER2_BOOT_RETRY.md (release 2026.05.06.09).
 *
 * Boot race symptom: UD / pool mounts come up after plugin start, so
 * zram_init.sh sees the swap file as missing, logs WARN, quits, and Tier 2
 * never activates. Pre-fix workaround was clicking APPLY & SAVE which fired
 * the init script as a side effect — that disappeared with the auto-save
 * migration in 2026.05.06.05. Fix: a background retry loop in init.sh.
 */
final class Tier2BootRetryTest extends TestCase
{
    private string $initSrc;

    protected function setUp(): void
    {
        $this->initSrc = file_get_contents(__DIR__ . '/../../src/zram_init.sh');
        $this->assertNotEmpty($this->initSrc);
    }

    public function testActivateDiskSwapFunctionDefined(): void
    {
        $this->assertMatchesRegularExpression(
            '/activate_disk_swap\s*\(\)\s*\{/',
            $this->initSrc,
            'init.sh must define activate_disk_swap() so retry path uses identical activation semantics'
        );
        $this->assertStringContainsString(
            'cfg_val "ssd_swap_priority"',
            $this->initSrc,
            'activate_disk_swap must source priority from config'
        );
        $this->assertStringContainsString(
            'swaplabel -L "$SSD_LABEL"',
            $this->initSrc,
            'activate_disk_swap must perform the legacy-label migration'
        );
    }

    public function testBackgroundRetryLoopExists(): void
    {
        $this->assertStringContainsString(
            'scheduling background retry',
            $this->initSrc,
            'init.sh must log "scheduling background retry" when mount is not ready'
        );
        $this->assertMatchesRegularExpression(
            '/seq\s+1\s+60/',
            $this->initSrc,
            'retry loop must iterate 60 times (5 minute cap with 5 second sleep)'
        );
        $this->assertMatchesRegularExpression(
            '/sleep\s+5/',
            $this->initSrc,
            'retry loop must sleep 5 seconds between attempts'
        );
        $this->assertMatchesRegularExpression(
            '/\)\s*>\s*\/dev\/null\s+2>&1\s*&\s*\n\s*disown/',
            $this->initSrc,
            'retry loop must run in the background (subshell ending with & + disown)'
        );
    }

    public function testRetrySuccessAndTimeoutBothLog(): void
    {
        $this->assertMatchesRegularExpression(
            '/zlog\s+"Tier 2 activated after \$\(\(i\*5\)\)s wait"/',
            $this->initSrc,
            'retry loop must log a clear success line including how many seconds it waited'
        );
        $this->assertMatchesRegularExpression(
            '/Tier 2 active by external trigger/',
            $this->initSrc,
            'retry loop must detect external activation (e.g. user CREATE) and exit cleanly'
        );
        $this->assertStringContainsString(
            'never appeared after 5 minutes',
            $this->initSrc,
            'retry loop must emit a final WARN if the mount never appears'
        );
    }

    public function testSynchronousPathStillUsesActivateFunction(): void
    {
        $this->assertMatchesRegularExpression(
            '/if\s*\[\s*-f\s+"\$SSD_PATH"\s*\][\s\S]*?activate_disk_swap\s+"\$SSD_PATH"/s',
            $this->initSrc,
            'synchronous activation path must call activate_disk_swap rather than inlining swapon'
        );
    }
}