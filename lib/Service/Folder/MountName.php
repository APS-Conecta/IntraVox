<?php
declare(strict_types=1);

namespace OCA\IntraVox\Service\Folder;

use OCP\IConfig;

/**
 * The name of the group folder IntraVox stores its content in — the ONE place
 * it is known (review L1-01). Every resolver walks `userFolder->get(name)`,
 * every path stripper looks for the `<name>/` segment, the seam creates and
 * looks the mount up by it, and the sidebar prints it.
 *
 * Configurable so a managed install can call the folder what its staff will
 * recognize («Intranet», ADR-0020) while every existing install keeps the
 * default and does not move. Set BEFORE `occ intravox:setup` on a new install;
 * on an existing one it is the first of the three runbook steps (config →
 * `groupfolders:rename` → `intravox:reindex`, docs/WELCOME-SCREEN.md).
 */
final class MountName {
    public const APP_ID = 'intravox';
    public const CONFIG_KEY = 'groupfolder_name';
    public const DEFAULT = 'IntraVox';

    public function __construct(private string $name) {
        if (trim($name) === '' || str_contains($name, '/')) {
            throw new \InvalidArgumentException('MOUNT_NAME_INVALID: the group-folder name must be a single non-empty path segment, got "' . $name . '"');
        }
    }

    /** Empty or unset config = the default; whitespace is not a name. */
    public static function fromConfig(IConfig $config): self {
        $raw = trim($config->getAppValue(self::APP_ID, self::CONFIG_KEY, ''));
        return new self($raw === '' ? self::DEFAULT : $raw);
    }

    public function get(): string {
        return $this->name;
    }

    /**
     * Strip the per-user / legacy prefix from a stored page path (review L2-03).
     *
     * The index is shared by every user but a Nextcloud path is per-user
     * (`/admin/files/<name>/en/about`, `/Rik/files/<name>/en/about`), and rows
     * written before 2.0 still carry that form. Everything up to and including
     * the LAST `<name>/` segment goes; what remains resolves against the caller's
     * own mount. A path with no such segment is returned untouched: since 2.0 the
     * index stores app-root-relative paths (`en/about`), which is exactly that
     * shape. Leading/trailing slashes are dropped either way ('' for the root).
     *
     * One implementation, fed by the configured name — after a rename
     * (ADR-0020) the segment is the NEW name; legacy rows under the old one are
     * retired by `occ intravox:reindex`, the runbook's last step.
     */
    public function stripPrefix(string $storedPath): string {
        $path = trim($storedPath, '/');
        if ($path === '') {
            return '';
        }
        $segments = explode('/', $path);
        $rootIndex = null;
        foreach ($segments as $i => $segment) {
            if ($segment === $this->name) {
                $rootIndex = $i;
            }
        }
        if ($rootIndex === null) {
            return $path;
        }
        return implode('/', array_slice($segments, $rootIndex + 1));
    }
}
