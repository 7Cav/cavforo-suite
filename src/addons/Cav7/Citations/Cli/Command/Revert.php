<?php

namespace Cav7\Citations\Cli\Command;

use Cav7\Citations\Storage\Reverter;
use Cav7\Citations\Storage\RevertScope;
use Cav7\Citations\Storage\Storage;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;
use XF\Repository\OptionRepository;

/**
 * Exits 0 clean, 1 when damage was reported, 2 when the command was refused.
 */
class Revert extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('cav7-citations:revert')
            ->setDescription('Puts back the source blob for each row a re-encode moved onto an encoded blob.')
            ->addArgument('action', InputArgument::REQUIRED, 'plan, or apply')
            ->addOption('run', null, InputOption::VALUE_REQUIRED, 'One re-encode run.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Every run. Apply also switches the re-encode off.')
            ->addOption('blob', null, InputOption::VALUE_REQUIRED, 'One encoded blob.')
            ->addOption('row', null, InputOption::VALUE_REQUIRED, 'The encoded blob a row points at, as award:ID or record:ID.')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Directory of fetched sources, keyed by archive key.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $say = fn (string $line) => $output->writeln($line, OutputInterface::OUTPUT_RAW);
        $action = $input->getArgument('action');
        if ($action !== 'plan' && $action !== 'apply') {
            $output->writeln('<error>The action is plan or apply.</error>');
            return 2;
        }

        $storage = Storage::forApp(\XF::app());

        try {
            $scope = RevertScope::parse(
                $input->getOption('run'),
                (bool) $input->getOption('all'),
                $input->getOption('blob'),
                $input->getOption('row'),
                $storage->rows
            );
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 2;
        }

        $reverter = new Reverter(
            $storage->db,
            $storage->disk,
            $storage->blobs,
            $storage->rows,
            $storage->damageLog($say)
        );

        if ($action === 'plan') {
            $needed = $reverter->plan($scope);
            foreach ($needed as $hash => $key) {
                $say(bin2hex($hash) . '  ' . $key);
            }
            $say(count($needed) . ' source(s) to fetch from the archive.');
            return 0;
        }

        if (!$storage->lock()) {
            $output->writeln('<error>Refused: another storage operation holds the lock.</error>');
            return 2;
        }

        try {
            if ($scope->all) {
                \XF::repository(OptionRepository::class)->updateOption('cav7CitationReencodeEnabled', false);
                $say('The re-encode is now switched off.');
            }
            $result = $reverter->apply($scope, $input->getOption('from'));
        } finally {
            $storage->unlock();
        }

        $say(sprintf(
            '%d row(s) reverted, %d left alone, %d damage.',
            $result['reverted'],
            $result['left'],
            $result['damage']
        ));

        return $result['damage'] ? 1 : 0;
    }
}
