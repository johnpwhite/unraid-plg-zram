<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for REMOVE_ZRAM_STATE_SYNC.md.
 *
 * The pre-fix `remove_zram` block discarded the exit codes of `swapoff` and
 * `zramctl --reset`, then unconditionally unlinked device.conf and reported
 * success. Under memory pressure that left a still-active kernel device while
 * the UI thought it was gone — the user-visible "REMOVE button persists across
 * reloads" bug.
 *
 * This test asserts (textually) that the action source still contains exit-code
 * checks for both commands. It is intentionally not a behavioural test: the
 * actual command paths require a live kernel and root. The goal is purely to
 * fail CI if a future refactor reverts the fix.
 */
final class RemoveZramSilentFailureTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../src/zram_actions.php';
        $this->assertFileExists($path);
        $this->source = file_get_contents($path);
        $this->assertNotEmpty($this->source);
    }

    public function testRemoveZramChecksSwapoffExitCode(): void
    {
        // Match a `zram_run("swapoff ...")` invocation guarded by a
        // `!== 0` comparison on the same line. `[^"]*` keeps the match
        // anchored to a single string-literal command so the `wipefs`
        // call on a later line cannot accidentally satisfy the test.
        $this->assertMatchesRegularExpression(
            '/zram_run\(\s*"swapoff [^"]*"[^)]*\)[^)]*\)\s*!==\s*0/',
            $this->source,
            'remove_zram must guard swapoff with a !== 0 exit-code check ' .
            '(see docs/specs/REMOVE_ZRAM_STATE_SYNC.md)'
        );
    }

    public function testRemoveZramChecksZramctlResetExitCode(): void
    {
        $this->assertMatchesRegularExpression(
            '/zram_run\(\s*"zramctl --reset [^"]*"[^)]*\)[^)]*\)\s*!==\s*0/',
            $this->source,
            'remove_zram must guard zramctl --reset with a !== 0 exit-code check ' .
            '(see docs/specs/REMOVE_ZRAM_STATE_SYNC.md)'
        );
    }

    public function testGetOurDeviceBypassesBlkidCache(): void
    {
        // The probe must use `-c /dev/null` so /run/blkid/blkid.tab cannot
        // mask a freshly reset device. Without this, REMOVE looks like it
        // failed because the cache still reports our label.
        $configPath = __DIR__ . '/../../src/zram_config.php';
        $this->assertFileExists($configPath);
        $config = file_get_contents($configPath);

        $this->assertStringContainsString(
            'blkid -c /dev/null -t LABEL=',
            $config,
            'zram_get_our_device() must invoke blkid with -c /dev/null to bypass the persistent cache'
        );
    }
}
