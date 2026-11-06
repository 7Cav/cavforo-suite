<?php

namespace Cav7\Citations\Storage;

use XF\Db\AbstractAdapter;

/**
 * The only way a replaced source leaves the box. An operator takes the candidates, checks
 * them against the archive, and hands the same list back, so what was checked is what
 * goes. Lines read like sha256sum's, hash then two spaces then archive key.
 */
final class SourcePurger
{
    public function __construct(private AbstractAdapter $db, private BlobStore $blobs, private Rows $rows)
    {
    }

    public function candidateList(): string
    {
        $lines = array_map(
            fn (array $blob) => bin2hex($blob['content_hash']) . '  ' . BlobFile::keyOf($blob) . "\n",
            $this->candidates()
        );

        return implode('', $lines);
    }

    /**
     * @return array{purged: int, skipped: int}
     * @throws \InvalidArgumentException on a malformed list, before anything is deleted
     */
    public function purge(string $list): array
    {
        $entries = [];
        foreach (preg_split('/\R/', trim($list), -1, PREG_SPLIT_NO_EMPTY) as $line) {
            if (!preg_match('/^([0-9a-f]{64})  (\S+)$/', $line, $match)) {
                throw new \InvalidArgumentException("Not a purge list line: $line");
            }
            $entries[] = ['hash' => hex2bin($match[1]), 'key' => $match[2]];
        }

        $purged = 0;
        $skipped = 0;
        foreach ($entries as $entry) {
            $blob = $this->blobs->byHash($entry['hash']);
            if ($blob === null || BlobFile::keyOf($blob) !== $entry['key'] || !$this->isReplacedSource($blob)) {
                $skipped++;
                continue;
            }

            $this->blobs->delete($blob, 'replaced_source', (int) $blob['ref_count'], false);
            $purged++;
        }

        return ['purged' => $purged, 'skipped' => $skipped];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidates(): array
    {
        return $this->db->fetchAll(
            'SELECT b.* FROM ' . BlobStore::TABLE . ' AS b
            WHERE ' . Rows::unreferencedSql('b') . '
                AND EXISTS (SELECT 1 FROM ' . Reencoder::LOG_TABLE . ' WHERE source_blob_id = b.blob_id)
            ORDER BY b.blob_id'
        );
    }

    /**
     * @param array<string, mixed> $blob
     */
    private function isReplacedSource(array $blob): bool
    {
        $blobId = (int) $blob['blob_id'];

        return !$this->rows->hasReference($blobId)
            && $this->db->fetchOne(
                'SELECT 1 FROM ' . Reencoder::LOG_TABLE . ' WHERE source_blob_id = ? LIMIT 1',
                [$blobId]
            );
    }
}
