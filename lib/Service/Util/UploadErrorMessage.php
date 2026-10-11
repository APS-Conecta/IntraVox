<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service\Util;

use OCP\IL10N;

/**
 * The user-facing sentence for a PHP upload error code (UPLOAD_ERR_*).
 *
 * The ZIP import and the media upload both show this text as-is, so it lives
 * in one place: two copies of the same seven sentences drift apart, and each
 * copy is a second set of msgids for translators.
 */
final class UploadErrorMessage {
    public static function describe(IL10N $l10n, int $code): string {
        return match ($code) {
            // TRANSLATORS: %s is the PHP setting name upload_max_filesize
            UPLOAD_ERR_INI_SIZE => $l10n->t('The file is larger than the server allows (%s).', ['upload_max_filesize']),
            UPLOAD_ERR_FORM_SIZE => $l10n->t('The file is larger than the upload form allows.'),
            UPLOAD_ERR_PARTIAL => $l10n->t('The file was only partially uploaded.'),
            UPLOAD_ERR_NO_FILE => $l10n->t('No file was uploaded.'),
            UPLOAD_ERR_NO_TMP_DIR => $l10n->t('The server has no temporary folder for uploads.'),
            UPLOAD_ERR_CANT_WRITE => $l10n->t('The server could not write the file to disk.'),
            UPLOAD_ERR_EXTENSION => $l10n->t('A PHP extension stopped the upload.'),
            default => $l10n->t('The upload failed for an unknown reason.'),
        };
    }
}
