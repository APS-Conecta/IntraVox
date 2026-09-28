<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Migration;

use OCA\IntraVox\Migration\Version001600Date20260609000000;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * L2-01: the migration seeds the deployment's es+en reality when (and only
 * when) the enabled_languages key is unset — fresh installs. Key-set
 * installs (the lab box's ["es","en"], or any admin choice) keep their value.
 */
class Version001600SeedTest extends TestCase {

    /** @param array<string,string> $store config values, remembered */
    private function configRemembering(array &$store): IConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $default = '') use (&$store): string {
                return $store[$key] ?? $default;
            });
        $config->method('setAppValue')
            ->willReturnCallback(static function (string $app, string $key, string $value) use (&$store): void {
                $store[$key] = $value;
            });
        return $config;
    }

    public function testSeedsEsEnWhenKeyUnset(): void {
        $store = [];
        $step = new Version001600Date20260609000000($this->configRemembering($store));

        $step->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);

        self::assertSame('["es","en"]', $store['enabled_languages'] ?? null);
    }

    public function testLeavesExistingValuesAlone(): void {
        $store = ['enabled_languages' => '["es","en"]'];
        $step = new Version001600Date20260609000000($this->configRemembering($store));

        $step->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);

        self::assertSame('["es","en"]', $store['enabled_languages'], 'the skip arm keeps any existing value');
    }
}
