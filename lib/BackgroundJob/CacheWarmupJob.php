<?php
declare(strict_types=1);

namespace OCA\IntraVox\BackgroundJob;

use OCA\IntraVox\AppInfo\Application;
use OCA\IntraVox\Service\NavigationService;
use OCA\IntraVox\Service\PermissionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Pre-warms IntraVox caches so the first user of the day doesn't pay the
 * cold-cache cost on the homepage/navigation render.
 *
 * The distributed caches we maintain (intravox-pages tree + content,
 * intravox-permissions path-map) are wiped on every page mutation, so
 * post-deploy or post-mutation periods have a few hundred ms of cold
 * latency until the first reader rebuilds them. For enterprise instances
 * with thousands of users hitting `/apps/intravox/` simultaneously after
 * a deploy, that "first reader" becomes a thundering herd.
 *
 * This job runs every 15 minutes and forces a rebuild for each language
 * that has a content folder (L4-02 — derived from the folders that exist,
 * never a hard-coded list) by walking the same code paths as a real
 * request:
 * - PermissionService::buildPagePathMap → caches per-language path map
 * - PageTreeService::getPageTree → caches per-group tree
 *
 * Both of those store their result via distributed cache, so subsequent
 * real users see warm hits regardless of which front-end node serves
 * their request.
 *
 * Marked TIME_INSENSITIVE so it doesn't compete with user-facing cron
 * windows.
 */
class CacheWarmupJob extends TimedJob {
    private const INTERVAL_MINUTES = 15;

    public function __construct(
        ITimeFactory $time,
        private PermissionService $permissionService,
        private NavigationService $navigationService,
        private LoggerInterface $logger,
        private \OCA\IntraVox\Service\Tree\PageTreeService $treeService,
        private \OCA\IntraVox\Service\SetupService $setupService,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_MINUTES * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /**
     * The languages that actually have content (L4-02, M4): the groupfolder's
     * language subfolders — derived from reality, not a hard-coded list.
     * The old const warmed nl/en/de/fr every 15 minutes and NEVER the
     * deployment's only real language (es). System context via
     * getSharedFolder() (no user mount exists in a background job); the
     * 2-3-letter shape mirrors PageMaintenanceService's language-folder
     * walk. A pre-seed fresh install has no language folders and warms
     * nothing — correct: there is no content to keep warm.
     *
     * @return list<string>
     */
    private function languagesWithContent(): array {
        try {
            $root = $this->setupService->getSharedFolder();
        } catch (\Exception $e) {
            $this->logger->warning('[IntraVox] CacheWarmupJob could not resolve the groupfolder: ' . $e->getMessage());
            return [];
        }
        $languages = [];
        foreach ($root->getDirectoryListing() as $node) {
            if ($node instanceof \OCP\Files\Folder && preg_match('/^[a-z]{2,3}$/', $node->getName())) {
                $languages[] = $node->getName();
            }
        }
        return $languages;
    }

    protected function run($argument): void {
        $warmed = [];
        $errors = [];

        foreach ($this->languagesWithContent() as $lang) {
            try {
                $this->permissionService->buildPagePathMap($lang);
                $this->treeService->getPageTree(null, $lang);
                $this->navigationService->getNavigation($lang);
                $warmed[] = $lang;
            } catch (\Throwable $e) {
                // Failure to warm one language must not block the rest —
                // a missing or empty language folder is normal on instances
                // that aren't multi-lingual.
                $errors[$lang] = $e->getMessage();
            }
        }

        $this->logger->info('[IntraVox] CacheWarmupJob ran', [
            'warmed' => $warmed,
            'errors' => $errors,
        ]);
    }
}
