<?php
declare(strict_types=1);

namespace OCA\IntraVox\Command;

use OCA\IntraVox\Exception\PageNotFoundException;
use OCA\IntraVox\Service\Write\PageProtectionService;
use OCP\IUserManager;
use OCP\IUserSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The break-glass for the welcome tree's walls (review L4-01): a protected page
 * refuses delete and move until an admin runs this with --off. Two steps by
 * construction — no UI toggle, no API — so a wall never falls by accident.
 */
class ProtectPageCommand extends Command {
    public function __construct(
        private IUserSession $userSession,
        private IUserManager $userManager,
        private PageProtectionService $protection,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('intravox:protect')
            ->setDescription('Show, raise (--on) or remove (--off) structural protection on a page — the two-step before deleting or moving a wall')
            ->addArgument('uniqueId', InputArgument::REQUIRED, 'The page uniqueId (page-…)')
            ->addOption('on', null, InputOption::VALUE_NONE, 'Protect the page: it refuses delete and move')
            ->addOption('off', null, InputOption::VALUE_NONE, 'Remove the protection (the deliberate step before deleting or moving a wall)')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Only report whether the page is protected (the default when neither --on nor --off is given)')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'User ID to use for file operations', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $uniqueId = (string)$input->getArgument('uniqueId');
        $on = (bool)$input->getOption('on');
        $off = (bool)$input->getOption('off');
        if ((int)$on + (int)$off + (int)(bool)$input->getOption('status') > 1) {
            $output->writeln('<error>--on, --off and --status are mutually exclusive</error>');
            return 1;
        }

        $user = $this->userManager->get((string)$input->getOption('user'));
        if (!$user) {
            $output->writeln('<error>User not found: ' . $input->getOption('user') . '</error>');
            return 1;
        }
        $this->userSession->setUser($user);

        try {
            if (!$on && !$off) {
                $s = $this->protection->status($uniqueId);
                $output->writeln(sprintf('%s (%s): protected: %s', $s['path'], $uniqueId, $s['protected'] ? 'yes' : 'no'));
                return 0;
            }
            $r = $this->protection->setProtected($uniqueId, $on);
            if (!$r['changed']) {
                $output->writeln(sprintf('<comment>%s (%s) already %s</comment>', $r['path'], $uniqueId, $on ? 'protected' : 'unprotected'));
                return 0;
            }
            $output->writeln(sprintf('<info>%s: %s (%s)</info>', $on ? 'Protected' : 'Protection removed', $r['path'], $uniqueId));
            if ($off) {
                $output->writeln('<comment>The page can now be deleted or moved. Run again with --on to restore the wall.</comment>');
            }
            return 0;
        } catch (PageNotFoundException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }
    }
}
