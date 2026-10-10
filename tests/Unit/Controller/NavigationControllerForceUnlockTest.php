<?php

declare(strict_types=1);

namespace OCA\IntraVox\Tests\Unit\Controller;

use OCA\IntraVox\Controller\NavigationController;
use OCA\IntraVox\Service\NavigationService;
use OCA\IntraVox\Service\PermissionService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * IntraVox#11: «Desbloquear» showed to every account with write access on the root, but
 * PageLockController::forceReleaseLock answers 403 unless PermissionService::isAdmin().
 * The navigation payload carries the server's own answer, so the button and the endpoint
 * cannot disagree.
 */
class NavigationControllerForceUnlockTest extends TestCase {
    private function navigationFor(bool $isAdmin): array {
        $navigation = $this->createMock(NavigationService::class);
        $navigation->method('getCurrentLanguage')->willReturn('es');
        $navigation->method('getNavigation')->willReturn(['type' => 'dropdown', 'items' => []]);
        $navigation->method('canEdit')->willReturn(true);

        $permissions = $this->createMock(PermissionService::class);
        // An editor: write on the root, but not the full set isAdmin() demands.
        $permissions->method('getFolderPermissions')->willReturn(
            ['canRead' => true, 'canWrite' => true, 'canCreate' => true, 'canDelete' => false, 'canShare' => false, 'raw' => 7]
        );
        $permissions->method('buildPagePathMap')->willReturn([]);
        $permissions->method('filterNavigation')->willReturn([]);
        $permissions->method('isAdmin')->willReturn($isAdmin);

        $controller = new NavigationController(
            'intravox',
            $this->createMock(IRequest::class),
            $navigation,
            $permissions,
            $this->createMock(IL10N::class),
            $this->createMock(LoggerInterface::class)
        );

        return $controller->get()->getData();
    }

    public function testAnEditorWithoutFullRootPermissionsCannotForceUnlock(): void {
        $this->assertFalse($this->navigationFor(false)['canForceUnlock']);
    }

    public function testAnIntranetAdminCanForceUnlock(): void {
        $this->assertTrue($this->navigationFor(true)['canForceUnlock']);
    }
}
