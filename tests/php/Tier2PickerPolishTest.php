<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guards for TIER2_PICKER_AND_LOG_POLISH.md (release 2026.05.06.01).
 *
 * These are textual guards on the source files (not a live kernel test). They
 * exist to fail CI if a future refactor unintentionally undoes one of the
 * polish fixes:
 *
 *   1. Auto-size info line uses GB on both sides (was "X% of YGB = ZMB")
 *   2. Disk swap file label is "ZRAM_CARD_DISK" (was "ZRAM_CARD_SSD"); legacy
 *      label preserved in a separate constant for migration
 *   3. zram_init.sh contains a swaplabel relabel block for the legacy label
 *   4. HDD warning string says "faster disk" (was "SSD")
 *   5. zram_drives.php classifies ZFS and multi-device btrfs as "blocked" and
 *      sets clickable=false on those rows
 *   6. Settings card row spacing tightened (line-height: 1.6 on dt and dd)
 */
final class Tier2PickerPolishTest extends TestCase
{
    private string $configSrc;
    private string $initSrc;
    private string $drivesSrc;
    private string $pageSrc;
    private string $settingsJsSrc;

    protected function setUp(): void
    {
        $base = __DIR__ . '/../../src';
        $this->configSrc     = file_get_contents("$base/zram_config.php");
        $this->initSrc       = file_get_contents("$base/zram_init.sh");
        $this->drivesSrc     = file_get_contents("$base/zram_drives.php");
        $this->pageSrc       = file_get_contents("$base/UnraidZramCard.page");
        $this->settingsJsSrc = file_get_contents("$base/js/zram-settings.js");

        $this->assertNotEmpty($this->configSrc);
        $this->assertNotEmpty($this->initSrc);
        $this->assertNotEmpty($this->drivesSrc);
        $this->assertNotEmpty($this->pageSrc);
        $this->assertNotEmpty($this->settingsJsSrc);
    }

    public function testAutoSizeInfoUsesGbOnBothSides(): void
    {
        // Render side: PHP outputs $autoSizeGB, not $autoSizeMB.
        $this->assertStringContainsString(
            '$autoSizeGB',
            $this->pageSrc,
            'page must compute $autoSizeGB for the auto-size info line'
        );
        $this->assertStringNotContainsString(
            '$autoSizeMB',
            $this->pageSrc,
            'page must NOT reference $autoSizeMB anymore (replaced by GB conversion)'
        );
        // The visible string ends in "GB = $X GB" — verify both occurrences present
        $this->assertMatchesRegularExpression(
            '/\$memTotalGB.*?GB.*?\$autoSizeGB.*?GB/s',
            $this->pageSrc,
            'auto info line must show GB on both sides: "<X>GB = <Y>GB"'
        );
        // JS side: updateAutoSize uses GB suffix, no MB
        $this->assertStringContainsString(
            "+ 'GB'",
            $this->settingsJsSrc,
            'JS updateAutoSize() must format with GB suffix'
        );
        $this->assertStringNotContainsString(
            "+ mb +",
            $this->settingsJsSrc,
            'JS updateAutoSize() must not concatenate mb anymore'
        );
    }

    public function testSsdLabelRenamedToDisk(): void
    {
        $this->assertMatchesRegularExpression(
            "/define\(\s*'ZRAM_SSD_LABEL'\s*,\s*'ZRAM_CARD_DISK'\s*\)/",
            $this->configSrc,
            'ZRAM_SSD_LABEL constant must define value as ZRAM_CARD_DISK (was ZRAM_CARD_SSD)'
        );
        $this->assertMatchesRegularExpression(
            "/define\(\s*'ZRAM_LEGACY_SSD_LABEL'\s*,\s*'ZRAM_CARD_SSD'\s*\)/",
            $this->configSrc,
            'ZRAM_LEGACY_SSD_LABEL must preserve the old value for migration'
        );
    }

    public function testInitScriptRelabelsLegacyFiles(): void
    {
        // Relabel must run only if swaplabel exists, and only against the legacy label.
        $this->assertStringContainsString(
            'SSD_LABEL="ZRAM_CARD_DISK"',
            $this->initSrc,
            'init.sh SSD_LABEL var must be set to the new value'
        );
        $this->assertStringContainsString(
            'SSD_LEGACY_LABEL="ZRAM_CARD_SSD"',
            $this->initSrc,
            'init.sh must remember the legacy label name for migration'
        );
        // Refactor in 2026.05.06.09 moved the relabel block into the
        // activate_disk_swap function. Anchor on "command -v swaplabel" then
        // the legacy label comparison then the relabel call — variable name
        // (CURRENT_LABEL vs cur) intentionally not pinned.
        $this->assertMatchesRegularExpression(
            '/command -v swaplabel[\s\S]+?\$SSD_LEGACY_LABEL[\s\S]+?swaplabel -L "\$SSD_LABEL"/',
            $this->initSrc,
            'init.sh must contain a swaplabel migration block keyed on the legacy label'
        );
    }

    public function testHddWarningSaysFasterDiskNotSsd(): void
    {
        $this->assertStringContainsString(
            'no faster disk is available',
            $this->drivesSrc,
            'HDD warning must reference "faster disk" (Tier 2 has been renamed Disk for two releases)'
        );
        $this->assertStringNotContainsString(
            'no SSD is available',
            $this->drivesSrc,
            'HDD warning must not still reference SSD'
        );
    }

    public function testZfsPoolsAreListedAsBlocked(): void
    {
        // Allowing ZFS through the device-prefix gate is the bugfix that makes
        // them visible at all. The classification step then marks them blocked.
        $this->assertMatchesRegularExpression(
            '/\$isZfs\s*=\s*\(\$fstype\s*===\s*\'zfs\'\)/',
            $this->drivesSrc,
            'zram_drives.php must detect ZFS via fstype'
        );
        $this->assertMatchesRegularExpression(
            "/if\s*\(\s*!\\\$isZfs\s*&&\s*strpos\(\\\$dev,\s*'\/dev\/'\)\s*!==\s*0\s*\)\s*continue;/",
            $this->drivesSrc,
            'zram_drives.php must allow non-/dev devices for ZFS only (continue otherwise)'
        );
        $this->assertMatchesRegularExpression(
            "/if\s*\(\\\$isZfs\)\s*\{[^}]*?'classify'\s*=>\s*'blocked'|\\\$classify\s*=\s*'blocked'/s",
            $this->drivesSrc,
            'ZFS branch must set classify to blocked'
        );
        $this->assertStringContainsString(
            'kernel does not support swap files on ZFS datasets',
            $this->drivesSrc,
            'ZFS rows need an explanatory warning string'
        );
    }

    public function testMultiDeviceBtrfsIsBlockedNotJustWarn(): void
    {
        // Pre-fix: btrfs RAID was 'warn' + clickable. Post-fix: 'blocked' + non-clickable.
        $this->assertMatchesRegularExpression(
            '/\$btrfsRaid\)\s*\{[^}]*?\$classify\s*=\s*\'blocked\'/s',
            $this->drivesSrc,
            'multi-device btrfs branch must set classify to blocked'
        );
        $this->assertMatchesRegularExpression(
            '/\$btrfsRaid\)\s*\{[^}]*?\$clickable\s*=\s*false/s',
            $this->drivesSrc,
            'multi-device btrfs branch must set clickable=false'
        );
    }

    public function testDriveEntryExposesClickableFlag(): void
    {
        $this->assertMatchesRegularExpression(
            "/'clickable'\s*=>\s*\\\$clickable/",
            $this->drivesSrc,
            'drive entry must include the clickable flag in the JSON output'
        );
    }

    public function testFrontendRespectsClickableFlag(): void
    {
        // The render must skip the onclick attribute for non-clickable rows
        $this->assertStringContainsString(
            'd.clickable === false',
            $this->settingsJsSrc,
            'loadDrives() must check d.clickable === false to gate the click handler'
        );
        // selectDrive() must defence-in-depth bail when called on a blocked row
        $this->assertMatchesRegularExpression(
            '/function\s+selectDrive[^{]*\{\s*if\s*\(el\.classList\.contains\(\'zram-drive-row-blocked\'\)\)\s*return;/',
            $this->settingsJsSrc,
            'selectDrive() must early-return on blocked rows'
        );
        // CSS hooks for blocked styling
        $this->assertStringContainsString(
            '.zram-drive-row-blocked',
            $this->pageSrc,
            'page CSS must define the .zram-drive-row-blocked class'
        );
        $this->assertStringContainsString(
            '.indicator-red',
            $this->pageSrc,
            'page CSS must define a red indicator for blocked rows'
        );
    }

    public function testCreateDiskButtonIdMatchesJsLookup(): void
    {
        // Regression guard for 2026.05.06.03: rename pass changed the id in
        // JS (getElementById('btn-create-disk')) and the function name
        // (createDiskSwap) but missed the button declaration in the .page —
        // user reported the CREATE button stayed disabled because selectDrive()
        // was looking up an id that no longer existed.
        $this->assertStringContainsString(
            'id="btn-create-disk"',
            $this->pageSrc,
            'page button id must match the JS lookup ("btn-create-disk")'
        );
        $this->assertStringContainsString(
            'onclick="createDiskSwap()"',
            $this->pageSrc,
            'page button onclick must call the renamed createDiskSwap() function'
        );
        $this->assertStringNotContainsString(
            'id="btn-create-ssd"',
            $this->pageSrc,
            'legacy button id must not survive — JS no longer looks for it'
        );
        $this->assertStringNotContainsString(
            'createSsdSwap',
            $this->pageSrc,
            'legacy onclick handler must not survive — function was renamed'
        );
        // Belt-and-braces: every getElementById('btn-create-...') in JS has a
        // matching id="..." attribute somewhere in the page source.
        if (preg_match_all("/getElementById\('(btn-[^']+)'\)/", $this->settingsJsSrc, $m)) {
            foreach (array_unique($m[1]) as $id) {
                $this->assertStringContainsString(
                    'id="' . $id . '"',
                    $this->pageSrc,
                    "JS references getElementById('$id') but no element with that id in the page"
                );
            }
        }
    }

    public function testIntervalsBothExpressedInSeconds(): void
    {
        // Refresh + Collection were "(ms)" and "(sec)" respectively; user
        // asked for one unit. Both labels now say "(sec)". Refresh storage
        // stays in ms internally (consumed by JS setInterval) — conversion
        // happens at the form boundary so existing user configs do not need
        // a migration.
        $this->assertStringContainsString(
            'Refresh Interval (sec)',
            $this->pageSrc,
            'Refresh Interval label must show seconds (was "(ms)")'
        );
        $this->assertStringNotContainsString(
            'Refresh Interval (ms)',
            $this->pageSrc,
            'no remaining (ms) label on Refresh Interval'
        );
        // Form input divides storage value by 1000 for display
        $this->assertMatchesRegularExpression(
            "/refresh_interval[^>]*value=\"<\?php\s+echo\s+htmlspecialchars\(number_format\(intval\(\\\$settings\['refresh_interval'\]\)\s*\/\s*1000/",
            $this->pageSrc,
            'refresh_interval input must render storage value (ms) divided by 1000'
        );
        // POST handler scales seconds back to ms; legacy ms values still accepted (>=100 means ms already)
        $this->assertMatchesRegularExpression(
            '/intval\(round\(\$f\s*\*\s*1000\)\)/',
            $this->pageSrc,
            'POST handler must multiply seconds-input by 1000 to store ms'
        );
        $this->assertMatchesRegularExpression(
            '/\$f\s*>=\s*100\s*\?\s*intval\(\$f\)/',
            $this->pageSrc,
            'POST handler must accept legacy ms values (>=100 means already in ms) for in-flight forms'
        );
    }

    public function testTier1ControlsHiddenWhenZramActive(): void
    {
        // Once a ZRAM device is live, size/percent/algo can't change without a
        // REMOVE+CREATE cycle, so the controls are pure noise. Mirrors Tier 2
        // which hides its create form once a disk swap is in use.
        // Anchor on a unique comment so we know we're looking at the right
        // block, then verify the conditional shape and the dt labels.
        $needle = 'Tier 1 controls — only visible when no ZRAM device is active';
        $this->assertStringContainsString(
            $needle,
            $this->pageSrc,
            'page must contain the Tier 1-controls comment marking the conditional block'
        );

        // Find the position of the Size dt and assert there's an
        // "if (!$devActive)" between the unique comment and that dt.
        $commentPos = strpos($this->pageSrc, $needle);
        $sizeDtPos  = strpos($this->pageSrc, '<dt>Size:</dt>', $commentPos);
        $this->assertGreaterThan(0, $sizeDtPos, '<dt>Size:</dt> must follow the marker comment');
        $window = substr($this->pageSrc, $commentPos - 80, $sizeDtPos - $commentPos + 80);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\$devActive\s*\)/',
            $window,
            'Tier 1 size/percent/algo dl must be guarded by "if (!$devActive)"'
        );

        // And there must be an endif between the </dl> and the next major card boundary.
        $algoDtPos = strpos($this->pageSrc, '<dt>Algorithm:</dt>', $sizeDtPos);
        $this->assertGreaterThan(0, $algoDtPos, '<dt>Algorithm:</dt> must come after Size');
        $tail = substr($this->pageSrc, $algoDtPos, 600);
        $this->assertMatchesRegularExpression(
            '/<\/dl>\s*<\?php\s+endif;\s*\?>/',
            $tail,
            'closing endif; must follow the </dl> that closes Tier 1 controls'
        );
    }

    public function testRowSpacingIsTighter(): void
    {
        // Was: line-height 2.2 with floated dt (broke vertical alignment).
        // Now: grid layout with align-items:center and line-height 1.4.
        // Catch a future refactor that loosens the rows or breaks alignment.
        $this->assertMatchesRegularExpression(
            '/\.zram-card-body\s+dl\s*\{[^}]*display:\s*grid/',
            $this->pageSrc,
            'dl must use grid layout so dt+dd line up vertically per row'
        );
        $this->assertMatchesRegularExpression(
            '/\.zram-card-body\s+dl\s*\{[^}]*align-items:\s*center/',
            $this->pageSrc,
            'dl grid must align items centred so labels sit middle of tall controls (slider)'
        );
        $this->assertMatchesRegularExpression(
            '/\.zram-card-body\s+dl\s+dt\s*\{[^}]*line-height:\s*1\.4/',
            $this->pageSrc,
            'dt line-height must be 1.4 (tightened from 2.2)'
        );
        $this->assertMatchesRegularExpression(
            '/\.zram-card-body\s+dl\s+dd\s*\{[^}]*line-height:\s*1\.4/',
            $this->pageSrc,
            'dd line-height must be 1.4 to match'
        );
    }
}
