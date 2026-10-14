<?php
declare(strict_types=1);

namespace OCA\IntraVox\Controller;

use OCA\IntraVox\Service\Import\ConfluenceHtmlImportOrchestrator;
use OCA\IntraVox\Service\Import\ZipUploadValidator;
use OCA\IntraVox\Service\ImportService;
use OCA\IntraVox\Service\PermissionService;
use OCA\IntraVox\Service\Read\PageReadService;
use OCA\IntraVox\Service\Util\UploadErrorMessage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Importing content into IntraVox: an IntraVox export ZIP, or a Confluence HTML
 * export.
 *
 * Split out of ApiController (PR-A). Both endpoints require administering the
 * IntraVox team folder, checked in the body rather than by attribute (see
 * PermissionService::canImport()), and both accept an uploaded file — which is
 * why validateParentPageId() travels with them: it is the IDOR guard on the
 * parent page an import is grafted onto, and importConfluenceHtml is its only
 * caller.
 *
 * The actual work lives in the service layer (PR-B): ZipUploadValidator decides
 * whether the upload is a ZIP, ConfluenceHtmlImportOrchestrator does the
 * conversion. What is left here is HTTP.
 */
class ImportApiController extends Controller {
    use ApiErrorTrait;

    public function __construct(
        string $appName,
        IRequest $request,
        private PageReadService $pageRead,
        private ImportService $importService,
        private ZipUploadValidator $zipUploads,
        private ConfluenceHtmlImportOrchestrator $confluenceImport,
        private PermissionService $permissionService,
        private LoggerInterface $logger,
        private IL10N $l10n,
    ) {
        parent::__construct($appName, $request);
    }

    protected function getLogger(): LoggerInterface {
        return $this->logger;
    }
    /**
     * Validate parentPageId parameter for import operations
     *
     * Security: Prevents IDOR attacks by validating:
     * 1. Parent page exists
     * 2. User has write permission on parent
     * 3. Parent is in the same language (groupfolder)
     *
     * @param string $parentPageId The parent page unique ID
     * @param string $targetLanguage The target language for import
     * @return array{valid: bool, error?: string, page?: array} Validation result
     */
    private function validateParentPageId(string $parentPageId, string $targetLanguage): array {
        try {
            $parentPage = $this->pageRead->getPage($parentPageId);
        } catch (\Exception $e) {
            $this->logger->warning('[ApiController] Parent page validation failed: page not found', [
                'parentPageId' => $parentPageId,
                'error' => $e->getMessage()
            ]);
            return [
                'valid' => false,
                'error' => $this->l10n->t('Parent page not found')
            ];
        }

        // Check write permission
        if (!($parentPage['permissions']['canWrite'] ?? false)) {
            $this->logger->warning('[ApiController] Parent page validation failed: no write permission', [
                'parentPageId' => $parentPageId,
                'targetLanguage' => $targetLanguage
            ]);
            return [
                'valid' => false,
                'error' => $this->l10n->t('You do not have permission to add pages under this parent page')
            ];
        }

        // Check same language (prevents cross-groupfolder imports)
        $parentLanguage = $parentPage['language'] ?? null;
        if ($parentLanguage !== $targetLanguage) {
            $this->logger->warning('[ApiController] Parent page validation failed: language mismatch', [
                'parentPageId' => $parentPageId,
                'parentLanguage' => $parentLanguage,
                'targetLanguage' => $targetLanguage
            ]);
            return [
                'valid' => false,
                'error' => $this->l10n->t('The parent page must be in the language you import into')
            ];
        }

        return [
            'valid' => true,
            'page' => $parentPage
        ];
    }
    /**
     * Import from uploaded ZIP file.
     *
     * #[NoAdminRequired] lifts the framework's Nextcloud-admin requirement so
     * that canImport() below becomes the actual gate: administering the
     * IntraVox team folder, which a delegated manager can do without being a
     * Nextcloud admin. Without the attribute the framework rejects that caller
     * first and the check never runs.
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function importZip(): JSONResponse {
        // Security: only an administrator of the IntraVox team folder may import.
        if (!$this->permissionService->canImport()) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Permission denied')],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            $file = $this->request->getUploadedFile('file');

            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                // Shown to the admin as-is (AdminSettings importErrorMessageFor).
                $errorMessage = UploadErrorMessage::describe($this->l10n, (int)($file['error'] ?? UPLOAD_ERR_NO_FILE));
                return new JSONResponse(['error' => $errorMessage], Http::STATUS_BAD_REQUEST);
            }

            $this->zipUploads->assertIsZip($file);

            $importComments = $this->request->getParam('importComments', '1') === '1';
            $overwrite = $this->request->getParam('overwrite', '0') === '1';
            $autoCreateMetaVoxFields = $this->request->getParam('autoCreateMetaVoxFields', '0') === '1';

            $zipContent = file_get_contents($file['tmp_name']);
            $stats = $this->importService->importFromZip($zipContent, $importComments, $overwrite, null, $autoCreateMetaVoxFields);

            return new JSONResponse([
                'success' => true,
                'stats' => $stats,
            ]);
        } catch (\OCA\IntraVox\Exception\InvalidImportException $e) {
            // Validation errors are safe to forward to the client verbatim —
            // they describe the upload, never internal paths or IDs. See #52.
            // The frontend maps `errorCode` to a translated string; `error` is
            // an English fallback for non-UI consumers (curl, scripts).
            $this->logger->info('IntraVox Import: ZIP rejected during validation', [
                'errorCode' => $e->getErrorCode(),
                'message' => $e->getMessage(),
            ]);
            return new JSONResponse(
                [
                    'error' => $e->getMessage(),
                    'errorCode' => $e->getErrorCode(),
                    'params' => $e->getParams(),
                ],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            $errorId = uniqid('err_');
            $this->logger->error('IntraVox Import: ZIP import failed', [
                'errorId' => $errorId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Import failed. Please check the ZIP file format and try again.'), 'errorId' => $errorId],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
    /**
     * Import from Confluence HTML export ZIP file
     * Admin only
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function importConfluenceHtml(): JSONResponse {
        // Security: only an administrator of the IntraVox team folder may import.
        if (!$this->permissionService->canImport()) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Permission denied')],
                Http::STATUS_FORBIDDEN
            );
        }

        $this->logger->info('Confluence HTML import endpoint called');

        try {
            $file = $this->request->getUploadedFile('file');
            $language = $this->request->getParam('language', 'nl');
            $parentPageId = $this->request->getParam('parentPageId', null);

            $this->logger->info('Import request received', [
                'file' => $file ? $file['name'] : 'no file',
                'language' => $language,
                'parentPageId' => $parentPageId,
            ]);

            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                $this->logger->warning('File upload error', ['error' => $file['error'] ?? 'no file']);
                return new JSONResponse(
                    ['error' => $this->l10n->t('No file was uploaded, or the upload failed.')],
                    Http::STATUS_BAD_REQUEST
                );
            }

            $this->zipUploads->assertIsZip($file);

            // Security: Validate parentPageId before import (IDOR prevention)
            if ($parentPageId) {
                $validation = $this->validateParentPageId($parentPageId, $language);
                if (!$validation['valid']) {
                    return new JSONResponse(
                        ['error' => $validation['error']],
                        Http::STATUS_BAD_REQUEST
                    );
                }
            }

            $this->logger->info('Starting Confluence HTML import', [
                'filename' => $file['name'],
                'size' => $file['size'],
                'language' => $language,
            ]);

            $result = $this->confluenceImport->importFromUploadedZip(
                $file['tmp_name'],
                $language,
                $parentPageId
            );

            return new JSONResponse([
                'success' => true,
                'stats' => $result['stats'],
                'pages' => $result['pages'],
                'message' => $this->l10n->t('Confluence HTML export imported'),
            ]);
        } catch (\OCA\IntraVox\Exception\InvalidImportException $e) {
            $this->logger->info('Confluence HTML import: validation failed', [
                'errorCode' => $e->getErrorCode(),
                'message' => $e->getMessage(),
            ]);
            return new JSONResponse(
                [
                    'error' => $e->getMessage(),
                    'errorCode' => $e->getErrorCode(),
                    'params' => $e->getParams(),
                ],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Exception $e) {
            $errorId = uniqid('err_');
            $this->logger->error('Confluence HTML import failed', [
                'errorId' => $errorId,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return new JSONResponse(
                ['error' => $this->l10n->t('Confluence import failed. Please check the export format and try again.'), 'errorId' => $errorId],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }
}
