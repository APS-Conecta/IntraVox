<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service;

use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Creates (and removes) the per-language content folder inside the IntraVox
 * GroupFolder. Shared between the `intravox:create-language-homepages` OCC
 * command (bulk) and the LanguageController (on-demand, when an admin adds or
 * removes a language in the admin UI).
 *
 * Idempotent: if `home.json` already exists in the target language folder, the
 * create method returns without overwriting user content.
 *
 * Content is rendered in the requested language for the four bundled
 * translations (nl/en/de/fr) and falls back to English for all others — until
 * Transifex catches up with bundled defaults for those languages, this is
 * intentional and acceptable.
 *
 * The GroupFolder is reached via SetupService::getSharedFolder() (the same path
 * demo-data installation uses), NOT via a user folder — there is no dedicated
 * `intravox` system user, so getUserFolder('intravox') fails with "Backends
 * provided no user object" and the homepage was never actually written.
 */
class LanguageHomepageService {
    private SetupService $setupService;
    private LoggerInterface $logger;

    public function __construct(
        SetupService $setupService,
        LoggerInterface $logger
    ) {
        $this->setupService = $setupService;
        $this->logger = $logger;
    }

    /**
     * Result type for the operation.
     *
     * @return array{success: bool, created: bool, message: string}
     *   - success: did the call complete without error?
     *   - created: was a new home.json file actually written? (false when one already existed)
     *   - message: human-readable summary
     */
    public function createEmptyHomepage(string $lang): array {
        try {
            // getSharedFolder() IS the IntraVox content root (the GroupFolder's
            // `files` dir), so language folders live directly inside it.
            $contentRoot = $this->setupService->getSharedFolder();

            // Get-or-create the language folder.
            try {
                $langFolder = $contentRoot->get($lang);
            } catch (NotFoundException $e) {
                $langFolder = $contentRoot->newFolder($lang);
                $langFolder->newFolder('images');
            }

            // Idempotent: never overwrite existing user content.
            try {
                $langFolder->get('home.json');
                return [
                    'success' => true,
                    'created' => false,
                    'message' => "home.json already exists in {$lang}, skipped",
                ];
            } catch (NotFoundException $e) {
                $content = $this->setupService->getDefaultHomePageContent($lang);
                $file = $langFolder->newFile('home.json');
                $file->putContent(json_encode($content, JSON_PRETTY_PRINT));
            }

            // The new folder is written via the Files API, but a user's mounted
            // view of the GroupFolder reads from the file cache, which isn't
            // updated for the new path until a scan. Rescan synchronously so the
            // language shows up immediately in every view.
            $this->setupService->rescanGroupfolderSync();

            return [
                'success' => true,
                'created' => true,
                'message' => "Created home.json for {$lang}",
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[LanguageHomepageService] Failed to create homepage for ' . $lang . ': ' . $e->getMessage());
            return [
                'success' => false,
                'created' => false,
                'message' => 'Failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Remove a language's entire content folder from the IntraVox GroupFolder.
     * Deletes `<lang>/` and everything in it (pages, images). The folder goes to
     * the GroupFolder trash, so it can be restored from Files if needed.
     *
     * @return array{success: bool, removed: bool, message: string}
     *   - removed: was a folder actually deleted? (false when none existed)
     */
    public function removeLanguage(string $lang): array {
        try {
            $contentRoot = $this->setupService->getSharedFolder();

            if (!$contentRoot->nodeExists($lang)) {
                return [
                    'success' => true,
                    'removed' => false,
                    'message' => "No content folder for {$lang}, nothing to remove",
                ];
            }

            $contentRoot->get($lang)->delete();

            // Rescan synchronously so the deletion propagates to every user's
            // mounted view; without this the removed folder lingers as a stale
            // (unreadable) entry in the file cache and the Files app keeps
            // showing it.
            $this->setupService->rescanGroupfolderSync();

            return [
                'success' => true,
                'removed' => true,
                'message' => "Removed content folder for {$lang}",
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[LanguageHomepageService] Failed to remove language ' . $lang . ': ' . $e->getMessage());
            return [
                'success' => false,
                'removed' => false,
                'message' => 'Failed: ' . $e->getMessage(),
            ];
        }
    }
}
