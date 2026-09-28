<?php
declare(strict_types=1);

namespace OCA\IntraVox\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Seed `intravox.enabled_languages` for upgrades from <1.6.0.
 *
 * Pre-1.6.0 the supported-languages list was a hardcoded `['nl','en','de','fr']`
 * constant duplicated across 12 services. From 1.6.0 the set lives in
 * `oc_appconfig.intravox.enabled_languages` and is admin-controlled.
 *
 * Phase 2 (L2-01) supersedes the 1.6.0 upgrade contract: the seed is the
 * deployment's es+en reality — the same set gestion's seam converged
 * before the engine took ownership. Only key-unset (fresh) installs are
 * affected; any install that already has a value (the lab box's
 * ["es","en"], or any admin choice) keeps it via the skip arm. The app
 * shipped 2026-09-25, so no production install ever carried the old
 * four-language seed; the vendor/upstream surface is deferred (L1-15 class).
 *
 * Pure config-init: no folder creation, no folder deletion, no destructive
 * side-effects. Idempotent.
 */
class Version001600Date20260609000000 extends SimpleMigrationStep {

    private const APP_ID = 'intravox';
    private const CONFIG_KEY = 'enabled_languages';
    private const DEPLOYMENT_DEFAULT = ['es', 'en'];

    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $existing = $this->config->getAppValue(self::APP_ID, self::CONFIG_KEY, '');
        if ($existing !== '') {
            $output->info('[IntraVox] enabled_languages already set, skipping seed');
            return;
        }

        $this->config->setAppValue(self::APP_ID, self::CONFIG_KEY, json_encode(self::DEPLOYMENT_DEFAULT));
        $output->info('[IntraVox] Seeded enabled_languages with the deployment default ["es","en"]');
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        // No schema changes for 1.6.0.
        return null;
    }
}
