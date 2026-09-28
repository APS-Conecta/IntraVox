<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Folder;

use OCA\IntraVox\Service\Folder\MountName;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The one fact about where content lives (review L1-01): read from app config,
 * defaulting so no existing install moves, refusing anything that is not a
 * single path segment.
 */
class MountNameTest extends TestCase {

    public function testUnsetConfigIsTheDefault(): void {
        $this->assertSame('IntraVox', MountName::fromConfig($this->config(''))->get());
        $this->assertSame('IntraVox', MountName::fromConfig($this->config('   '))->get());
    }

    public function testAConfiguredNameIsReturnedTrimmed(): void {
        $this->assertSame('Intranet', MountName::fromConfig($this->config(' Intranet '))->get());
    }

    public function testASlashOrNothingIsRefused(): void {
        foreach (['', '  ', 'a/b', '/Intranet'] as $bad) {
            try {
                new MountName($bad);
                $this->fail(var_export($bad, true) . ' must be refused');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith('MOUNT_NAME_INVALID', $e->getMessage());
            }
        }
    }

    public function testTheConfigKeyIsTheOneTheSeamSets(): void {
        // gestion writes `occ config:app:set intravox groupfolder_name` (ADR-0020):
        // the two sides agree on the key by this pin, not by convention.
        $this->assertSame('intravox', MountName::APP_ID);
        $this->assertSame('groupfolder_name', MountName::CONFIG_KEY);
    }

    private function config(string $value): IConfig {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn(string $app, string $key, string $default = '') => ($app === 'intravox' && $key === 'groupfolder_name') ? $value : $default
        );
        return $config;
    }
}
