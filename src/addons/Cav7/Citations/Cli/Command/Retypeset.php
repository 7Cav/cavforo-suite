<?php

namespace Cav7\Citations\Cli\Command;

use Cav7\Citations\Grant\RetypesetReport;
use Cav7\Citations\Grant\RetypesetSelection;
use Cav7\Citations\Service\Retypeset as RetypesetService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;

/**
 * Exits 0 clean, 1 when a grant failed, 2 when the command was refused.
 */
class Retypeset extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('cav7-citations:retypeset')
            ->setDescription('Remakes the typesetting of chosen grants with the current renderer.')
            ->addOption('grant', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A grant. Repeatable.')
            ->addOption('template-version', null, InputOption::VALUE_REQUIRED, 'Every grant pinned to this version.')
            ->addOption('addon-version', null, InputOption::VALUE_REQUIRED, 'Every grant holding a row with this addon_version_id.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would change and write nothing.')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why, for the grant log. Required unless --dry-run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $say = fn (string $line) => $output->writeln($line, OutputInterface::OUTPUT_RAW);

        try {
            $selection = RetypesetSelection::parse(
                $input->getOption('grant'),
                $input->getOption('template-version'),
                $input->getOption('addon-version'),
                (bool) $input->getOption('dry-run'),
                $input->getOption('reason'),
            );
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 2;
        }

        /** @var RetypesetService $service */
        $service = \XF::service(RetypesetService::class);
        $render = $service->render();
        $addOn = \XF::em()->find('XF:AddOn', 'Cav7/Citations');

        $say(sprintf(
            '%s. Renderer %s (%d), ImageMagick %s, FreeType %s.',
            $selection->dryRun ? 'Retypeset dry run' : 'Retypeset',
            $addOn->version_string,
            $render->addonVersionId,
            $render->libraries->imagemagick_version,
            $render->libraries->freetype_version,
        ));

        $report = new RetypesetReport();
        $failed = 0;

        foreach ($service->selected($selection) as $grantId) {
            try {
                $result = $selection->dryRun ? $service->preview($grantId) : $service->apply($grantId, $selection);
            } catch (\Exception $e) {
                $failed++;
                $report->failed();
                \XF::logException($e, false, "Retypeset of grant $grantId failed: ");
                $say("grant $grantId failed: " . $e->getMessage());
                continue;
            }

            $grant = $result['grant'];
            foreach ($report->grant($grantId, $grant->template_version_id, $grant->Members->count(), $result['changes']) as $line) {
                $say($line);
            }
        }

        $say($report->summary($selection->dryRun));
        $say($selection->dryRun ? 'Nothing written. Run without --dry-run, with --reason="...", to write.' : "$failed failed.");

        return $failed > 0 ? 1 : 0;
    }
}
