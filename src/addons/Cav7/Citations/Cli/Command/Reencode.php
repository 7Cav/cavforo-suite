<?php

namespace Cav7\Citations\Cli\Command;

use Cav7\Citations\Render\LibraryVersions;
use Cav7\Citations\Storage\Reencoder;
use Cav7\Citations\Storage\ReencodeRules;
use Cav7\Citations\Storage\Storage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;

/**
 * Exits 0 clean, 1 when damage was reported, 2 when the run was refused.
 */
class Reencode extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('cav7-citations:reencode')
            ->setDescription('Replaces eligible source blobs with encoded blobs, after an archive sync.')
            ->addOption('synced-at', null, InputOption::VALUE_REQUIRED, 'When the archive sync began, in ISO 8601.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $say = fn (string $line) => $output->writeln($line, OutputInterface::OUTPUT_RAW);

        $imagick = extension_loaded('imagick');
        $syncedAt = $input->getOption('synced-at');
        $refusal = ReencodeRules::refusal(
            (bool) \XF::options()->cav7CitationReencodeEnabled,
            $imagick,
            $imagick ? \Imagick::queryFormats('JPEG') : [],
            $syncedAt,
            time()
        );
        if ($refusal !== null) {
            $output->writeln("<error>Refused: $refusal.</error>");
            return 2;
        }

        $storage = Storage::forApp(\XF::app());
        if (!$storage->lock()) {
            $output->writeln('<error>Refused: another storage operation holds the lock.</error>');
            return 2;
        }

        $libjpeg = ReencodeRules::libjpegVersionIn((string) @shell_exec('magick -list format 2>/dev/null'));
        if ($libjpeg === '') {
            $output->writeln('<comment>The magick binary gave no libjpeg version, so none is recorded.</comment>');
        }

        try {
            $result = (new Reencoder(
                $storage->db,
                $storage->disk,
                $storage->blobs,
                $storage->rows,
                $storage->damageLog($say),
                $say
            ))->run(ReencodeRules::parseSyncedAt($syncedAt), LibraryVersions::read()->imagemagick_version, $libjpeg);
        } finally {
            $storage->unlock();
        }

        $say(sprintf(
            'Run %d: %d encoded, %d kept, %d damage, %d row(s) moved.',
            $result['run_id'],
            $result['encoded'],
            $result['kept'],
            $result['damage'],
            $result['rows_moved']
        ));

        return $result['damage'] ? 1 : 0;
    }
}
