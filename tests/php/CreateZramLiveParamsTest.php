<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for CREATE_ZRAM_LIVE_PARAMS.md.
 *
 * The pre-fix `create_zram` action read all sizing parameters from the
 * saved config only, so a user who moved the percent slider or switched
 * to custom mode without first clicking APPLY & SAVE got a device sized
 * from the previous save — the user's "always 16G" complaint. The fix
 * accepts size_mode/size/percent/algo as live query params and persists
 * what was actually used.
 *
 * These are textual guard tests because the action handler shells out to
 * zramctl/mkswap/swapon and cannot run in PHPUnit. The aim is purely to
 * fail CI if a future refactor reverts the live-param contract.
 */
final class CreateZramLiveParamsTest extends TestCase
{
    private string $actionsSource;
    private string $jsSource;
    private string $pageSource;

    protected function setUp(): void
    {
        $base = __DIR__ . '/../../src';
        $this->actionsSource = file_get_contents("$base/zram_actions.php");
        $this->jsSource      = file_get_contents("$base/js/zram-settings.js");
        $this->pageSource    = file_get_contents("$base/UnraidZramCard.page");
        $this->assertNotEmpty($this->actionsSource);
        $this->assertNotEmpty($this->jsSource);
        $this->assertNotEmpty($this->pageSource);
    }

    public function testCreateZramReadsSizeModeFromQuery(): void
    {
        $this->assertMatchesRegularExpression(
            "/filter_input\(\s*INPUT_GET\s*,\s*'size_mode'/",
            $this->actionsSource,
            'create_zram must read size_mode from INPUT_GET so the form value beats saved config'
        );
    }

    public function testCreateZramReadsPercentFromQuery(): void
    {
        $this->assertMatchesRegularExpression(
            "/filter_input\(\s*INPUT_GET\s*,\s*'percent'/",
            $this->actionsSource,
            'create_zram must read percent from INPUT_GET (regression: live-form contract)'
        );
    }

    public function testCreateZramReadsCustomSizeFromQuery(): void
    {
        $this->assertMatchesRegularExpression(
            "/filter_input\(\s*INPUT_GET\s*,\s*'size'/",
            $this->actionsSource,
            'create_zram must read size from INPUT_GET when in custom mode'
        );
    }

    public function testCreateZramPersistsZramPercent(): void
    {
        // Before the fix, the post-success config write only persisted
        // zram_algo and a stale zram_size — zram_percent was never written
        // by the AJAX path, so init.sh on next boot used the old value.
        // Lazy `.*?` allows the nested parens of max(25, min(75, $pct)).
        $this->assertMatchesRegularExpression(
            "/zram_config_write\(.*?'zram_percent'/s",
            $this->actionsSource,
            'create_zram must persist zram_percent so next-boot init reproduces the user-chosen size'
        );
    }

    public function testCustomSizeFormatIsValidated(): void
    {
        // Match the literal `[GMT]` character class inside a preg_match call.
        // We do not anchor on `\d` because that would have to match the
        // literal two characters `\d` in the PHP source string, which would
        // make the regex brittle.
        $this->assertMatchesRegularExpression(
            "/preg_match\([^,]*\[GMT\]/",
            $this->actionsSource,
            'create_zram must validate custom size format with a [GMT] character class — silent fall-through on bad input would replicate the original UX bug'
        );
    }

    public function testJsExposesBuildCreateZramParams(): void
    {
        $this->assertStringContainsString(
            'function buildCreateZramParams',
            $this->jsSource,
            'zram-settings.js must define buildCreateZramParams() so CREATE sends live form state'
        );
    }

    public function testPageButtonInvokesCreateZramHelper(): void
    {
        // The CREATE button must call the helper that reads live form state,
        // not the bare zramAction('create_zram') that ignored the form.
        $this->assertMatchesRegularExpression(
            "/onclick=\"createZram\(\)\"/",
            $this->pageSource,
            'CREATE button must invoke createZram() helper, not the form-blind action'
        );
    }
}
