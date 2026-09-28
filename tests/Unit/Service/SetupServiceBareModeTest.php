<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\GroupFolders\GroupFoldersGateway;
use OCA\IntraVox\Service\Language\LanguageResolver;
use OCA\IntraVox\Service\LanguageService;
use OCA\IntraVox\Service\SetupService;
use OCA\IntraVox\Tests\Mocks\MockUserSession;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * es is the deployment's default language, and --skip-demo means NO content
 * (L1-02 + L1-03).
 *
 * The phantom-tree generators this pins shut: setup used to special-case
 * nl/en detection (a Chilean install detected 'en') and create a boilerplate
 * tree in the detected language even under --skip-demo, so a fresh managed
 * install had a language folder full of English boilerplate before the seed
 * ever ran — content the developer never chose. Bare mode: the groupfolder,
 * groups and grants converge, and the tree is left to the import.
 */
class SetupServiceBareModeTest extends TestCase {

    private const MARKER = 'admin_access_provisioned';

    /**
     * The grants table in configureGroupfolderPermissions() reads
     * \OCP\Constants, which the unit suite's OCP stubs do not carry (see
     * SetupProvisioningOnceTest's fail-loud pin). The real class is a
     * dependency-free constants holder shipped in nextcloud/ocp; loading it
     * lets the whole setup chain run. SetupService is its only runtime reader.
     */
    public static function setUpBeforeClass(): void {
        if (!class_exists(\OCP\Constants::class)) {
            require_once __DIR__ . '/../../../vendor/nextcloud/ocp/OCP/Constants.php';
        }
    }

    /** @param array<string,string> $store config values, remembered (also collects writes) */
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
        $config->method('getSystemValue')
            ->willReturnCallback(static function (string $key, $default = '') use (&$store) {
                return $store['system_' . $key] ?? $default;
            });
        return $config;
    }

    /** A group manager whose IntraVox Admins group contains one enabled member (alice). */
    private function groupManagerWithAdminMember(): IGroupManager {
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');
        $alice->method('isEnabled')->willReturn(true);

        $intraVoxAdmins = $this->createMock(IGroup::class);
        $intraVoxAdmins->method('getUsers')->willReturn([$alice]);

        $empty = $this->createMock(IGroup::class);
        $empty->method('getUsers')->willReturn([]);

        return new class($intraVoxAdmins, $empty) implements IGroupManager {
            public function __construct(private IGroup $admins, private IGroup $empty) {}
            public function get(string $gid): ?IGroup {
                return $gid === 'IntraVox Admins' ? $this->admins : $this->empty;
            }
            public function groupExists(string $gid): bool { return true; }
            public function createGroup(string $gid): ?IGroup { return null; }
            public function isAdmin(string $uid): bool { return false; }
            public function isInGroup(string $uid, string $gid): bool { return false; }
            public function getUserGroupIds(IUser $user): array { return []; }
        };
    }

    /**
     * A folder whose every miss is a NotFoundException and whose creations
     * are recorded. By-ref closures — the house recorder idiom
     * (SetupProvisioningOnceTest's use (&$seeded)); arrow fns capture by
     * value and would silently drop every recording.
     */
    private function folderRecording(array &$folders, array &$files): Folder {
        $folder = $this->createMock(Folder::class);
        $folder->method('get')->willReturnCallback(
            static function (string $name) {
                throw new NotFoundException($name);
            }
        );
        $folder->method('nodeExists')->willReturn(false);
        $folder->method('newFolder')->willReturnCallback(
            function (string $name) use (&$folders, &$files): Folder {
                $folders[] = $name;
                return $this->folderRecording($folders, $files);
            }
        );
        $folder->method('newFile')->willReturnCallback(
            function (string $name) use (&$files): File {
                $files[] = $name;
                return $this->createMock(File::class);
            }
        );
        return $folder;
    }

    /** The full setup chain over mocks: gateway creates folder 20, alice's mount resolves it. */
    private function setupService(IConfig $config, array &$folders, array &$files): SetupService {
        $recordingRoot = $this->folderRecording($folders, $files);

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('get')->willReturnCallback(
            static function (string $name) use ($recordingRoot) {
                if ($name === 'IntraVox') {
                    return $recordingRoot;
                }
                throw new NotFoundException($name);
            }
        );

        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('getUserFolder')->willReturn($userFolder);

        $gateway = $this->createMock(GroupFoldersGateway::class);
        $gateway->method('isAvailable')->willReturn(true);
        $gateway->method('findFolderIdByMountPoint')->willReturn(null);
        $gateway->method('createFolder')->willReturn(20);
        $gateway->method('folderManager')->willReturn(new class {
            public function addApplicableGroup(int $folderId, string $group): void {}
            public function setGroupPermissions(int $folderId, string $group, int $permissions): void {}
        });

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturn(true);

        return new SetupService(
            $rootFolder,
            $config,
            $this->createMock(LoggerInterface::class),
            new MockUserSession(),
            $this->createMock(IShareManager::class),
            $this->groupManagerWithAdminMember(),
            $this->createMock(LanguageService::class),
            $appManager,
            new LanguageResolver(),
            $gateway,
        );
    }

    public function testDetectionIsEsRegardlessOfSystemLanguage(): void {
        $folders = [];
        $files = [];
        $store = ['system_default_language' => 'nl'];
        $service = $this->setupService($this->configRemembering($store), $folders, $files);

        self::assertSame('es', $service->detectDefaultLanguage(), 'a Dutch system language must not map to nl');

        $store['system_default_language'] = 'es_CL';
        self::assertSame('es', $service->detectDefaultLanguage(), 'es is the default regardless of detection');
    }

    public function testBareSetupCreatesNoContent(): void {
        $store = [];
        $folders = [];
        $files = [];
        $service = $this->setupService($this->configRemembering($store), $folders, $files);

        $result = $service->setupSharedFolder(true);

        self::assertTrue($result['success'], 'bare setup must still converge infrastructure');
        self::assertSame([], $folders, 'bare setup must create no language folder and no _resources/_templates');
        self::assertSame([], $files, 'bare setup must write no home.json');
        self::assertSame('true', $store[self::MARKER] ?? null, 'bare setup still completes provisioning (the marker is written)');
    }

    public function testFullSetupCreatesTheEsBoilerplateTree(): void {
        $store = [];
        $folders = [];
        $files = [];
        $service = $this->setupService($this->configRemembering($store), $folders, $files);

        $result = $service->setupSharedFolder();

        self::assertTrue($result['success']);
        self::assertContains('es', $folders, 'full mode lands the boilerplate tree in es (L1-02), never en');
        self::assertNotContains('en', $folders);
        self::assertContains('home.json', $files);
        self::assertContains('_resources', $folders);
        self::assertContains('_templates', $folders);
    }
}
