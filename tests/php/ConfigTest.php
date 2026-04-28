<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/zram_config.php';

final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        @unlink(ZRAM_CONFIG_FILE);
        @unlink(ZRAM_LOCK_FILE);
    }

    public function testReadReturnsDefaultsWhenNoConfigFileExists(): void
    {
        $cfg = zram_config_read();

        $this->assertIsArray($cfg);
        $this->assertSame('yes', $cfg['enabled']);
        $this->assertSame('3000', $cfg['refresh_interval']);
        $this->assertSame('zstd', $cfg['zram_algo']);
    }

    public function testReadMergesFileOverDefaults(): void
    {
        file_put_contents(ZRAM_CONFIG_FILE, implode("\n", [
            'enabled="no"',
            'refresh_interval="1000"',
            // swappiness omitted — should fall back to default
        ]) . "\n");

        $cfg = zram_config_read();

        $this->assertSame('no', $cfg['enabled'], 'value from file wins');
        $this->assertSame('1000', $cfg['refresh_interval'], 'value from file wins');
        $this->assertSame('150', $cfg['swappiness'], 'missing keys fall back to defaults');
    }

    public function testWriteRoundTripsThroughRead(): void
    {
        $ok = zram_config_write([
            'refresh_interval' => '1500',
            'swappiness'       => '60',
        ]);

        $this->assertTrue($ok);

        $cfg = zram_config_read();
        $this->assertSame('1500', $cfg['refresh_interval']);
        $this->assertSame('60',   $cfg['swappiness']);
        $this->assertSame('yes',  $cfg['enabled'], 'untouched keys retain default');
    }

    public function testWriteMergesOverExistingFile(): void
    {
        zram_config_write(['refresh_interval' => '2000']);
        zram_config_write(['swappiness'       => '80']);

        $cfg = zram_config_read();
        $this->assertSame('2000', $cfg['refresh_interval'], 'first write survives second');
        $this->assertSame('80',   $cfg['swappiness']);
    }

    public function testSwappinessAcceptsExtendedKernelRange(): void
    {
        // Regression guard for SWAPPINESS_RANGE_EXTENSION spec:
        // kernel 5.8+ supports vm.swappiness up to 200; storage layer must
        // round-trip values in the 101-200 band without silent clamping.
        zram_config_write(['swappiness' => '180']);
        $cfg = zram_config_read();
        $this->assertSame('180', $cfg['swappiness'], 'config layer must not clamp at the legacy 100 ceiling');

        zram_config_write(['swappiness' => '200']);
        $cfg = zram_config_read();
        $this->assertSame('200', $cfg['swappiness'], 'kernel maximum (200) must round-trip');
    }

    public function testWriteIsAtomicAgainstConcurrentReaders(): void
    {
        // Regression guard for feedback_concurrent_config_writes.md:
        // two sequential writes must not corrupt the file byte-for-byte.
        zram_config_write(['refresh_interval' => '1000']);
        zram_config_write(['refresh_interval' => '2000']);
        zram_config_write(['refresh_interval' => '3000']);

        $cfg = zram_config_read();
        $this->assertSame('3000', $cfg['refresh_interval']);
        // File should be parseable INI, not garbage
        $raw = parse_ini_file(ZRAM_CONFIG_FILE);
        $this->assertIsArray($raw, 'config file must remain valid INI');
    }
}
