<?php

declare(strict_types=1);

namespace OCA\IntraVox\Service;

use OCA\IntraVox\Service\Folder\FolderContext;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IUserSession;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Per-language homepage pointer (issue: configurable homepage).
 *
 * Stores which page is the homepage in a small per-language config file
 * `{lang}/homepage.json` = { "homepageUniqueId": "page-…" }. This lives next to
 * navigation.json / footer.json and is skipped in page enumeration.
 *
 * The pointer is OPTIONAL: when absent (every pre-existing install), the app
 * falls back to the legacy loose `home.json`-in-root behaviour, so nothing
 * changes until an admin explicitly picks a different homepage. This service
 * only reads/writes the pointer; PageService owns the resolution + fallback.
 */
class HomepageService {
    public const CONFIG_FILE = 'homepage.json';

    private IUserSession $userSession;
    private LanguageService $languageService;
    private SystemFileService $systemFileService;
    private FolderContext $folders;

    private ?ICache $pagesCache = null;
    private ?ICache $permissionsCache = null;

    public function __construct(
        IUserSession $userSession,
        LanguageService $languageService,
        SystemFileService $systemFileService,
        ICacheFactory $cacheFactory,
        FolderContext $folders
    ) {
        $this->userSession = $userSession;
        $this->languageService = $languageService;
        $this->systemFileService = $systemFileService;
        $this->folders = $folders;

        if ($cacheFactory->isAvailable()) {
            $this->pagesCache = $cacheFactory->createDistributed('intravox-pages');
            $this->permissionsCache = $cacheFactory->createDistributed('intravox-permissions');
        }
    }

    /**
     * The configured homepage uniqueId for a language, or null when unset
     * (→ caller falls back to the legacy loose home.json).
     *
     * Read via the user's folder view first (respects ACL); falls back to the
     * system context so department-only users still resolve the homepage.
     */
    public function getHomepageUniqueId(?string $language = null): ?string {
        $lang = $language ?? $this->getCurrentLanguage();

        // Try the user's own view first (ACL-respecting).
        try {
            $folder = $this->readLanguageFolder($lang);
            if ($folder->nodeExists(self::CONFIG_FILE)) {
                $file = $folder->get(self::CONFIG_FILE);
                $data = $file instanceof File ? json_decode($file->getContent(), true) : null;
                $uid = $data['homepageUniqueId'] ?? null;
                if (is_string($uid) && $uid !== '') {
                    return $uid;
                }
            }
        } catch (\Exception $e) {
            // No access to the language root — fall through to system context.
        }

        // Fallback: system context (for limited-access users).
        try {
            $content = $this->systemFileService->getSharedResource($lang, self::CONFIG_FILE);
            if ($content !== null) {
                $data = json_decode($content, true);
                $uid = $data['homepageUniqueId'] ?? null;
                if (is_string($uid) && $uid !== '') {
                    return $uid;
                }
            }
        } catch (\Exception $e) {
            // System read failed too — treat as unset.
        }

        return null;
    }

    /**
     * Persist the homepage pointer for a language and flush the page/permission
     * caches (mirrors NavigationService::saveNavigation).
     */
    public function setHomepageUniqueId(string $uniqueId, ?string $language = null): void {
        // By code: HomepageResolverService passes the target language (review L3-04).
        $lang = $language ?? $this->getCurrentLanguage();
        $folder = $this->folders->writeLanguageFolder($lang);
        $content = json_encode(['homepageUniqueId' => $uniqueId], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($folder->nodeExists(self::CONFIG_FILE)) {
            $file = $folder->get(self::CONFIG_FILE);
            if (!$file instanceof File) {
                throw new NotFoundException(self::CONFIG_FILE . ' is not a file');
            }
            $file->putContent($content);
        } else {
            $folder->newFile(self::CONFIG_FILE, $content);
        }

        $this->pagesCache?->clear();
        $this->permissionsCache?->clear();
    }

    /**
     * Remove the pointer (revert to legacy home.json fallback).
     */
    public function clearHomepagePointer(?string $language = null): void {
        $lang = $language ?? $this->getCurrentLanguage();
        try {
            $folder = $this->readLanguageFolder($lang);
            if ($folder->nodeExists(self::CONFIG_FILE)) {
                $folder->get(self::CONFIG_FILE)->delete();
            }
        } catch (\Exception $e) {
            // Nothing to clear.
        }
        $this->pagesCache?->clear();
        $this->permissionsCache?->clear();
    }

    // ---- helpers (mirror NavigationService, review L3-04: one folder walk, one language source) ----

    private function readLanguageFolder(string $language): Folder {
        if (!$this->languageService->isLanguageEnabled($language)) {
            $language = $this->languageService->getDefaultLanguage();
        }
        $folder = $this->folders->languageFolderByCode($language);
        if ($folder === null) {
            throw new NotFoundException('Language folder does not exist: ' . $language);
        }
        return $folder;
    }

    public function getCurrentLanguage(): string {
        $baseLang = $this->folders->userLanguage();
        return $this->languageService->isLanguageEnabled($baseLang)
            ? $baseLang
            : $this->languageService->getDefaultLanguage();
    }
}
