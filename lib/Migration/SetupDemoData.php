<?php
declare(strict_types=1);

namespace OCA\IntraVox\Migration;

use OCA\IntraVox\Service\DemoDataService;
use OCA\IntraVox\Service\SetupService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that runs on app install/update — converges the IntraVox
 * groupfolder, groups and grants ONLY (bare, L1-03). Content is never the
 * upgrade path's business on a managed install (fork doctrine): it is
 * seeded by gestion's provisioning (phase 41).
 */
class SetupDemoData implements IRepairStep {
    private SetupService $setupService;
    // Kept deliberately (fork doctrine: the on-demand demo path stays
    // reachable — bundled only, `occ intravox:setup --force-demo`); it was already write-only
    // from 3.1.2, when the demo import moved out of this step, and dropping
    // it now would be a ctor change with no behavioral gain.
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
        return 'Setup IntraVox groupfolder (bare)';
    }

    public function run(IOutput $output): void {
        $output->info('Setting up IntraVox...');

        // Setup groupfolder structure — BARE (L1-03): occ upgrade converges
        // the groupfolder, groups and grants and creates NO content. Full
        // mode used to run createDefaultContent() here, so a fresh managed
        // install got a boilerplate language tree BEFORE the seed ran — the
        // same class as the demo re-injection 3.1.2 removed below. The
        // admin API setup endpoint keeps full mode; upgrades never create
        // content.
        try {
            $result = $this->setupService->setupSharedFolder(true);
            // The array return itself used to be negated here — a non-empty
            // array is always truthy, so this warning could never fire.
            if (!$result['success']) {
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
        // imported bundled demo data for the detected default language whenever the marker
        // was unset, which meant every `occ upgrade` re-injected demo after any cleanup, and
        // every FRESH managed install got demo 'en' content BEFORE the seed ran — content
        // that outranked the seeded es welcome for default-language users (hasRealContent
        // counts the demo home as real; verified live 2026-09-26). Demo stays reachable on
        // demand — bundled only, review L1-12: Admin Settings → IntraVox → Install, or
        // `occ intravox:setup --force-demo` (honors --skip-demo/--force-demo).
        // The template install that used to sit here is gone for the same reason: it copied
        // template folders into every existing enabled language folder on every occ upgrade —
        // content the developer never chose. Templates become declaration material (the
        // welcome-structure phase) if the seeded pages want them.
        $output->info('Demo data import skipped (managed install — content is provisioned)');
        $output->info('Template install skipped (managed install — content is provisioned)');
    }
}
