<?php
declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Service;

use OCA\IntraVox\Service\LanguageService;
use OCA\IntraVox\Service\SetupService;
use OCA\IntraVox\Tests\Mocks\MockUserSession;
use OCP\App\IAppManager;
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
 * Admin access is provisioned once, not re-applied on every update (#113).
 *
 * The reported behaviour: every member of the Nextcloud `admin` group was
 * silently a member of "IntraVox Admins", and removing someone worked until the
 * next app update put them back. The same held for the `admin` group's rights on
 * the Team folder, which were reset to PERMISSION_ALL each time.
 *
 * Both came from the same place. Setup runs as an IRepairStep, which Nextcloud
 * executes on EVERY app update rather than only at install, and setup seeded the
 * group and stamped the permissions unconditionally. The intent was sound — an
 * install whose owner leaves should not become unmanageable — but enforcing it
 * forever takes a decision away from the administrator that is theirs to make.
 * As the reporter put it, there is a difference between *being able to* grant
 * yourself rights and *having* them by default.
 *
 * So provisioning is now guarded by an app-config marker, and these tests pin
 * the two halves of that: the first run still seeds, and a second run leaves
 * everything alone.
 *
 * What this is NOT: a security boundary. A Nextcloud admin can always add
 * themselves back, and nothing here prevents that. What changes is that they no
 * longer get it by default, and a deliberate removal survives an update.
 */
class SetupProvisioningOnceTest extends TestCase {

    private const ADMIN_GROUP = 'IntraVox Admins';
    private const MARKER = 'admin_access_provisioned';

    /**
     * A config double that behaves like the real one: reads return what was
     * written, and the default is honoured for keys never set.
     *
     * A plain mock with willReturn() cannot express "false until someone writes
     * true", which is the entire behaviour under test.
     */
    private function configRemembering(array $seed = []): IConfig {
        $store = $seed;
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')
            ->willReturnCallback(
                static fn (string $app, string $key, string $default = '')
                    => $store[$key] ?? $default
            );
        $config->method('setAppValue')
            ->willReturnCallback(
                static function (string $app, string $key, string $value) use (&$store): void {
                    $store[$key] = $value;
                }
            );
        return $config;
    }

    /**
     * A group manager whose IntraVox Admins group records addUser() calls.
     *
     * MockGroupManager cannot serve here: its get() returns a fresh MockGroup
     * each call, so addUser() writes to an object that is immediately discarded
     * and membership never changes. Asserting on the CALL is also closer to the
     * question anyway — did setup try to seed, yes or no.
     *
     * @param list<string> $seeded collects uids handed to addUser()
     */
    private function groupManagerRecording(array &$seeded): IGroupManager {
        $alice = $this->createMock(IUser::class);
        $alice->method('getUID')->willReturn('alice');

        $ncAdmins = $this->createMock(IGroup::class);
        $ncAdmins->method('getUsers')->willReturn([$alice]);

        $intraVoxAdmins = $this->createMock(IGroup::class);
        $intraVoxAdmins->method('inGroup')->willReturn(false);
        $intraVoxAdmins->method('addUser')
            ->willReturnCallback(
                static function (IUser $user) use (&$seeded): void {
                    $seeded[] = $user->getUID();
                }
            );

        // Hand-written rather than createMock(): groupExists() and createGroup()
        // are not on the OCP stub the unit suite loads, so they cannot be
        // configured on a generated double -- the same reason MockGroupManager
        // spells them out.
        return new class($ncAdmins, $intraVoxAdmins) implements IGroupManager {
            public function __construct(
                private IGroup $ncAdmins,
                private IGroup $intraVoxAdmins,
            ) {
            }

            public function get(string $gid): ?IGroup {
                return $gid === 'admin' ? $this->ncAdmins : $this->intraVoxAdmins;
            }

            public function groupExists(string $gid): bool {
                return true;
            }

            public function createGroup(string $gid): ?IGroup {
                return null;
            }

            public function isAdmin(string $uid): bool {
                return $uid === 'alice';
            }

            public function isInGroup(string $uid, string $gid): bool {
                return false;
            }

            public function getUserGroupIds(IUser $user): array {
                return [];
            }
        };
    }

    private function service(IConfig $config, IGroupManager $groups): SetupService {
        return new SetupService(
            $this->createMock(IRootFolder::class),
            $config,
            $this->createMock(LoggerInterface::class),
            new MockUserSession(),
            $this->createMock(IShareManager::class),
            $groups,
            $this->createMock(LanguageService::class),
            $this->createMock(IAppManager::class),
            new \OCA\IntraVox\Service\Language\LanguageResolver(),
        );
    }

    /** Reach the private seeding step directly; setupSharedFolder() needs a server. */
    private function seed(SetupService $service): void {
        $method = (new \ReflectionClass($service))->getMethod('ensureGroupsExist');
        $method->setAccessible(true);
        $method->invoke($service);
    }

    /**
     * First install: the Nextcloud admins are seeded into IntraVox Admins.
     *
     * The behaviour that must survive this change — a fresh install still has a
     * reachable administrator.
     */
    public function testFirstRunSeedsNextcloudAdmins(): void {
        $seeded = [];
        $this->seed($this->service(
            $this->configRemembering(),
            $this->groupManagerRecording($seeded)
        ));

        self::assertSame(
            ['alice'],
            $seeded,
            'a fresh install must put the Nextcloud admins in IntraVox Admins, '
            . 'or nobody can administer the app'
        );
    }

    /**
     * The actual #113 assertion: a second run does not undo a removal.
     *
     * Seeding once and stopping is only worth anything if the marker is honoured
     * afterwards. With the guard removed this fails, because ensureGroupsExist()
     * re-adds alice exactly as it did on every update before.
     */
    public function testSecondRunLeavesGroupMembershipAlone(): void {
        $seeded = [];
        $this->seed($this->service(
            // The state an administrator deliberately created: alice administers
            // the server but not the intranet.
            $this->configRemembering([self::MARKER => 'true']),
            $this->groupManagerRecording($seeded)
        ));

        self::assertSame(
            [],
            $seeded,
            'an app update re-added a Nextcloud admin to IntraVox Admins. '
            . 'That is #113: the administrator removed them on purpose, and '
            . 'the next update silently overruled it'
        );
    }

    /**
     * Upgrading an existing installation must not lock anyone out.
     *
     * Installations from before this change have no marker, so the first run
     * after upgrading looks like a first install and seeds again — which is
     * exactly right: everyone who had access keeps it, and only then does the
     * overwriting stop.
     */
    public function testUpgradeFromBeforeThisChangeStillSeedsOnce(): void {
        $seeded = [];
        // No marker: the shape of every installation that predates this fix.
        $this->seed($this->service(
            $this->configRemembering(),
            $this->groupManagerRecording($seeded)
        ));

        self::assertSame(
            ['alice'],
            $seeded,
            'upgrading must not strip access from an installation that had it'
        );
    }

    /**
     * The marker is written by setupSharedFolder(), not by the seeding step.
     *
     * Both halves of the fix read it, and the permissions half runs later in the
     * same call. If seeding stamped it, the permissions step would see "already
     * provisioned" on a first install and skip granting any rights at all —
     * shipping an empty Team folder nobody can write to.
     */
    public function testSeedingDoesNotWriteTheMarkerItself(): void {
        $seeded = [];
        $groups = $this->groupManagerRecording($seeded);

        $written = [];
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturn('false');
        $config->method('setAppValue')
            ->willReturnCallback(
                static function (string $app, string $key, string $value) use (&$written): void {
                    $written[] = $key;
                }
            );

        $this->seed($this->service($config, $groups));

        self::assertNotContains(
            self::MARKER,
            $written,
            'the seeding step wrote the provisioning marker. The permissions '
            . 'step reads it later in the same run and would then skip granting '
            . 'rights on a first install'
        );
    }

    /**
     * A failed groupfolder-permission write fails the whole setup (L1-04).
     *
     * configureGroupfolderPermissions() used to catch-and-log every exception,
     * so a half-configured install (groupfolder exists, no group has a mount)
     * reported success=true — the seam's FATAL guard could never fire and the
     * clinic's welcome screen was invisible while every log line was green.
     * The swallow is gone: the failure must surface as success:false, and the
     * provisioning marker must NOT be written (a half-run retries in full).
     *
     * The error key is the discriminating assertion. With the swallow in place
     * setup carries on past the permission step and still ends success:false
     * here — but later, at STEP 5, as 'groupfolder_access_failed' (the root
     * folder double below has no groupfolder to hand out). Only the fail-loud
     * path reports 'setup_exception', the key setupSharedFolder()'s catch maps
     * a propagated exception to.
     */
    public function testPermissionWriteFailureFailsSetupInsteadOfSwallowing(): void {
        $gateway = $this->createMock(\OCA\IntraVox\Service\GroupFolders\GroupFoldersGateway::class);
        $gateway->method('isAvailable')->willReturn(true);
        $gateway->method('findFolderIdByMountPoint')->willReturn(20);
        // The wiring failure under test: a plain (non-DBException) error from
        // the groupfolders manager the permission step resolves. (Thrown by
        // folderManager() itself: a double whose addApplicableGroup() throws
        // cannot be reached here, because the grants table before that call
        // reads \OCP\Constants, which the unit suite's OCP stubs do not carry.)
        $gateway->method('folderManager')
            ->willThrowException(new \Exception('groupfolders wiring failed (simulated DB hiccup)'));

        // No groupfolder to resolve: without the fix, setup reaches STEP 5 and
        // stops there with a different error key (see the docblock).
        $rootFolder = $this->createMock(IRootFolder::class);
        $rootFolder->method('get')->willThrowException(new NotFoundException('__groupfolders'));

        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturn(true);

        $written = [];
        $config = $this->createMock(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = '') => $key === self::MARKER ? 'false' : $default
        );
        $config->method('setAppValue')->willReturnCallback(
            static function (string $app, string $key, string $value) use (&$written): void {
                $written[] = $key;
            }
        );

        $seeded = [];
        $service = new SetupService(
            $rootFolder,
            $config,
            $this->createMock(LoggerInterface::class),
            new MockUserSession(),
            $this->createMock(IShareManager::class),
            $this->groupManagerRecording($seeded),
            $this->createMock(LanguageService::class),
            $appManager,
            new \OCA\IntraVox\Service\Language\LanguageResolver(),
            $gateway,
        );

        $result = $service->setupSharedFolder();

        self::assertFalse(
            $result['success'],
            'a permission-write failure must fail setup (L1-04 fail-loud): the old '
            . 'catch-and-log shipped half-configured installs with green logs'
        );
        self::assertSame(
            'setup_exception',
            $result['error'] ?? null,
            'the permission failure must propagate to setupSharedFolder()\'s catch; '
            . 'any other key means setup swallowed it and failed (or not) later'
        );
        self::assertNotContains(
            self::MARKER,
            $written,
            'a failed run must not stamp admin_access_provisioned — a retry '
            . 'must run in full, not leave a half-provisioned install marked done'
        );
    }
}
