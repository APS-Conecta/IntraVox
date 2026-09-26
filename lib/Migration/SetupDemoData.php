<?php
declare(strict_types=1);

namespace OCA\IntraVox\Migration;

use OCA\IntraVox\Service\DemoDataService;
use OCA\IntraVox\Service\SetupService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that runs on app install/update to setup demo data
 */
class SetupDemoData implements IRepairStep {
    private SetupService $setupService;
    private DemoDataService $demoDataService;
    private LoggerInterface $logger;

    public function __construct(
        SetupService $setupService,
        DemoDataService $demoDataService,
        LoggerInterface $logger
    ) {
        $this->setupService = $setupService;
        $this->demoDataService = $demoDataService;
        $this->logger = $logger;
    }

    public function getName(): string {
        return 'Setup IntraVox demo data';
    }

    public function run(IOutput $output): void {
        $output->info('Setting up IntraVox...');

        // Setup groupfolder structure
        try {
            if (!$this->setupService->setupSharedFolder()) {
                $output->warning('Could not setup IntraVox groupfolder - groupfolders app may not be installed');
                return;
            }
            $output->info('IntraVox groupfolder ready');
        } catch (\Exception $e) {
            $this->logger->error('[IntraVox] Setup failed: ' . $e->getMessage());
            $output->warning('Setup failed: ' . $e->getMessage());
            return;
        }

        // NO demo import on install/update (fork doctrine: content is seeded by gestion's
        // provisioning — phase 41 — never by the app's own upgrade path). The upstream step
        // imported bundled demo data for the detected default language whenever the marker was
        // unset, which meant every `occ upgrade` re-injected demo after any cleanup, and every
        // FRESH managed install got demo 'en' content BEFORE the seed ran — content that
        // outranked the seeded es welcome for default-language users (hasRealContent counts
        // the demo home as real; verified live 2026-09-26). Demo stays reachable on demand:
        // `occ intravox:import-demo` (SetupCommand honors --skip-demo/--force-demo).
        $output->info('Demo data import skipped (managed install — content is provisioned)');

        // Install default templates (idempotent - skips existing templates)
        $output->info('Installing default templates...');
        try {
            $result = $this->setupService->installDefaultTemplates();
            if ($result['success']) {
                if ($result['installed'] > 0) {
                    $output->info("Default templates installed: {$result['installed']} new");
                }
                if ($result['skipped'] > 0) {
                    $output->info("Default templates skipped: {$result['skipped']} already exist");
                }
            } else {
                $output->warning('Default templates installation had issues: ' . ($result['error'] ?? 'unknown error'));
            }
        } catch (\Exception $e) {
            $this->logger->warning('[IntraVox] Template installation failed: ' . $e->getMessage());
            $output->warning('Template installation failed: ' . $e->getMessage());
        }
    }
}
