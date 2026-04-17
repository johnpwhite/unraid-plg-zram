<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for 2026.04.17.02: the dashboard card must not hardcode a
 * version string as the JS/CSS cache-buster, because it drifts out of sync
 * with the actual plugin version on upgrade and browsers serve stale JS.
 *
 * See: feedback_cache_bust_filemtime.md
 * See: .claude/docs/unraid-plugin/DASHBOARD_STYLE_GUIDE.md § Cache-busting
 */
final class CacheBusterTest extends TestCase
{
    private string $cardSource;

    protected function setUp(): void
    {
        $path = __DIR__ . '/../../src/ZramCard.php';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "source file missing: $path");
        $this->cardSource = $raw;
    }

    public function testNoHardcodedVersionStringInScriptTag(): void
    {
        // Catches ?v=2026.04.16 and similar calendar-version literals
        $this->assertDoesNotMatchRegularExpression(
            '/\?v=[\'"]?20\d{2}\.\d{2}\.\d{2}/',
            $this->cardSource,
            'Script/link tags must not embed a hardcoded calendar version. Use filemtime() instead.'
        );
    }

    public function testNoHardcodedVersionVariable(): void
    {
        // Catches $version = '2026.04.XX';
        $this->assertDoesNotMatchRegularExpression(
            '/\$version\s*=\s*[\'"]20\d{2}\.\d{2}/',
            $this->cardSource,
            '$version must not be assigned a literal calendar version. Derive from filemtime() or plugin metadata.'
        );
    }

    public function testFilemtimeIsUsedForCacheBusting(): void
    {
        $this->assertMatchesRegularExpression(
            '/filemtime\s*\(/',
            $this->cardSource,
            'ZramCard.php should compute cache-buster via filemtime() of the asset file.'
        );
    }

    public function testFormActionInSettingsPageIsSelfRelative(): void
    {
        // Regression guard for 2026.04.17.02: form must submit to self, not a
        // hardcoded absolute path that breaks when Menu directive changes.
        // See feedback_form_action_self.md
        $path = __DIR__ . '/../../src/UnraidZramCard.page';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, "source file missing: $path");

        // Match <form ... method="post" ... action="/Something">
        $this->assertDoesNotMatchRegularExpression(
            '/<form[^>]+action=["\']\/[A-Z][^"\']*["\']/',
            $raw,
            'Settings form must not hardcode an absolute action URL. Omit the action attribute so it submits to self.'
        );
    }
}
