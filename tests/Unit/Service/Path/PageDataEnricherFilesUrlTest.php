<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Path;

use OCA\IntraVox\Service\Folder\FolderContext;
use OCA\IntraVox\Service\Language\LanguageResolver;
use OCA\IntraVox\Service\LanguageService;
use OCA\IntraVox\Service\Locator\PageLocator;
use OCA\IntraVox\Service\PageIndexService;
use OCA\IntraVox\Service\Path\PageDataEnricher;
use OCA\IntraVox\Service\Path\PagePathHelper;
use OCA\IntraVox\Service\PermissionService;
use OCA\IntraVox\Service\Publication\MetaVoxGateway;
use OCA\IntraVox\Service\Translation\TranslationGroupService;
use OCA\IntraVox\Service\Util\GroupfolderResolver;
use OCA\IntraVox\Tests\Unit\Service\Harness\BuildsNodeFixtures;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One click from any page to its Files location (review L3-01): the enricher
 * builds the link from the configured mount name + the page's folder path.
 */
class PageDataEnricherFilesUrlTest extends TestCase {
    use BuildsNodeFixtures;

    public function testTheLinkOpensThePagesFolderUnderTheConfiguredMount(): void {
        $page = $this->makeFolder('/IntraVox/es/noticias/bienvenida', []);
        $lang = $this->makeFolder('/IntraVox/es', ['noticias' => $this->makeFolder('/IntraVox/es/noticias', ['bienvenida' => $page])]);
        $base = $this->makeFolder('/IntraVox', ['es' => $lang]);

        $out = $this->enricher($base, 'Intranet')->enrich(['uniqueId' => 'page-x', 'title' => 'Bienvenida'], $page);

        $this->assertSame('es/noticias/bienvenida', $out['path']);
        $this->assertSame('/index.php/apps/files/?dir=%2FIntranet%2Fes%2Fnoticias%2Fbienvenida', $out['filesFolderUrl'], 'the FOLDER, under the configured name, never the .json');
    }

    public function testTheLanguageRootLinksTheMountItself(): void {
        $lang = $this->makeFolder('/IntraVox/es', []);
        $base = $this->makeFolder('/IntraVox', ['es' => $lang]);

        $out = $this->enricher($base, '')->enrich(['uniqueId' => 'home', 'title' => 'Inicio'], $lang);

        $this->assertSame('es', $out['path']);
        $this->assertSame('/index.php/apps/files/?dir=%2FIntraVox%2Fes', $out['filesFolderUrl'], 'unset config = the default name');
    }

    private function enricher(\OCP\Files\Folder $base, string $configuredName): PageDataEnricher {
        $config = $this->createMock(\OCP\IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn(string $app, string $key, string $default = '') => $key === 'groupfolder_name' ? $configuredName : $default
        );
        $config->method('getUserValue')->willReturn('es');
        // FolderContext built the way PageTreeBuilderTest does: real atoms, the
        // mount injected as the override — the harness's fakeFolderContext() owns its own
        // IConfig, and this test needs the app value answered.
        $locator = new PageLocator($this->createMock(PageIndexService::class), $this->createMock(LoggerInterface::class));
        $folders = new FolderContext(
            $this->createMock(\OCP\Files\IRootFolder::class),
            'tester',
            $config,
            $this->createMock(LanguageService::class),
            new LanguageResolver(),
            $locator,
            $base
        );
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRoute')->willReturnCallback(
            fn(string $route, array $params = []) => '/index.php/apps/files/?' . http_build_query($params)
        );
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('permissionsFromNode')->willReturn(['canRead' => true, 'canWrite' => false, 'canCreate' => false, 'canDelete' => false, 'canShare' => false, 'raw' => 1]);
        $metaVox = $this->createMock(MetaVoxGateway::class);
        $metaVox->method('isMetaVoxAvailable')->willReturn(false);

        return new PageDataEnricher(
            new PagePathHelper(),
            $permissions,
            $metaVox,
            $folders,
            $this->createMock(TranslationGroupService::class),
            new GroupfolderResolver(),   // final class — cannot be mocked; stateless, no deps
            $urls
        );
    }
}
