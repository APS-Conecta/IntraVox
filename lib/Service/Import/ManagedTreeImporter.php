<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service\Import;

use OCP\Files\File;
use OCP\Files\Folder;

/**
 * THE canonical managed import path (review L1-07). A directory tree on the
 * host — `home.json`, `navigation.json`, `footer.json`, `images/`, and one
 * `<page>/<page>.json` (+ `<page>/images/`) per page folder, recursively —
 * lands in a language folder of the IntraVox groupfolder. gestion's phase 41 is
 * its one caller (through `occ intravox:import`); editors never see it. The
 * editor path is ImportService (ZIP / Confluence, `_media/` convention, its own
 * overwrite flag): two audiences, two conventions, no merge by design.
 *
 * Conventions this path owns: a page folder is a folder that OWNS
 * `<folder>/<folder>.json` — anything else is neither imported nor recursed
 * into; a page's media lives in `<page>/images/`, ensured for every page the
 * path writes; a page without `uniqueId` is minted one on the way in.
 *
 * `$skipExisting` (L1-08) is the seam's safety net: an existing node is never
 * overwritten, it is reported as `Skipped (exists)`, and the walk still descends
 * into existing page folders so a re-run ADDS what is missing and never flattens
 * staff edits. Without the flag the legacy behaviour (overwrite) holds, for an
 * operator who means it.
 *
 * Extracted from ImportPagesCommand (now a thin wrapper): Symfony commands are
 * not constructible in the unit suite (tests/Unit/ConstructorArityTest.php:203);
 * this class is, so the no-overwrite contract is pinned by phpunit.
 */
final class ManagedTreeImporter {
    /** @var array{created:int,updated:int,skipped:int,errors:int} */
    private array $report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
    /** @var callable(string):void */
    private $say;
    private bool $skipExisting = false;

    /**
     * @param callable(string):void $say one progress line at a time; the command prints it
     * @return array{created:int,updated:int,skipped:int,errors:int}
     */
    public function importTree(string $sourcePath, Folder $languageFolder, bool $skipExisting, callable $say): array {
        $this->report = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $this->say = $say;
        $this->skipExisting = $skipExisting;

        foreach (['home.json', 'navigation.json', 'footer.json'] as $rootFile) {
            $path = $sourcePath . '/' . $rootFile;
            if (file_exists($path)) {
                $this->importFile($path, $languageFolder, $rootFile);
            }
        }
        $this->importImages($sourcePath . '/images', $languageFolder, 'images', true);
        $this->importPageFolders($sourcePath, $languageFolder, '');

        return $this->report;
    }

    private function importFile(string $sourcePath, Folder $targetFolder, string $filename): void {
        try {
            $content = file_get_contents($sourcePath);
            if ($content === false) {
                ($this->say)("<error>Failed to read {$sourcePath}</error>");
                $this->report['errors']++;
                return;
            }
            $this->writeFile($targetFolder, $filename, $content, $filename);
        } catch (\Exception $e) {
            ($this->say)("<error>Failed to import {$filename}: {$e->getMessage()}</error>");
            $this->report['errors']++;
        }
    }

    /**
     * The one write decision every node this path produces goes through:
     * create when absent; otherwise update — or, under skipExisting, skip.
     */
    private function writeFile(Folder $folder, string $name, string $content, string $displayPath): void {
        if (!$folder->nodeExists($name)) {
            $folder->newFile($name, $content);
            ($this->say)("<info>Created: {$displayPath}</info>");
            $this->report['created']++;
            return;
        }
        if ($this->skipExisting) {
            ($this->say)("<comment>Skipped (exists): {$displayPath}</comment>");
            $this->report['skipped']++;
            return;
        }
        $file = $folder->get($name);
        if (!$file instanceof File) {
            throw new \RuntimeException("{$displayPath} exists and is not a file");
        }
        $file->putContent($content);
        ($this->say)("<info>Updated: {$displayPath}</info>");
        $this->report['updated']++;
    }

    /**
     * `<source>/images/*` → `<parent>/images/*`. The folder is created only when
     * the source has one (the language root); page folders get theirs ensured in
     * importPageFolders(), as before. $skipReadme keeps HEAD's asymmetry: the language
     * root's images/README.md is documentation (skipped), a page's is imported as before.
     */
    private function importImages(string $imagesSourcePath, Folder $parent, string $displayPrefix, bool $skipReadme): void {
        if (!is_dir($imagesSourcePath)) {
            return;
        }
        try {
            if (!$parent->nodeExists('images')) {
                $imagesFolder = $parent->newFolder('images');
                ($this->say)("<info>Created folder: {$displayPrefix}/</info>");
            } else {
                $imagesFolder = $parent->get('images');
                if (!$imagesFolder instanceof Folder) {
                    throw new \RuntimeException("{$displayPrefix} exists and is not a folder");
                }
            }
            foreach (scandir($imagesSourcePath) ?: [] as $imageFile) {
                if ($imageFile === '.' || $imageFile === '..' || ($skipReadme && $imageFile === 'README.md')) {
                    continue;
                }
                $imageSourcePath = $imagesSourcePath . '/' . $imageFile;
                if (!is_file($imageSourcePath)) {
                    continue;
                }
                $imageContent = file_get_contents($imageSourcePath);
                if ($imageContent === false) {
                    continue;
                }
                $this->writeFile($imagesFolder, $imageFile, $imageContent, "{$displayPrefix}/{$imageFile}");
            }
        } catch (\Exception $e) {
            ($this->say)("<error>Failed to import images folder {$displayPrefix}: {$e->getMessage()}</error>");
            $this->report['errors']++;
        }
    }

    /**
     * Recursively import page folders. A directory is a page only when it owns
     * `<dir>/<dir>.json`; the recursion happens through page folders only, so a
     * section whose hub JSON is missing orphans its whole subtree (the seam's
     * staging gate exists for that).
     */
    private function importPageFolders(string $sourcePath, Folder $targetFolder, string $relativePath): void {
        foreach (scandir($sourcePath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === 'images') {
                continue;
            }
            $entryPath = $sourcePath . '/' . $entry;
            if (!is_dir($entryPath)) {
                continue;
            }
            $pageId = $entry;
            $pageJsonPath = $entryPath . '/' . $pageId . '.json';
            if (!file_exists($pageJsonPath)) {
                continue;
            }
            $displayPath = $relativePath !== '' ? $relativePath . '/' . $pageId : $pageId;

            try {
                $pageExisted = $targetFolder->nodeExists($pageId);
                if (!$pageExisted) {
                    $pageFolder = $targetFolder->newFolder($pageId);
                    ($this->say)("<info>Created folder: {$displayPath}/</info>");
                } else {
                    $pageFolder = $targetFolder->get($pageId);
                    if (!$pageFolder instanceof Folder) {
                        throw new \RuntimeException("{$displayPath} exists and is not a folder");
                    }
                }
                // Every page the path writes carries its media folder. Under skipExisting an
                // existing page is left exactly as found — no empty images/ appears beside it.
                if (!$pageFolder->nodeExists('images') && (!$pageExisted || !$this->skipExisting)) {
                    $pageFolder->newFolder('images');
                    ($this->say)("<info>Created folder: {$displayPath}/images/</info>");
                }

                $jsonFileName = $pageId . '.json';
                if ($pageFolder->nodeExists($jsonFileName) && $this->skipExisting) {
                    ($this->say)("<comment>Skipped (exists): {$displayPath}/{$jsonFileName}</comment>");
                    $this->report['skipped']++;
                } else {
                    $jsonContent = file_get_contents($pageJsonPath);
                    if ($jsonContent === false) {
                        ($this->say)("<error>Failed to read {$pageJsonPath}</error>");
                        $this->report['errors']++;
                        continue;
                    }
                    $pageData = json_decode($jsonContent, true);
                    if (!is_array($pageData)) {
                        ($this->say)("<error>Invalid JSON in {$pageJsonPath}</error>");
                        $this->report['errors']++;
                        continue;
                    }
                    if (!isset($pageData['uniqueId'])) {
                        $pageData['uniqueId'] = 'page-' . bin2hex(random_bytes(8));
                        $jsonContent = json_encode($pageData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                        ($this->say)("<comment>Added uniqueId: {$pageData['uniqueId']}</comment>");
                    }
                    $this->writeFile($pageFolder, $jsonFileName, (string)$jsonContent, "{$displayPath}/{$jsonFileName}");
                }

                $this->importImages($entryPath . '/images', $pageFolder, "{$displayPath}/images", false);
                $this->importPageFolders($entryPath, $pageFolder, $displayPath);
            } catch (\Exception $e) {
                ($this->say)("<error>Failed to import {$displayPath}: {$e->getMessage()}</error>");
                $this->report['errors']++;
            }
        }
    }
}
