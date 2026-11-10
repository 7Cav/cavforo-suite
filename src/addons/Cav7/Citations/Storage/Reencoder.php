<?php

namespace Cav7\Citations\Storage;

use Cav7\Citations\Render\JpegEncoder;
use XF\Db\AbstractAdapter;

/**
 * One re-encode run: each eligible source blob, in blob_id order, is encoded once and
 * its rows moved onto the encoded blob, with a log entry per row for the revert.
 *
 * ref_count lags until the next sweep, so a source whose rows have all moved is passed
 * over by asking the rows, which keeps a rerun from doing the work twice.
 */
final class Reencoder
{
    public const RUN_TABLE = 'xf_cav7_citation_reencode_run';
    public const LOG_TABLE = 'xf_cav7_citation_reencode_log';

    private const PAGE = 200;

    private int $encoded = 0;
    private int $kept = 0;
    private int $moved = 0;

    /**
     * @param \Closure(string): void $say
     */
    public function __construct(
        private AbstractAdapter $db,
        private DataDisk $disk,
        private BlobStore $blobs,
        private Rows $rows,
        private DamageLog $damage,
        private \Closure $say
    ) {
    }

    /**
     * @return array{run_id: int, encoded: int, kept: int, damage: int, rows_moved: int}
     */
    public function run(int $syncedAt, string $imagemagickVersion, string $libjpegVersion): array
    {
        $this->db->insert(self::RUN_TABLE, [
            'started_date' => time(),
            'archive_sync_date' => $syncedAt,
            'encoder_label' => JpegEncoder::ENCODER_LABEL,
        ]);
        $runId = (int) $this->db->lastInsertId();

        $encoderFields = [
            'encoder_label' => JpegEncoder::ENCODER_LABEL,
            'imagemagick_version' => $imagemagickVersion,
            'libjpeg_version' => $libjpegVersion,
        ];

        $afterId = 0;
        while ($sources = $this->eligible($syncedAt, $afterId)) {
            foreach ($sources as $source) {
                $afterId = (int) $source['blob_id'];
                $this->process($runId, $source, $encoderFields);
            }
        }

        $this->damage->finish();
        $this->db->update(self::RUN_TABLE, [
            'finished_date' => time(),
            'encoded_count' => $this->encoded,
            'kept_count' => $this->kept,
            'damage_count' => $this->damage->total,
            'rows_moved' => $this->moved,
        ], 'run_id = ?', [$runId]);

        return [
            'run_id' => $runId,
            'encoded' => $this->encoded,
            'kept' => $this->kept,
            'damage' => $this->damage->total,
            'rows_moved' => $this->moved,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eligible(int $syncedAt, int $afterId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM " . BlobStore::TABLE . "
            WHERE origin = 'source' AND keep_reason IS NULL AND ref_count > 0
                AND created_date < ? AND blob_id > ?
            ORDER BY blob_id
            LIMIT " . self::PAGE,
            [$syncedAt, $afterId]
        );
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, string> $encoderFields
     */
    private function process(int $runId, array $source, array $encoderFields): void
    {
        $blobId = (int) $source['blob_id'];
        if (!$this->rows->hasReference($blobId)) {
            return;
        }

        $key = BlobFile::keyOf($source);
        $onDisk = $this->disk->hash($key);
        if ($onDisk === null || !hash_equals($source['content_hash'], $onDisk)) {
            $what = $onDisk === null ? 'blob file missing' : 'blob file hash mismatch';
            $this->damage->report($what, $key, '', $source['content_hash']);
            return;
        }

        if ($source['format'] !== 'png') {
            $this->keep($blobId, $source['format'] === 'gif' ? 'gif' : 'already_jpeg');
            return;
        }

        $jpeg = $this->encode($key, $source);
        if ($jpeg === null) {
            return;
        }

        if (ReencodeRules::keepsSource(strlen($jpeg), (int) $source['byte_size'])) {
            $this->keep($blobId, 'grew');
            return;
        }

        $temp = tempnam(sys_get_temp_dir(), 'cav7cit');
        try {
            if (file_put_contents($temp, $jpeg) !== strlen($jpeg)) {
                throw new BlobDamage('encoded output could not be written');
            }
            $encoded = $this->blobs->findOrCreate($temp, 'encoded', $encoderFields);
        } catch (BlobDamage $e) {
            $this->damage->report($e->getMessage(), $key, '', $source['content_hash']);
            return;
        } finally {
            @unlink($temp);
        }

        $this->repoint($runId, $source, $key, (int) $encoded['blob_id']);
        $this->encoded++;
    }

    /**
     * @param array<string, mixed> $source
     * @return string|null the output, or null when the source was kept as decode_failed
     */
    private function encode(string $key, array $source): ?string
    {
        try {
            $image = self::decode((string) file_get_contents($this->disk->path($key)), 'png');
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $jpeg = JpegEncoder::encode($image);
            $image->clear();

            $check = self::decode($jpeg, 'jpeg');
            $sameSize = $check->getImageWidth() === $width && $check->getImageHeight() === $height;
            $check->clear();
        } catch (\ImagickException $e) {
            $this->decodeFailed($key, $source);
            return null;
        }

        if (!$sameSize) {
            $this->decodeFailed($key, $source);
            return null;
        }

        return $jpeg;
    }

    /**
     * @throws \ImagickException when the bytes don't decode
     */
    private static function decode(string $bytes, string $format): \Imagick
    {
        $image = new \Imagick();
        $image->setFormat($format);
        $image->readImageBlob($bytes);

        return $image;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function decodeFailed(string $key, array $source): void
    {
        $this->damage->report('decode failed', $key, '', $source['content_hash']);
        $this->keep((int) $source['blob_id'], 'decode_failed');
    }

    private function keep(int $blobId, string $reason): void
    {
        $this->blobs->keep($blobId, $reason);
        $this->kept++;
        ($this->say)("kept $blobId: $reason");
    }

    /**
     * @param array<string, mixed> $source
     */
    private function repoint(int $runId, array $source, string $key, int $encodedId): void
    {
        $sourceId = (int) $source['blob_id'];

        $this->db->beginTransaction();
        foreach ($this->rows->on($sourceId, true) as $row) {
            $this->db->insert(self::LOG_TABLE, [
                'run_id' => $runId,
                'row_kind' => $row['kind']->value,
                'record_id' => $row['record_id'],
                'source_blob_id' => $sourceId,
                'source_hash' => $source['content_hash'],
                'source_archive_key' => $key,
                'encoded_blob_id' => $encodedId,
            ]);
        }
        $this->moved += $this->rows->move($sourceId, $encodedId);
        $this->db->commit();
    }
}
