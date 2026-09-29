<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service\Folder;

use OCA\IntraVox\Service\Folder\FolderContext;
use OCA\IntraVox\Service\Language\LanguageResolver;
use OCA\IntraVox\Service\LanguageService;
use OCA\IntraVox\Service\Locator\PageLocator;
use OCA\IntraVox\Service\PageIndexService;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Proves FolderContext resolves folders + language byte-identically to the
 * PageService seams it was lifted from (getIntraVoxFolder / getUserLanguage /
 * getLanguageFolder / resolveEffectiveLanguage / getReadLanguageFolder /
 * getLanguageFolderByCode). This is the empirical gate of clean-target step 1:
 * FolderContext ships UNUSED, and if these assertions cannot hold, the substrate
 * is not cleanly extractable and the plan folds back for one revert.
 *
 * The bodies mirror PageLanguageResolutionTest's pinning of the same seams, now
 * driven through FolderContext's front door instead of a PageService subclass.
 */
class FolderContextSeamTest extends TestCase {

    /** newFolder() codes recorded, to assert the create-on-miss side-effect. */
    private array $created = [];

    private function jsonFile(string $path, array $json): File {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn(basename($path));
        $file->method('getType')->willReturn(FileInfo::TYPE_FILE);
        $file->method('getPath')->willReturn($path);
        $file->method('getContent')->willReturn(json_encode($json));
        return $file;
    }

    /** A language folder; $home gives it a loose home.json (real vs _generated). */
    private function langFolder(string $path, ?array $home = null): Folder {
        $children = [];
        if ($home !== null) {
            $children['home.json'] = $this->jsonFile($path . '/home.json', $home);
        }
        $folder = $this->createMock(Folder::class);
        $folder->method('getName')->willReturn(basename($path));
        $folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        $folder->method('getPath')->willReturn($path);
        $folder->method('getDirectoryListing')->willReturn(array_values($children));
        $folder->method('nodeExists')->willReturnCallback(fn($n) => isset($children[$n]));
        $folder->method('get')->willReturnCallback(function ($p) use ($children, $path) {
            if (isset($children[$p])) {
                return $children[$p];
            }
            throw new NotFoundException($path . '/' . $p);
        });
        return $folder;
    }

    /** The /IntraVox base folder resolving $languages, recording newFolder(). */
    private function baseFolder(array $languages): Folder {
        $base = $this->createMock(Folder::class);
        $base->method('getName')->willReturn('IntraVox');
        $base->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        $base->method('getPath')->willReturn('/IntraVox');
        $base->method('nodeExists')->willReturnCallback(fn($n) => isset($languages[$n]));
        $base->method('get')->willReturnCallback(function ($code) use ($languages) {
            if (isset($languages[$code])) {
                return $languages[$code];
            }
            throw new NotFoundException('/IntraVox/' . $code);
        });
        $base->method('newFolder')->willReturnCallback(function ($code) {
            $this->created[] = $code;
            return $this->langFolder('/IntraVox/' . $code);
        });
        return $base;
    }

    /**
     * Build a FolderContext whose intraVox() resolves $base, with $userLangValue
     * as the profile language (null = logged out). The real-content probe treats
     * a folder as "real" when it has a home.json without _generated.
     */
    private function context(
        Folder $base,
        ?string $userLangValue = 'en',
        string $primaryLanguage = 'en'
    ): FolderContext {
        // DI-first-class FolderContext: pass $base as the intraVoxOverride so the
        // real mount walk is bypassed. A null $userLangValue models logged-out —
        // reproduced by an empty userId, so userLanguage() short-circuits to 'en'
        // and intraVox() would throw "not logged in" IF no override were set. Here
        // an override IS set, so these tests exercise the composition over $base;
        // the logged-out throw is pinned separately via the real-mount path below.
        $userId = $userLangValue === null ? '' : 'tester';

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn($userLangValue ?? 'en');

        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('getPrimaryLanguage')->willReturn($primaryLanguage);

        return new FolderContext(
            $this->createMock(IRootFolder::class), // unused: $base override short-circuits the walk
            $userId,
            $config,
            $languageService,
            new LanguageResolver(),
            new PageLocator(
                $this->createMock(PageIndexService::class),
                $this->createMock(LoggerInterface::class)
            ),
            $base // intraVoxOverride
        );
    }

    /**
     * A FolderContext with NO intraVox override — so intraVox() runs the real
     * mount walk (rootFolder->getUserFolder(userId)->get('IntraVox')). Used to pin
     * the mount-resolution throws that the injected-seam builds bypassed.
     */
    private function realMountContext(IRootFolder $rootFolder, string $userId = 'tester'): FolderContext {
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturn('en');
        $languageService = $this->createMock(LanguageService::class);
        $languageService->method('getPrimaryLanguage')->willReturn('en');

        return new FolderContext(
            $rootFolder,
            $userId,
            $config,
            $languageService,
            new LanguageResolver(),
            new PageLocator(
                $this->createMock(PageIndexService::class),
                $this->createMock(LoggerInterface::class)
            )
        );
    }

    // ---------------------------------------------------------------- intraVox

    public function testLoggedOutIntraVoxThrows(): void {
        // No override + empty userId -> the real mount walk throws "not logged in".
        $ctx = $this->realMountContext($this->createMock(IRootFolder::class), userId: '');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('User not logged in');
        $ctx->intraVox();
    }

    public function testIntraVoxResolvesTheMountedFolder(): void {
        $base = $this->baseFolder([]);
        $ctx = $this->context($base);
        $this->assertSame($base, $ctx->intraVox());
    }

    /**
     * The real mount walk resolves rootFolder->getUserFolder(userId)->get('IntraVox').
     * Pins the resolution the injected-override builds bypass.
     */
    public function testIntraVoxWalksTheUserMount(): void {
        $intraVox = $this->baseFolder([]);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturnCallback(function ($p) use ($intraVox) {
            if ($p === 'IntraVox') {
                return $intraVox;
            }
            throw new NotFoundException($p);
        });
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->with('tester')->willReturn($userFolder);

        $ctx = $this->realMountContext($rootFolder);
        $this->assertSame($intraVox, $ctx->intraVox());
    }

    public function testIntraVoxThrowsWhenMountMissing(): void {
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willThrowException(new NotFoundException('IntraVox'));
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($userFolder);

        $ctx = $this->realMountContext($rootFolder);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('IntraVox folder not found');
        $ctx->intraVox();
    }

    public function testIntraVoxThrowsWhenMountIsNotAFolder(): void {
        // get('IntraVox') returns a File, not a Folder -> same not-found throw.
        $notAFolder = $this->createMock(File::class);
        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturn($notAFolder);
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($userFolder);

        $ctx = $this->realMountContext($rootFolder);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('IntraVox folder not found');
        $ctx->intraVox();
    }

    // ---------------------------------------------------------------- userLanguage

    public function testUserLanguageLoggedOutIsDefault(): void {
        $ctx = $this->context($this->baseFolder([]), userLangValue: null);
        $this->assertSame('en', $ctx->userLanguage());
    }

    public function testUserLanguageExtractsBaseCode(): void {
        $ctx = $this->context($this->baseFolder([]), userLangValue: 'nl_NL');
        $this->assertSame('nl', $ctx->userLanguage());
    }

    public function testUserLanguageMalformedFallsBack(): void {
        $ctx = $this->context($this->baseFolder([]), userLangValue: 'NL');
        $this->assertSame('en', $ctx->userLanguage());
    }

    // ---------------------------------------------------------------- languageFolderByCode (no-create read, L2-02)

    public function testLanguageFolderByCodeExistingReused(): void {
        $nl = $this->langFolder('/IntraVox/nl');
        $ctx = $this->context($this->baseFolder(['nl' => $nl]));
        $this->assertSame($nl, $ctx->languageFolderByCode('nl'));
        $this->assertSame([], $this->created);
    }

    public function testLanguageFolderByCodeMissingReturnsNullWithoutCreating(): void {
        $ctx = $this->context($this->baseFolder([]));
        $this->assertNull(
            $ctx->languageFolderByCode('nl'),
            'a missing language reads as "no content" — never a created folder (L2-02)'
        );
        $this->assertSame([], $this->created, 'the default must not be created either');
    }

    // ---------------------------------------------------------------- writeLanguageFolder (guarded create, L3-04)

    public function testWriteLanguageFolderReusesAnExistingFolder(): void {
        $es = $this->langFolder('/IntraVox/es');
        $ctx = $this->context($this->baseFolder(['es' => $es]));

        $this->assertSame($es, $ctx->writeLanguageFolder('es'));
        $this->assertSame([], $this->created, 'an existing folder is never re-created');
    }

    public function testWriteLanguageFolderCreatesTheRequestedCodeWhenCreatable(): void {
        $base = $this->baseFolder([]);
        $base->method('isCreatable')->willReturn(true);
        $ctx = $this->context($base);

        $ctx->writeLanguageFolder('es');

        $this->assertSame(['es'], $this->created, 'the REQUESTED code — a write by code never falls back to the default (that was the read-side bug of L2-02)');
    }

    public function testWriteLanguageFolderRefusesOnAReadOnlyMount(): void {
        $base = $this->baseFolder([]);
        $base->method('isCreatable')->willReturn(false);
        $ctx = $this->context($base);

        try {
            $ctx->writeLanguageFolder('es');
            $this->fail('a read-only member must not trigger newFolder() (issue #70)');
        } catch (NotFoundException $e) {
            $this->assertSame([], $this->created, 'nothing is created when the guard refuses');
        }
    }

    // ---------------------------------------------------------------- effectiveLanguage (#75)

    public function testEffectiveLanguageIsUserLanguageWithRealContent(): void {
        $nl = $this->langFolder('/IntraVox/nl', ['uniqueId' => 'page-home', 'title' => 'Welkom']);
        $ctx = $this->context($this->baseFolder(['nl' => $nl]), userLangValue: 'nl', primaryLanguage: 'nl');
        $this->assertSame('nl', $ctx->effectiveLanguage());
    }

    public function testEffectiveLanguageFallsToPrimaryThenEnglish(): void {
        // user nl (placeholder only) -> primary de (real) wins.
        $nl = $this->langFolder('/IntraVox/nl', ['title' => 'PH', '_generated' => true]);
        $de = $this->langFolder('/IntraVox/de', ['title' => 'Willkommen']);
        $ctx = $this->context($this->baseFolder(['nl' => $nl, 'de' => $de]), userLangValue: 'nl', primaryLanguage: 'de');
        $this->assertSame('de', $ctx->effectiveLanguage());
    }

    public function testEffectiveLanguageNullWhenNothingReal(): void {
        $nl = $this->langFolder('/IntraVox/nl', ['title' => 'PH', '_generated' => true]);
        $ctx = $this->context($this->baseFolder(['nl' => $nl]), userLangValue: 'nl', primaryLanguage: 'nl');
        $this->assertNull($ctx->effectiveLanguage());
    }

    // ---------------------------------------------------------------- readLanguageFolder

    public function testReadLanguageFolderReturnsResolved(): void {
        $nl = $this->langFolder('/IntraVox/nl', ['title' => 'Welkom']);
        $ctx = $this->context($this->baseFolder(['nl' => $nl]), userLangValue: 'nl', primaryLanguage: 'nl');
        $this->assertSame($nl, $ctx->readLanguageFolder());
    }

    public function testReadLanguageFolderNullWhenNothingReal(): void {
        // Nothing real resolves -> null. The old fallback returned the
        // write-target (creating it when nothing existed at all) — the
        // read-time phantom-folder generator L2-02 kills.
        $nl = $this->langFolder('/IntraVox/nl', ['title' => 'PH', '_generated' => true]);
        $ctx = $this->context($this->baseFolder(['nl' => $nl]), userLangValue: 'nl', primaryLanguage: 'nl');
        $this->assertNull($ctx->readLanguageFolder());
        $this->assertSame([], $this->created, 'a read must never materialize a folder');
    }

    public function testReadLanguageFolderNullOnContentlessBase(): void {
        $ctx = $this->context($this->baseFolder([]), userLangValue: 'nl', primaryLanguage: 'nl');
        $this->assertNull($ctx->readLanguageFolder(), 'a contentless install reads as no-content, not a created en/');
        $this->assertSame([], $this->created);
    }

    // ---- branches folded in from the retired PageLanguageResolutionTest (fase-10) ----
    // These exercise the same FolderContext front door and cover branches the tests
    // above do not: default-exists-so-no-create, ask-default-when-missing, the
    // user-language-driven languageFolder() write-target create-on-miss, and the
    // #75 skip-past-a-missing-candidate-folder continue.

    public function testLanguageFolderByCodeMissingReturnsNullEvenWhenDefaultExists(): void {
        // 'nl' is missing, 'en' exists — the OLD code returned the en folder
        // (serving en content under a path claiming nl); L2-02 reads it as
        // "no content in this language".
        $en = $this->langFolder('/IntraVox/en');
        $ctx = $this->context($this->baseFolder(['en' => $en]));

        $this->assertNull($ctx->languageFolderByCode('nl'));
        $this->assertSame([], $this->created);
    }

    public function testLanguageFolderByCodeAskingForMissingDefaultReturnsNull(): void {
        $ctx = $this->context($this->baseFolder([]));
        $this->assertNull($ctx->languageFolderByCode('en'));
        $this->assertSame([], $this->created, 'even the default is never created by a read (L2-02)');
    }

    public function testLanguageFolderUsesUserLanguageAndCreatesOnMiss(): void {
        // The write-target languageFolder() resolves the USER's language (nl_NL -> nl)
        // and, when neither nl nor the default exist, creates the default.
        // (L2-02 keeps the write target's create-on-miss — only the READ accessors went no-create.)
        $ctx = $this->context($this->baseFolder([]), userLangValue: 'nl_NL');

        $ctx->languageFolder();

        $this->assertSame(['en'], $this->created);
    }

    public function testEffectiveLanguageFallsToEnglishWhenUserAndPrimaryAreEmpty(): void {
        // #75 rule 3: neither the user's language nor the primary has real content,
        // but English does -> serve English.
        $nl = $this->langFolder('/IntraVox/nl', ['title' => 'PH', '_generated' => true]);
        $en = $this->langFolder('/IntraVox/en', ['uniqueId' => 'page-home', 'title' => 'Welcome']);
        $ctx = $this->context(
            $this->baseFolder(['nl' => $nl, 'en' => $en]),
            userLangValue: 'nl',
            primaryLanguage: 'nl'
        );

        $this->assertSame('en', $ctx->effectiveLanguage());
    }

    public function testEffectiveLanguageSkipsMissingCandidateFolders(): void {
        // primary 'de' folder does not exist on disk at all (get throws NotFound);
        // the loop must `continue` past it and land on English.
        $en = $this->langFolder('/IntraVox/en', ['uniqueId' => 'page-home', 'title' => 'Welcome']);
        $ctx = $this->context(
            $this->baseFolder(['en' => $en]),
            userLangValue: 'nl',
            primaryLanguage: 'de'
        );

        $this->assertSame('en', $ctx->effectiveLanguage());
    }

    /**
     * Validation #2 end to end: a roster user (no core/lang → 'en') on a fresh
     * clinic (primary_language unset) over es-only content. With a REAL
     * LanguageService the chain is ['en', 'es'], so es serves — the home,
     * tree and news resolve es — and nothing is created.
     */
    public function testFreshClinicRosterUserIsServedEsThroughThePrimaryDefault(): void {
        $es = $this->langFolder('/IntraVox/es', ['title' => 'Inicio']);

        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnArgument(3);   // no core/lang: the caller's default
        $config->method('getAppValue')->willReturn('');           // primary_language unset

        $ctx = new FolderContext(
            $this->createMock(IRootFolder::class),
            'director',
            $config,
            new LanguageService(
                $config,
                $this->createMock(\OCP\L10N\IFactory::class),
                $this->createMock(LoggerInterface::class),
                $this->createMock(\OCA\IntraVox\Service\Cache\PageCacheService::class),
                new LanguageResolver()
            ),
            new LanguageResolver(),
            new PageLocator(
                $this->createMock(PageIndexService::class),
                $this->createMock(LoggerInterface::class)
            ),
            $this->baseFolder(['es' => $es])
        );

        $this->assertSame('en', $ctx->userLanguage(), 'no core/lang reads as en');
        $this->assertSame('es', $ctx->effectiveLanguage(), 'the es primary default puts es in the chain');
        $this->assertSame($es, $ctx->readLanguageFolder());
        $this->assertSame([], $this->created, 'nothing is created');
    }
}
