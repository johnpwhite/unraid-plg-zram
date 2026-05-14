<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guards for INSTALL_HARDENING.md (OpenProject #774).
 *
 * The bug: the .plg's INLINE install script did `wget -q -O "$EMHTTP_DEST/$DEST"`
 * which truncates the destination to 0 bytes BEFORE the fetch starts — so any
 * per-file failure (404, DNS, transient network blip) leaves a 0-byte stub at
 * the real destination, and the loop continues without aborting. Plugin Manager
 * saw the script exit 0 and marked the install complete, while Unraid's webgui
 * spammed "Invalid .page format: …" on every render (the .page files were 0-byte).
 *
 * Separately, the remove script did `rm -f "$SSD_PATH"` — uninstalling the
 * plugin (or any Plugin Manager update that internally remove-then-installs)
 * silently destroyed the user's Tier 2 swap file. Violated HYBRID_PERSISTENCE.
 *
 * Fix (this WP): install script fetches to mktemp, validates [ -s "$TMP" ],
 * mv only on success; wget gets --tries=3 --timeout=15; per-file failures
 * accumulate into $FAILED and exit 1 if any; post-install zero-byte sanity
 * gate (find -size 0 -> exit 1). Remove script drops the rm of $SSD_PATH;
 * swapoff stays.
 */
final class InstallScriptHardeningTest extends TestCase
{
    private string $plgSrc;

    protected function setUp(): void
    {
        $this->plgSrc = file_get_contents(__DIR__ . '/../../unraid-zram-card.plg');
        $this->assertNotEmpty($this->plgSrc);
    }

    // --- Install script: fetch-to-temp pattern ------------------------------

    public function testInstallFetchesToMktemp(): void
    {
        // The install INLINE block must allocate a temp file before wget.
        $this->assertMatchesRegularExpression(
            '/TMP=\$\(mktemp\)/',
            $this->plgSrc,
            'install script must use TMP=$(mktemp) so a failed wget cannot leave a 0-byte stub at $EMHTTP_DEST/$DEST'
        );
    }

    public function testInstallWgetsToTempNotDestination(): void
    {
        // wget must target $TMP, not the real destination.
        $this->assertMatchesRegularExpression(
            '/wget\s+[^\n]*-O\s+"\$TMP"/',
            $this->plgSrc,
            'install script must `wget -O "$TMP"` (was: -O "$EMHTTP_DEST/$DEST", which 0-byte-stubs on failure)'
        );
        // Must NOT have the legacy direct-to-destination form.
        $this->assertDoesNotMatchRegularExpression(
            '/wget\s+-q\s+-O\s+"\$EMHTTP_DEST\/\$DEST"/',
            $this->plgSrc,
            'install script must not regress to wget -O "$EMHTTP_DEST/$DEST" — that is the 0-byte-stub bug'
        );
    }

    public function testInstallValidatesNonEmptyBeforeMv(): void
    {
        // The success guard must include a non-empty check on $TMP.
        $this->assertMatchesRegularExpression(
            '/\[\s+-s\s+"\$TMP"\s+\]/',
            $this->plgSrc,
            'install script must guard the move with `[ -s "$TMP" ]` so an HTTP 200 returning an empty body still fails the file'
        );
    }

    public function testInstallMovesOnlyOnSuccess(): void
    {
        // The mv to the real destination must be inside the success branch.
        // We assert the ordering: wget --tries ... -O "$TMP" ... && [ -s "$TMP" ] ... mv "$TMP" "$EMHTTP_DEST/$DEST"
        $this->assertMatchesRegularExpression(
            '/wget[\s\S]{0,200}-O\s+"\$TMP"[\s\S]{0,200}\[\s+-s\s+"\$TMP"\s+\][\s\S]{0,200}mv\s+"\$TMP"\s+"\$EMHTTP_DEST\/\$DEST"/',
            $this->plgSrc,
            'mv "$TMP" "$EMHTTP_DEST/$DEST" must appear AFTER both the wget and the [ -s "$TMP" ] check, all on the success branch'
        );
    }

    public function testInstallWgetHasRetriesAndTimeout(): void
    {
        // Transient network blips should not fail the whole install — wget retries.
        $this->assertMatchesRegularExpression(
            '/wget\s+[^\n]*--tries=3/',
            $this->plgSrc,
            'install wget must pass --tries=3 so a transient network blip does not fail the file'
        );
        $this->assertMatchesRegularExpression(
            '/wget\s+[^\n]*--timeout=15/',
            $this->plgSrc,
            'install wget must pass --timeout=15 so a hung connection does not stall the install indefinitely'
        );
    }

    // --- Install script: failure accounting + abort -------------------------

    public function testInstallAccumulatesFailures(): void
    {
        $this->assertMatchesRegularExpression(
            '/FAILED=\$\(\(FAILED\+1\)\)/',
            $this->plgSrc,
            'install script must accumulate per-file failures into a $FAILED counter on the error branch'
        );
    }

    public function testInstallAbortsWhenAnyFailed(): void
    {
        // After the loop, $FAILED -gt 0 -> exit 1 (so Plugin Manager sees failure).
        $this->assertMatchesRegularExpression(
            '/\[\s+"\$FAILED"\s+-gt\s+0\s+\][\s\S]{0,400}exit\s+1/',
            $this->plgSrc,
            'install script must `exit 1` when $FAILED is greater than 0 — silent 0 exit is what made the bug invisible to Plugin Manager'
        );
    }

    public function testInstallHasPostInstallZeroByteGate(): void
    {
        // Belt-and-braces: even if $FAILED accounting misses something, scan
        // the install dir post-hoc for 0-byte files and abort if any.
        $this->assertMatchesRegularExpression(
            '/find\s+"\$EMHTTP_DEST"\s+-type\s+f\s+-size\s+0[\s\S]{0,400}exit\s+1/',
            $this->plgSrc,
            'install script must include a post-install `find "$EMHTTP_DEST" -type f -size 0` gate that exit 1s if anything'
        );
    }

    // --- Remove script: must NOT destroy the Tier 2 swap file ---------------

    public function testRemoveDoesNotUnlinkSsdSwap(): void
    {
        // The remove INLINE block must NOT rm/unlink the configured Tier 2
        // swap file path — that's user data, must survive uninstall/reinstall
        // per HYBRID_PERSISTENCE.
        $this->assertDoesNotMatchRegularExpression(
            '/\brm\s+-f\s+"\$SSD_PATH"/',
            $this->plgSrc,
            'remove script must NOT `rm -f "$SSD_PATH"` — the Tier 2 swap file is user data and must survive uninstall (HYBRID_PERSISTENCE)'
        );
        // Catch related-but-different forms too (unlink, just rm without -f, etc.).
        $this->assertDoesNotMatchRegularExpression(
            '/\b(rm|unlink)\s+(-[a-zA-Z]+\s+)*"\$SSD_PATH"/',
            $this->plgSrc,
            'remove script must not delete $SSD_PATH via any rm/unlink variant'
        );
    }

    public function testRemoveStillDeactivatesSsdSwap(): void
    {
        // The deactivate-from-kernel behaviour stays — only the file unlink is removed.
        $this->assertMatchesRegularExpression(
            '/swapoff\s+"\$SSD_PATH"/',
            $this->plgSrc,
            'remove script must still `swapoff "$SSD_PATH"` — only the file unlink is dropped, kernel-side deactivation remains'
        );
    }

    public function testRemoveStillResetsLabeledZramDevice(): void
    {
        // In-memory zram device cleanup stays (RAM state, safe to wipe).
        $this->assertMatchesRegularExpression(
            '/zramctl\s+--reset\s+"\/dev\/\$ZID"/',
            $this->plgSrc,
            'remove script must still reset the labeled zram device — RAM-side state is safe to wipe on uninstall'
        );
    }
}
