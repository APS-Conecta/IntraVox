<?php
declare(strict_types=1);

namespace OCA\IntraVox\Command;

use OCA\IntraVox\Service\Import\ManagedTreeImporter;
use OCA\IntraVox\Service\SetupService;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ImportPagesCommand extends Command {
    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private SetupService $setupService;
    private IUserManager $userManager;
    private ManagedTreeImporter $importer;

    public function __construct(
        IRootFolder $rootFolder,
        IUserSession $userSession,
        SetupService $setupService,
        IUserManager $userManager,
        ManagedTreeImporter $importer
    ) {
        parent::__construct();
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->setupService = $setupService;
        $this->userManager = $userManager;
        $this->importer = $importer;
    }

    protected function configure(): void {
        $this->setName('intravox:import')
            ->setDescription('Import IntraVox pages from a directory tree — the managed content path (gestion phase 41)')
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Source directory containing pages to import (e.g., /tmp/intravox-welcome-es)'
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED,
                'Target language code (defaults to es — the deployment writes in Spanish)',
                'es'
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'User ID to use for file operations',
                'admin'
            )
            ->addOption(
                'skip-existing',
                null,
                InputOption::VALUE_NONE,
                'Never overwrite an existing page, file or image — report it as skipped. The seam always passes this (per-section convergence): a re-run adds what is missing and touches nothing else.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $sourcePath = $input->getArgument('source');
        $language = $input->getOption('language');
        $userId = $input->getOption('user');
        $skipExisting = (bool)$input->getOption('skip-existing');

        // Validate source directory
        if (!is_dir($sourcePath)) {
            $output->writeln("<error>Source directory not found: {$sourcePath}</error>");
            return 1;
        }

        $output->writeln("<info>Importing IntraVox pages...</info>");
        $output->writeln("<info>Source: {$sourcePath}</info>");
        $output->writeln("<info>Language: {$language}</info>");
        $output->writeln($skipExisting ? "<info>Mode: skip existing (never overwrite)</info>" : "<info>Mode: overwrite</info>");
        $output->writeln("");

        // Set user context
        $user = $this->userManager->get($userId);
        if (!$user) {
            $output->writeln("<error>User not found: {$userId}</error>");
            return 1;
        }
        $this->userSession->setUser($user);

        // Get language folder
        try {
            $sharedFolder = $this->setupService->getSharedFolder();

            // Get or create language folder
            if (!$sharedFolder->nodeExists($language)) {
                $languageFolder = $sharedFolder->newFolder($language);
                $output->writeln("<info>Created language folder: {$language}</info>");
            } else {
                $languageFolder = $sharedFolder->get($language);
            }
        } catch (\Exception $e) {
            $output->writeln("<error>Failed to get language folder: {$e->getMessage()}</error>");
            return 1;
        }
        if (!$languageFolder instanceof \OCP\Files\Folder) {
            $output->writeln("<error>{$language} exists and is not a folder</error>");
            return 1;
        }

        $report = $this->importer->importTree(
            $sourcePath,
            $languageFolder,
            $skipExisting,
            static function (string $line) use ($output): void { $output->writeln($line); }
        );

        $output->writeln("");
        $output->writeln("<info>Import complete!</info>");
        $output->writeln(sprintf(
            "<info>Written: %d files (created %d, updated %d, skipped %d)</info>",
            $report['created'] + $report['updated'], $report['created'], $report['updated'], $report['skipped']
        ));

        if ($report['errors'] > 0) {
            $output->writeln("<error>Errors: {$report['errors']}</error>");
            return 1;
        }

        $output->writeln("");
        $output->writeln("<comment>Pages are now registered in Nextcloud's file cache and ready to use in IntraVox!</comment>");

        return 0;
    }
}
