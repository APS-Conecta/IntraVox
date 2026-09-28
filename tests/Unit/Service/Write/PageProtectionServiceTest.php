<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Write;

use OCA\IntraVox\Exception\PageNotFoundException;
use OCA\IntraVox\Service\Cache\PageCacheService;
use OCA\IntraVox\Service\Locator\PageLocator;
use OCA\IntraVox\Service\PageIndexService;
use OCA\IntraVox\Service\Version\PageVersionService;
use OCA\IntraVox\Service\Write\PageProtectionService;
use OCA\IntraVox\Tests\Unit\Service\Harness\BuildsCacheFixtures;
use OCA\IntraVox\Tests\Unit\Service\Harness\BuildsFolderFixtures;
use OCA\IntraVox\Tests\Unit\Service\Harness\BuildsNodeFixtures;
use OCP\Files\File;
use OCP\Files\FileInfo;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The break-glass of the welcome tree's walls (review L4-01): the only writer of
 * `protected` besides gestion's renderer. Pinned here so the two-step stays a
 * two-step — a flip is versioned, idempotent, and cache-invalidating.
 */
class PageProtectionServiceTest extends TestCase {
    use BuildsCacheFixtures;
    use BuildsFolderFixtures;
    use BuildsNodeFixtures;

    /** @var string[] side effects in order: version.snapshot, file.write, cache.<uniqueId> */
    private array $events = [];
    private ?array $written = null;

    protected function setUp(): void {
        $this->events = [];
        $this->written = null;
    }

    public function testRaisingAWallSnapshotsAVersionThenWritesTheFlag(): void {
        $svc = $this->service(['uniqueId' => 'page-wall', 'title' => 'Noticias']);

        $r = $svc->setProtected('page-wall', true);

        $this->assertSame(['uniqueId' => 'page-wall', 'path' => 'en/noticias/noticias.json', 'protected' => true, 'changed' => true], $r);
        $this->assertTrue($this->written['protected'] ?? false);
        $this->assertSame('Noticias', $this->written['title'], 'the rest of the page is untouched');
        $this->assertSame(['version.snapshot', 'file.write', 'cache.page-wall'], $this->events, 'version BEFORE write, cache after');
    }

    public function testRemovingAWallDropsTheKey(): void {
        $svc = $this->service(['uniqueId' => 'page-wall', 'title' => 'Noticias', 'protected' => true]);

        $r = $svc->setProtected('page-wall', false);

        $this->assertFalse($r['protected']);
        $this->assertTrue($r['changed']);
        $this->assertArrayNotHasKey('protected', $this->written);
    }

    public function testAMatchingPageIsNotRewritten(): void {
        $svc = $this->service(['uniqueId' => 'page-wall', 'title' => 'Noticias', 'protected' => true]);

        $r = $svc->setProtected('page-wall', true);

        $this->assertFalse($r['changed']);
        $this->assertNull($this->written, 'no write when nothing changes');
        $this->assertSame([], $this->events, 'no version, no cache churn either');
    }

    public function testStatusReadsTheMarker(): void {
        $this->assertTrue($this->service(['uniqueId' => 'page-wall', 'protected' => true])->status('page-wall')['protected']);
        $this->assertFalse($this->service(['uniqueId' => 'page-wall'])->status('page-wall')['protected']);
    }

    public function testAnUnknownIdIsNotFound(): void {
        $svc = $this->service(['uniqueId' => 'page-wall']);

        $this->expectException(PageNotFoundException::class);
        $svc->status('page-nope');
    }

    // ── fixture: /IntraVox/en/noticias/noticias.json holding $stored ────────────────────────

    private function service(array $stored): PageProtectionService {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('noticias.json');
        $file->method('getType')->willReturn(FileInfo::TYPE_FILE);
        $file->method('getPath')->willReturn('/IntraVox/en/noticias/noticias.json');
        $file->method('getId')->willReturn(7);
        $file->method('getContent')->willReturn(json_encode($stored));
        $file->method('putContent')->willReturnCallback(function (string $json): bool {
            $this->events[] = 'file.write';
            $this->written = json_decode($json, true);
            return true;
        });
        $page = $this->makeFolder('/IntraVox/en/noticias', ['noticias.json' => $file]);
        $lang = $this->makeFolder('/IntraVox/en', ['noticias' => $page]);
        $base = $this->makeFolder('/IntraVox', ['en' => $lang]);

        $index = $this->createMock(PageIndexService::class);
        $index->method('findByUniqueId')->willReturn(null);   // index misses: the walk resolves, as deletePage's tests do
        $locator = new PageLocator($index, $this->createMock(LoggerInterface::class));

        $versions = $this->createMock(PageVersionService::class);
        $versions->method('createBeforeUpdate')->willReturnCallback(function (): void {
            $this->events[] = 'version.snapshot';
        });
        // PageCacheInvalidator is final: the real one over a PageCacheService spy
        // (BuildsCacheFixtures) — invalidate($id) always starts with clearRequest($id).
        $spy = $this->createMock(PageCacheService::class);
        $spy->method('clearRequest')->willReturnCallback(function (?string $id = null): void {
            $this->events[] = 'cache.' . $id;
        });
        $cache = $this->fakeCacheInvalidator($spy);

        return new PageProtectionService(
            $this->fakeFolderContext(intraVox: $base, languageFolder: $lang),
            $locator,
            $versions,
            $cache
        );
    }
}
