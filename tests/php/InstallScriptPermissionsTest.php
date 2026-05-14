<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the file-permissions side-effect of the .14.01 install
 * hardening: switching from `wget -q -O "$EMHTTP_DEST/$DEST"` to a fetch-to-
 * mktemp + mv pattern inadvertently changed install perms from umask-default
 * 0644 to mktemp's 0600. Static assets served by nginx (running as nobody)
 * became unreadable, so the plugin icon disappeared from the Plugins page and
 * the dashboard JS silently 404'd. Scripts dodged the worst of it because
 * php-fpm runs as root and the existing `chmod +x` block fires on them.
 *
 * Fix (this WP, .14.02): explicit `chmod 0644 "$EMHTTP_DEST/$DEST"` after the
 * mv in the install loop's success branch. The subsequent `chmod +x` block
 * for scripts then produces 0755 (was producing 0711 — execute but no read).
 */
final class InstallScriptPermissionsTest extends TestCase
{
    private string $plgSrc;

    protected function setUp(): void
    {
        $this->plgSrc = file_get_contents(__DIR__ . '/../../unraid-zram-card.plg');
        $this->assertNotEmpty($this->plgSrc);
    }

    public function testInstallSetsReadablePermsAfterMv(): void
    {
        // After the mv into $EMHTTP_DEST/$DEST, the file must be re-moded to
        // 0644 so nginx (user `nobody`) can serve it as a static asset.
        $this->assertMatchesRegularExpression(
            '/mv\s+"\$TMP"\s+"\$EMHTTP_DEST\/\$DEST"[\s\S]+?chmod\s+0644\s+"\$EMHTTP_DEST\/\$DEST"/',
            $this->plgSrc,
            'install script must `chmod 0644 "$EMHTTP_DEST/$DEST"` after the mv — otherwise mktemp-default 0600 leaks through and static assets (icon, JS) become unreadable to nginx-as-nobody'
        );
    }

    public function testInstallChmodIsInSuccessBranchNotErrorBranch(): void
    {
        // The chmod 0644 must run on the SUCCESS path, not after the error
        // branch. Verify by checking it appears between `mv` and the next
        // `else` of the wget/test/mv conditional.
        $this->assertMatchesRegularExpression(
            '/mv\s+"\$TMP"\s+"\$EMHTTP_DEST\/\$DEST";\s*then[\s\S]+?chmod\s+0644[\s\S]+?else/',
            $this->plgSrc,
            'chmod 0644 must live inside the `then` branch of the wget+test+mv conditional (success path)'
        );
    }

    public function testScriptChmodPlusXStaysAfterReadFix(): void
    {
        // The existing per-script chmod +x lines must remain — combined with
        // the new chmod 0644, scripts end up 0755 (rwxr-xr-x).
        foreach (['zram_status.php', 'zram_actions.php', 'zram_drives.php',
                  'zram_collector.php', 'zram_init.sh'] as $name) {
            $this->assertMatchesRegularExpression(
                '/chmod \+x "\$EMHTTP_DEST\/' . preg_quote($name, '/') . '"/',
                $this->plgSrc,
                "chmod +x for $name must remain so the script ends up executable (0755) after the 0644 base"
            );
        }
    }
}
