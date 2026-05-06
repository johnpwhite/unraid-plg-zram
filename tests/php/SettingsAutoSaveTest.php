<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guards for SETTINGS_AUTO_SAVE.md (release 2026.05.06.05).
 *
 * The auto-save flow is end-to-end UI behaviour (blur → AJAX → indicator).
 * These tests are textual contracts on the source files that catch the
 * common ways a future refactor could quietly undo it:
 *
 *  1. The `update_setting` action handler exists in zram_actions.php with
 *     the correct whitelist and per-key validation
 *  2. Targeted side effects are gated by key (sysctl swappiness only on
 *     swappiness; collector restart only on collection_interval/debug)
 *  3. The page form fields all have data-autosave="true"
 *  4. The APPLY & SAVE button is gone
 *  5. The indicator CSS class is present
 *  6. JS contains the autosave bootstrap and the size coordinator
 */
final class SettingsAutoSaveTest extends TestCase
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

    public function testUpdateSettingActionExistsWithWhitelist(): void
    {
        $this->assertMatchesRegularExpression(
            "/if\s*\(\\\$action\s*===\s*'update_setting'\)/",
            $this->actionsSrc,
            'zram_actions.php must define an update_setting action handler'
        );
        // Whitelist must include every key the form auto-saves
        foreach (['enabled','refresh_interval','collection_interval','swappiness',
                  'debug','console_visible','zram_size','zram_percent','zram_algo'] as $k) {
            $this->assertMatchesRegularExpression(
                "/'$k'/",
                $this->actionsSrc,
                "update_setting whitelist must include '$k'"
            );
        }
        // Anything outside the whitelist must reject
        $this->assertStringContainsString(
            "Invalid setting key",
            $this->actionsSrc,
            'unknown keys must be rejected with a clear message'
        );
    }

    public function testPerKeyValidation(): void
    {
        // Swappiness clamp 0-200
        $this->assertMatchesRegularExpression(
            '/case\s+\'swappiness\':[^;]*max\(0,\s*min\(200,\s*intval\(\$rawValue\)\)\)/s',
            $this->actionsSrc,
            'swappiness must be clamped to 0-200'
        );
        // refresh_interval seconds-to-ms with legacy ms passthrough
        $this->assertMatchesRegularExpression(
            '/case\s+\'refresh_interval\':.*?\$f\s*>=\s*100\s*\?\s*intval\(\$f\)\s*:\s*intval\(round\(\$f\s*\*\s*1000\)\)/s',
            $this->actionsSrc,
            'refresh_interval must accept seconds (multiply x1000) and legacy ms (>=100 passthrough)'
        );
        // collection_interval min 1
        $this->assertMatchesRegularExpression(
            '/case\s+\'collection_interval\':[^;]*max\(1,\s*intval\(\$rawValue\)\)/s',
            $this->actionsSrc,
            'collection_interval must enforce min 1'
        );
        // zram_percent clamp 25-75
        $this->assertMatchesRegularExpression(
            '/case\s+\'zram_percent\':[^;]*max\(25,\s*min\(75,\s*intval\(\$rawValue\)\)\)/s',
            $this->actionsSrc,
            'zram_percent must be clamped to 25-75'
        );
        // zram_size accepts auto or N[GMT]
        $this->assertMatchesRegularExpression(
            '/case\s+\'zram_size\':.*?\$rawValue\s*===\s*\'auto\'.*?preg_match.*?\\\\d\+\\\\s\*\[GMT\]/s',
            $this->actionsSrc,
            'zram_size must accept "auto" or N[GMT]'
        );
        // zram_algo restricted to a known set
        $this->assertMatchesRegularExpression(
            "/case\s+'zram_algo':.*?\\['zstd',\s*'lz4',\s*'lzo',\s*'deflate'\\]/s",
            $this->actionsSrc,
            'zram_algo must be restricted to the known kernel set'
        );
    }

    public function testTargetedSideEffectsAreGated(): void
    {
        // sysctl runs only when swappiness changes
        $this->assertMatchesRegularExpression(
            "/if\s*\(\\\$key\s*===\s*'swappiness'\)\s*\{\s*zram_run\([^)]*sysctl[^)]*vm\.swappiness/s",
            $this->actionsSrc,
            'sysctl vm.swappiness must run only inside the swappiness-key branch'
        );
        // Collector restart only when collection_interval or debug changes
        $this->assertMatchesRegularExpression(
            "/if\s*\(\\\$key\s*===\s*'collection_interval'\s*\|\|\s*\\\$key\s*===\s*'debug'\)\s*\{[^}]*zram_init\.sh/s",
            $this->actionsSrc,
            'collector restart must be gated by key (collection_interval or debug only)'
        );
        // Debug-mode reset only when debug key changes
        $this->assertMatchesRegularExpression(
            "/if\s*\(\\\$key\s*===\s*'debug'\)\s*\{\s*zram_debug_reset\(\)/s",
            $this->actionsSrc,
            'zram_debug_reset must only fire on debug-key change'
        );
    }

    public function testApplySaveButtonRemoved(): void
    {
        $this->assertStringNotContainsString(
            'name="save_settings"',
            $this->pageSrc,
            'APPLY & SAVE submit button must be removed'
        );
        $this->assertStringNotContainsString(
            'APPLY &amp; SAVE',
            $this->pageSrc,
            'APPLY & SAVE label must be gone from the page'
        );
        // POST handler stays as a fallback (not removed) — verify it still exists
        $this->assertStringContainsString(
            "isset(\$_POST['save_settings'])",
            $this->pageSrc,
            'POST handler retained as deprecated fallback'
        );
    }

    public function testEverySettingFieldIsAutosaveTagged(): void
    {
        // Each settings field must carry data-autosave="true" so the JS bootstrap picks it up
        foreach ([
            'name="enabled"',
            'name="refresh_interval"',
            'name="collection_interval"',
            'name="swappiness"',
            'name="debug"',
            'name="console_visible"',
            'name="zram_percent"',
            'name="zram_algo"',
        ] as $needle) {
            $needleEsc = preg_quote($needle, '/');
            // Find the line containing the name attribute and assert data-autosave appears nearby
            $pattern = '/' . $needleEsc . '[^>]*data-autosave="true"|data-autosave="true"[^>]*' . $needleEsc . '/';
            $this->assertMatchesRegularExpression(
                $pattern,
                $this->pageSrc,
                "field with $needle must be tagged data-autosave=\"true\""
            );
        }
    }

    public function testTier1SizeUsesCoordinatorHandler(): void
    {
        // The size dropdown + custom input share the zram_size key, so they
        // need a coordinating save function rather than the generic data-autosave path.
        $this->assertStringContainsString(
            'function saveZramSize',
            $this->jsSrc,
            'JS must define saveZramSize() coordinator'
        );
        $this->assertStringContainsString(
            'saveZramSize()',
            $this->pageSrc,
            'page must wire saveZramSize() onto the size controls'
        );
        // Coordinator must call zramAutoSave with key "zram_size"
        $this->assertMatchesRegularExpression(
            "/zramAutoSave\(\s*'zram_size'/",
            $this->jsSrc,
            'saveZramSize must call zramAutoSave with the zram_size key'
        );
    }

    public function testIndicatorCssAndJsBootstrap(): void
    {
        $this->assertStringContainsString(
            '.zram-saved-indicator',
            $this->pageSrc,
            'Saved indicator CSS class must be defined in the page style block'
        );
        $this->assertStringContainsString(
            '.zram-saved-indicator-err',
            $this->pageSrc,
            'error indicator class must exist for failed saves'
        );
        // JS bootstrap that walks data-autosave fields
        $this->assertMatchesRegularExpression(
            "/document\.querySelectorAll\(\s*'\[data-autosave\]'\s*\)/",
            $this->jsSrc,
            'JS must walk all [data-autosave] elements on bootstrap'
        );
        // showSavedIndicator helper must exist
        $this->assertStringContainsString(
            'function showSavedIndicator',
            $this->jsSrc,
            'JS must define showSavedIndicator() to render the green/red feedback'
        );
        // zramAutoSave helper that POSTs update_setting must exist
        $this->assertMatchesRegularExpression(
            "/function\s+zramAutoSave[^{]*\{[^}]*action=update_setting/s",
            $this->jsSrc,
            'JS zramAutoSave must call the update_setting action'
        );
    }

    public function testOriginalValueGuardSuppressesSyntheticChanges(): void
    {
        // Browser autofill / synthetic change events fire before user
        // interaction. Guard with originalValue so we don't save phantom edits.
        $this->assertStringContainsString(
            'el.dataset.originalValue',
            $this->jsSrc,
            'JS must capture and compare originalValue to skip no-op blurs and synthetic changes'
        );
    }
}
