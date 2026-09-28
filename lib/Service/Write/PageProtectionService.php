<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service\Write;

use OCA\IntraVox\Exception\PageNotFoundException;
use OCA\IntraVox\Service\Cache\PageCacheInvalidator;
use OCA\IntraVox\Service\Folder\FolderContext;
use OCA\IntraVox\Service\Locator\PageLocator;
use OCA\IntraVox\Service\Version\PageVersionService;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Structural protection — the welcome tree's "walls" (review L0-04 / L4-01).
 *
 * A page whose JSON carries `protected: true` refuses delete and move
 * (PageWriteService::deletePage, PageStructureService::movePage) and is exposed
 * to the UI as `protected` (permissions stay the filesystem truth). The flag is written by exactly two hands: gestion's
 * renderer, when a section is declared `wall`, and this service through
 * `occ intravox:protect` — the deliberate two-step an admin takes before
 * removing a wall. A save never writes it (PageShapeSanitizer keeps it,
 * updatePage carries the stored value over the client's).
 */
final class PageProtectionService {
    public function __construct(
        private FolderContext $folders,
        private PageLocator $locator,
        private PageVersionService $versions,
        private PageCacheInvalidator $cache,
    ) {
    }

    /** The one reading of the marker every guard shares. */
    public static function isProtected(array $pageData): bool {
        return ($pageData['protected'] ?? false) === true;
    }

    /**
     * @return array{uniqueId:string, path:string, protected:bool}
     * @throws PageNotFoundException when no language folder holds the id
     */
    public function status(string $uniqueId): array {
        [$folder, $file, $data] = $this->locate($uniqueId);
        return ['uniqueId' => $uniqueId, 'path' => $this->pathOf($folder, $file), 'protected' => self::isProtected($data)];
    }

    /**
     * Raise or remove a wall. Idempotent: an already-matching page is not
     * rewritten (no version, no write). Otherwise a version is snapshotted
     * first, exactly as updatePage() does, so the flip is reversible from the
     * page's history.
     *
     * @return array{uniqueId:string, path:string, protected:bool, changed:bool}
     * @throws PageNotFoundException
     */
    public function setProtected(string $uniqueId, bool $on): array {
        [$folder, $file, $data] = $this->locate($uniqueId);
        $path = $this->pathOf($folder, $file);
        if (self::isProtected($data) === $on) {
            return ['uniqueId' => $uniqueId, 'path' => $path, 'protected' => $on, 'changed' => false];
        }
        if ($on) {
            $data['protected'] = true;
        } else {
            unset($data['protected']);
        }
        $this->versions->createBeforeUpdate($file);
        $file->putContent(json_encode($data, JSON_PRETTY_PRINT));
        // Same key discipline as updatePage(): the page cache is keyed by the id
        // it was read with — here always the uniqueId (a slug alias, if one was
        // ever read, ages out like it does after any other write).
        $this->cache->invalidate($uniqueId);
        return ['uniqueId' => $uniqueId, 'path' => $path, 'protected' => $on, 'changed' => true];
    }

    /**
     * @return array{0:Folder,1:File,2:array}
     * @throws PageNotFoundException
     */
    private function locate(string $uniqueId): array {
        $languageFolder = $this->folders->languageFolder();
        $result = $this->locator->locatePageAnyLanguage(
            fn(): Folder => $this->folders->intraVox(),
            $languageFolder,
            $uniqueId
        );
        if ($result === null || !isset($result['folder'], $result['file']) || !$result['file'] instanceof File) {
            throw new PageNotFoundException('Page not found: ' . $uniqueId);
        }
        $decoded = json_decode($result['file']->getContent(), true);
        if (!is_array($decoded)) {
            throw new PageNotFoundException('Page is not readable JSON: ' . $uniqueId);
        }
        return [$result['folder'], $result['file'], $decoded];
    }

    private function pathOf(Folder $folder, File $file): string {
        return $this->folders->relativePathFromRoot($folder) . '/' . $file->getName();
    }
}
