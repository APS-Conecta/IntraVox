<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\Cache\PageCacheService;
use OCA\IntraVox\Service\Language\LanguageResolver;
use OCA\IntraVox\Service\LanguageService;
use OCP\IConfig;
use OCP\L10N\IFactory as IL10NFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The admin checkbox grid must name languages the same way folders and
 * content resolution do (L2-05).
 *
 * getLanguagesWithMetadata() used to truncate NC codes to 2 chars while
 * getAvailableLanguages() deliberately does NOT — some NC base codes are 3
 * letters (the repo ships l10n/ast.json and l10n/kab.json), and truncation
 * turned them into dead keys ('as'/'ka') whose display-name lookup always
 * missed, so the grid showed 'AST'/'KAB' instead of real names. The two
 * methods now share the one extraction rule; these tests pin that.
 */
class LanguageServiceBaseCodeTest extends TestCase {

    private function service(array $ncLanguages): LanguageService {
        $l10n = $this->createMock(IL10NFactory::class);
        $l10n->method('getLanguages')->willReturn($ncLanguages);

        return new LanguageService(
            $this->createMock(IConfig::class),
            $l10n,
            $this->createMock(LoggerInterface::class),
            $this->createMock(PageCacheService::class),
            new LanguageResolver()
        );
    }

    public function testMetadataNamesThreeLetterBaseCodes(): void {
        $service = $this->service([
            'commonLanguages' => [
                ['code' => 'ast', 'name' => 'Asturianu'],
            ],
            'otherLanguages' => [],
        ]);

        $byCode = [];
        foreach ($service->getLanguagesWithMetadata() as $lang) {
            $byCode[$lang['code']] = $lang['name'];
        }

        // l10n/ast.json is a discovered language on this checkout, so 'ast'
        // is in the grid; the fixed extraction keys its name by the FULL
        // base code. The old truncation looked up 'as' and missed.
        self::assertSame(
            'Asturianu',
            $byCode['ast'] ?? null,
            'a 3-letter NC base code (ast) must map to its real display name — '
            . 'the old substr(0,2) truncation turned it into a dead "as" key'
        );
        self::assertArrayNotHasKey('as', $byCode, 'no truncated dead keys');
    }

    public function testAvailableLanguagesKeepThreeLetterCodesWhole(): void {
        $service = $this->service([
            'commonLanguages' => [
                ['code' => 'kab', 'name' => 'Taqbaylit'],
            ],
            'otherLanguages' => [],
        ]);

        $codes = array_column($service->getAvailableLanguages(), 'code', 'code');
        self::assertArrayHasKey(
            'kab',
            $codes,
            'getAvailableLanguages must keep 3-letter base codes whole — already '
            . 'documented as correct; pinned so the two methods cannot drift again'
        );
        self::assertArrayNotHasKey('ka', $codes);
    }

    /** L2-01: the unset-key default is the deployment reality — es + the en floor. */
    public function testEnabledLanguagesDefaultToTheDeploymentReality(): void {
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('');  // key unset — the fresh-install shape

        $service = new LanguageService(
            $config,
            $this->createMock(IL10NFactory::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(PageCacheService::class),
            new LanguageResolver()
        );

        self::assertSame(['en', 'es'], $service->getEnabledLanguages(), 'sorted; es joins the non-removable en floor');
        self::assertTrue($service->isLanguageEnabled('es'), 'es-profile users route to es on fresh installs (feed/footer)');
    }
}
