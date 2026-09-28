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
}
