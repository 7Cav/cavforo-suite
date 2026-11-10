<?php

namespace Cav7\EnlistmentDefaults\Cli\Command;

use Cav7\EnlistmentDefaults\PucSync;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;
use XF\Repository\Option as OptionRepository;

/**
 * The PUC sync's first run, over every milpac whether the sync is on or not, then turns it on.
 * Exits 0 clean, 1 when a write failed, 2 when refused.
 */
class PucStrip extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('cav7-enlistment-defaults:puc-strip')
            ->setDescription('Replaces every PUC row with one linked row per live regiment grant, then turns the PUC sync on.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what a run would do and write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $say = fn (string $line) => $output->writeln($line, OutputInterface::OUTPUT_RAW);
        $dryRun = (bool) $input->getOption('dry-run');

        $sync = PucSync::forApp(\XF::app());
        if (!$sync->hasLiveGrants()) {
            $output->writeln('<error>No live regiment grant. Issue them first, or this would delete every PUC row and add none.</error>');
            return 2;
        }

        $after = 0;
        while (($relationIds = $sync->nextMilpacs($after)) !== []) {
            $sync->batch($relationIds, $dryRun);
            $after = end($relationIds);
        }

        foreach ($sync->counts->grants as $grantId => $counts) {
            $say(sprintf('Regiment grant %d: %d added, %d reset, %d removed.', $grantId, $counts['added'], $counts['reset'], $counts['removed']));
        }
        $say(sprintf('%d unlinked PUC row(s) removed.', $sync->counts->unlinked));

        if ($dryRun) {
            $say('Dry run: nothing written.');
            return 0;
        }

        $sync->logOnGrants();
        \XF::repository(OptionRepository::class)->updateOption('cav7EnlistDefPucSync', true);
        if ($sync->failures > 0) {
            $say("{$sync->failures} write(s) failed; see the error log. The daily sync retries them.");
        }
        $say('The PUC sync is on.');

        return $sync->failures > 0 ? 1 : 0;
    }
}
