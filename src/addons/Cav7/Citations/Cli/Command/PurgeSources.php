<?php

namespace Cav7\Citations\Cli\Command;

use Cav7\Citations\Storage\SourcePurger;
use Cav7\Citations\Storage\Storage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;

/**
 * Exits 0 clean, 2 when the command was refused or couldn't write its list.
 */
class PurgeSources extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('cav7-citations:purge-sources')
            ->setDescription('Deletes replaced sources, from a list an operator has checked against the archive.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Write the candidate list to --out and delete nothing.')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Where the dry run writes the list.')
            ->addOption('list', null, InputOption::VALUE_REQUIRED, 'The checked list to delete from.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storage = Storage::forApp(\XF::app());
        $purger = new SourcePurger($storage->db, $storage->blobs, $storage->rows);

        $dryRun = (bool) $input->getOption('dry-run');
        $out = $input->getOption('out');
        $list = $input->getOption('list');

        if ($dryRun && $out !== null && $list === null) {
            $candidates = $purger->candidateList();
            if (file_put_contents($out, $candidates) !== strlen($candidates)) {
                $output->writeln("<error>Couldn't write the whole list to $out.</error>");
                return 2;
            }
            $output->writeln(sprintf('%d replaced source(s) listed in %s.', substr_count($candidates, "\n"), $out));
            return 0;
        }

        if ($dryRun || $out !== null || $list === null || !is_readable($list)) {
            $output->writeln('<error>Use --dry-run --out=<file>, or --list=<readable file>.</error>');
            return 2;
        }

        if (!$storage->lock()) {
            $output->writeln('<error>Refused: another storage operation holds the lock.</error>');
            return 2;
        }

        try {
            $result = $purger->purge((string) file_get_contents($list));
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 2;
        } finally {
            $storage->unlock();
        }

        $output->writeln(sprintf('%d purged, %d skipped.', $result['purged'], $result['skipped']));

        return 0;
    }
}
