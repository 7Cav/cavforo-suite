<?php

namespace Cav7\Citations\Cli\Command;

use Cav7\Citations\Storage\Storage;
use Cav7\Citations\Storage\SweepProgress;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;

/**
 * Exits 0 clean, 1 when a brake held or damage was reported, 2 when the lock was taken.
 */
class Sweep extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('cav7-citations:sweep')
            ->setDescription('Takes legacy citation files in as citation blobs, keeps refcounts and deletes what no row needs.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what a run would do and write nothing.')
            ->addOption('lift-brake', null, InputOption::VALUE_NONE, 'Delete past the brake limit on this run.')
            ->addOption('verify-hashes', null, InputOption::VALUE_NONE, 'Also re-hash every blob file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $say = fn (string $line) => $output->writeln($line, OutputInterface::OUTPUT_RAW);

        $progress = new SweepProgress();
        $progress->dryRun = (bool) $input->getOption('dry-run');
        $progress->liftBrake = (bool) $input->getOption('lift-brake');
        $progress->verifyHashes = (bool) $input->getOption('verify-hashes');

        $storage = Storage::forApp(\XF::app());

        if (!$progress->dryRun && !$storage->lock()) {
            $output->writeln('<error>Another storage operation holds the lock.</error>');
            return 2;
        }

        try {
            $storage->sweeper($say, $progress->dryRun)->run($progress);
        } finally {
            if (!$progress->dryRun) {
                $storage->unlock();
            }
        }

        $say(sprintf(
            '%s: %d file(s) %s, %d orphan(s) found and %d deleted, %d zero-reference blob(s) found and %d deleted, %d damage.',
            $progress->dryRun ? 'Dry run' : 'Sweep',
            $progress->tookIn,
            $progress->dryRun ? 'to take in' : 'taken in',
            $progress->orphans,
            $progress->orphansDeleted,
            $progress->zeroReference,
            $progress->zeroDeleted,
            $progress->damage
        ));

        return $progress->braked || $progress->damage ? 1 : 0;
    }
}
