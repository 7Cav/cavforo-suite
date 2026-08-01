<?php

namespace Cav7\Core\Cli\Command;

use Cav7\Core\TemplateModification\BoardFacts;
use Cav7\Core\TemplateModification\DiscoveryFailed;
use Cav7\Core\TemplateModification\Reconciliation;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;

/**
 * Issue #171 — asks a live board whether this suite's template modifications
 * are actually in force, and names the ones that are not.
 *
 * What the command answers and how to read its exits: the addon's README. What
 * "in force" means and what each shape tells the reader: the suite's
 * CONTEXT.md. The check itself is `TemplateModification\BoardFacts`
 * (what the board says) and `TemplateModification\Reconciliation` (which of
 * that is a failure). This is one of two thin callers, the other being
 * `Cron\CheckTemplateModifications`.
 *
 * Run:
 *   php cmd.php cav7-core:check-template-modifications
 */
class CheckTemplateModifications extends AbstractCommand
{
    protected function configure()
    {
        $this
            ->setName('cav7-core:check-template-modifications')
            ->setDescription(
                'Checks that every template modification this suite ships is in force on this board, and names the ones that are not.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $facts = BoardFacts::gather(\XF::app());
        } catch (DiscoveryFailed $e) {
            // Separated from a red run on purpose. "Nothing is wrong" and "this
            // could not work out what to look at" are opposite answers, and a
            // wrapper that only tests for zero reads the second as the first.
            $output->writeln('<error>The check could not be performed: ' . $e->getMessage() . '</error>');
            return 2;
        }

        $failures = Reconciliation::failures($facts->modifications);

        $checked = sprintf(
            '%d modification(s) shipped by this suite, across %d template copy(ies)',
            $facts->modificationCount(),
            $facts->copyCount()
        );

        if (!$failures) {
            $output->writeln("<info>All in force: $checked.</info>");
            return 0;
        }

        $output->writeln("<error>" . count($failures) . " not in force, of $checked:</error>");
        $output->writeln('');
        foreach ($failures as $failure) {
            $output->writeln('  ' . $failure->line());
        }

        return 1;
    }
}
