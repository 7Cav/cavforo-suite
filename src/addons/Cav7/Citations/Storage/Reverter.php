<?php

namespace Cav7\Citations\Storage;

use XF\Db\AbstractAdapter;

/**
 * Puts back the source blob for each row a re-encode moved onto an encoded blob, working
 * from the re-encode log. It deletes nothing, and it touches a row only while that row
 * still points at the encoded blob its entry names.
 */
final class Reverter
{
    private const PAGE = 1000;

    /** @var array<string, array<string, mixed>> blobs already resolved this run, by source hash */
    private array $resolved = [];

    public function __construct(
        private AbstractAdapter $db,
        private DataDisk $disk,
        private BlobStore $blobs,
        private Rows $rows,
        private DamageLog $damage
    ) {
    }

    /**
     * @return array<string, string> archive key by source hash, for each source the
     *     revert needs that isn't on the box
     */
    public function plan(RevertScope $scope): array
    {
        $needed = [];
        foreach ($this->entries($scope) as $entry) {
            $hash = $entry['source_hash'];
            if (isset($needed[$hash]) || !$this->stillEncoded($entry)) {
                continue;
            }

            $blob = $this->blobs->byHash($hash);
            if ($blob === null || $this->disk->stat(BlobFile::keyOf($blob)) === null) {
                $needed[$hash] = $entry['source_archive_key'];
            }
        }

        return $needed;
    }

    /**
     * @return array{reverted: int, left: int, damage: int}
     */
    public function apply(RevertScope $scope, ?string $fetchedDir): array
    {
        $reverted = 0;
        $left = 0;

        foreach ($this->entries($scope) as $entry) {
            if (!$this->stillEncoded($entry)) {
                $left++;
                continue;
            }

            try {
                $source = $this->source($entry, $fetchedDir);
            } catch (BlobDamage $e) {
                $this->damage->report(
                    $e->getMessage(),
                    $entry['source_archive_key'],
                    "{$entry['row_kind']}:{$entry['record_id']}",
                    $entry['source_hash']
                );
                continue;
            }

            $this->revertRow($entry, (int) $source['blob_id']) ? $reverted++ : $left++;
        }

        $this->damage->finish();

        return ['reverted' => $reverted, 'left' => $left, 'damage' => $this->damage->total];
    }

    /**
     * @return \Generator<array<string, mixed>> the unreverted entries in scope
     */
    private function entries(RevertScope $scope): \Generator
    {
        [$where, $params] = $scope->condition();
        $afterId = 0;

        do {
            $page = $this->db->fetchAll(
                'SELECT * FROM ' . Reencoder::LOG_TABLE . "
                WHERE reverted_date = 0 AND log_id > ? AND $where
                ORDER BY log_id
                LIMIT " . self::PAGE,
                array_merge([$afterId], $params)
            );

            foreach ($page as $entry) {
                $afterId = (int) $entry['log_id'];
                yield $entry;
            }
        } while ($page);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function stillEncoded(array $entry, bool $lock = false): bool
    {
        $row = $this->rows->facts(RowKind::from($entry['row_kind']), (int) $entry['record_id'], $lock);

        return $row !== null
            && $row['citation_blob_id'] === (int) $entry['encoded_blob_id']
            && !$row['grant_linked'];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     * @throws BlobDamage
     */
    private function source(array $entry, ?string $fetchedDir): array
    {
        $hash = $entry['source_hash'];
        if (isset($this->resolved[$hash])) {
            return $this->resolved[$hash];
        }

        $onBox = $this->blobs->byHash($hash);
        $file = $onBox
            ? $this->disk->path(BlobFile::keyOf($onBox))
            : $this->fetchedFile($entry, $fetchedDir);

        return $this->resolved[$hash] = $this->blobs->findOrCreate($file, 'source');
    }

    /**
     * @param array<string, mixed> $entry
     * @throws BlobDamage
     */
    private function fetchedFile(array $entry, ?string $fetchedDir): string
    {
        $file = rtrim((string) $fetchedDir, '/') . '/' . $entry['source_archive_key'];
        if ($fetchedDir === null || !is_file($file)) {
            throw new BlobDamage('source not on the box or in the fetched directory');
        }
        if (!hash_equals($entry['source_hash'], hash_file('sha256', $file, true))) {
            throw new BlobDamage('fetched file hash mismatch');
        }

        return $file;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function revertRow(array $entry, int $sourceId): bool
    {
        $this->db->beginTransaction();

        $still = $this->stillEncoded($entry, true);
        if ($still) {
            $this->rows->point(RowKind::from($entry['row_kind']), (int) $entry['record_id'], $sourceId);
            $this->blobs->keep($sourceId, 'reverted');
            $this->db->update(Reencoder::LOG_TABLE, [
                'reverted_date' => time(),
                'reverted_to_blob_id' => $sourceId,
            ], 'log_id = ?', [$entry['log_id']]);
        }

        $this->db->commit();

        return $still;
    }
}
