<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\BackgroundJob;

use OCA\IntraVox\BackgroundJob\CacheWarmupJob;
use OCA\IntraVox\Service\Folder\FolderContext;
use OCA\IntraVox\Service\Language\LanguageResolver;
use OCA\IntraVox\Service\Locator\PageLocator;
use OCA\IntraVox\Service\NavigationService;
use OCA\IntraVox\Service\PageIndexService;
use OCA\IntraVox\Service\PermissionService;
use OCA\IntraVox\Service\SetupService;
use OCA\IntraVox\Tests\Unit\Service\Harness\BuildsCollaboratorFixtures;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * L4-02: the warmup job derives its languages from the groupfolder's
 * language subfolders — the folders that exist — not a hard-coded list.
 * The old const warmed nl/en/de/fr every 15 minutes and never the
 * deployment's only real language (es).
 */
class CacheWarmupJobTest extends TestCase {
    use BuildsCollaboratorFixtures;

    /**
     * The unit suite's OCP stubs carry no background-job types. The real ones
     * from nextcloud/ocp are small and self-contained (psr/clock is the only
     * outside type), so they are loaded as-is — in dependency order — to let
     * CacheWarmupJob (a TimedJob) load at all.
     */
    public static function setUpBeforeClass(): void {
        $ocp = __DIR__ . '/../../../vendor/nextcloud/ocp/OCP/';
        foreach ([
            'BackgroundJob/IJob.php',
            'BackgroundJob/IParallelAwareJob.php',
            'AppFramework/Utility/ITimeFactory.php',
            'BackgroundJob/Job.php',
            'BackgroundJob/TimedJob.php',
        ] as $file) {
            require_once $ocp . $file;
        }
    }

    private function folder(string $name): Folder {
        $f = $this->createMock(Folder::class);
        $f->method('getName')->willReturn($name);
        $f->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        return $f;
    }

    /**
     * @param list<string> $warmed collects the language each mockable warm
     *   step ran for (the tree step runs the real service between them)
     */
    private function job(Folder $root, array &$warmed): CacheWarmupJob {
        $setup = $this->createMock(SetupService::class);
        $setup->method('getSharedFolder')->willReturn($root);

        $perms = $this->createMock(PermissionService::class);
        $nav = $this->createMock(NavigationService::class);
        $perms->method('buildPagePathMap')->willReturnCallback(function (string $lang) use (&$warmed): array { $warmed[] = $lang; return []; });
        $nav->method('getNavigation')->willReturnCallback(function (?string $lang) use (&$warmed): array { $warmed[] = $lang; return []; });

        // PageTreeService is final — never mocked. A REAL inert one via the
        // existing fakeTreeService harness, over a contentless FolderContext:
        // getPageTree(null, 'es') returns [] through the L2-02 empty-tree
        // guard, proving the derived language reaches the tree step without
        // fataling (the job's per-language catch(\Throwable) would otherwise
        // swallow it into $errors and break the warm order below).
        $base = $this->createMock(Folder::class);   // intraVox override: no language folders
        $base->method('get')->willReturnCallback(
            static function (string $n) {
                throw new \OCP\Files\NotFoundException($n);
            }
        );
        $folders = new FolderContext(
            $this->createMock(\OCP\Files\IRootFolder::class),
            'tester',
            $this->createMock(\OCP\IConfig::class),
            $this->createMock(\OCA\IntraVox\Service\LanguageService::class),
            new LanguageResolver(),
            new PageLocator(
                $this->createMock(PageIndexService::class),
                $this->createMock(LoggerInterface::class),
            ),
            $base,                       // intraVoxOverride — empty base, no language folders
            static fn() => null,         // read seam — the no-content world
        );
        $tree = $this->fakeTreeService(['folderContext' => $folders]);

        return new CacheWarmupJob(
            $this->createMock(ITimeFactory::class),
            $perms,
            $nav,
            $this->createMock(LoggerInterface::class),
            $tree,
            $setup,
        );
    }

    /** TimedJob::run is protected — invoked via reflection (never named `run`: PHPUnit's TestCase::run is final public). */
    private function runJob(CacheWarmupJob $job): void {
        $m = new \ReflectionMethod($job, 'run');
        $m->setAccessible(true);
        $m->invoke($job, null);
    }

    public function testWarmsOnlyTheLanguagesThatExist(): void {
        $root = $this->createMock(Folder::class);
        $root->method('getDirectoryListing')->willReturn([
            $this->folder('es'),
            $this->folder('_media'),    // infrastructure — not a language
            $this->folder('noticias'),  // a page folder — not a language code
        ]);

        $warmed = [];
        $this->runJob($this->job($root, $warmed));

        $this->assertSame(
            ['es', 'es'],
            $warmed,
            'path map + navigation warmed for es; nl/en/de/fr are gone (the tree step ran in between without error)'
        );
    }

    public function testContentlessGroupfolderWarmsNothing(): void {
        $root = $this->createMock(Folder::class);
        $root->method('getDirectoryListing')->willReturn([]);

        $warmed = [];
        $this->runJob($this->job($root, $warmed));

        $this->assertSame([], $warmed, 'a pre-seed fresh install has nothing to warm');
    }
}
