<?php

namespace Cav7\Citations\Storage;

use XF\Db\AbstractAdapter;

/**
 * Citation references on the two vendor tables. Every write here is a plain update on
 * citation_blob_id, never an entity save, and none touches citation_date.
 */
final class Rows
{
    // hasGrantLink() in SQL, read wherever a row is judged from the database.
    private const GRANT_LINKED = '(citation_grant_member_id IS NOT NULL OR citation_regiment_grant_id IS NOT NULL)';

    public function __construct(private AbstractAdapter $db)
    {
    }

    /**
     * @return array{citation_date: int, citation_blob_id: ?int, grant_linked: bool}|null
     */
    public function facts(RowKind $kind, int $recordId, bool $lock = false): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT citation_date, citation_blob_id, ' . self::GRANT_LINKED . ' AS grant_linked
            FROM ' . $kind->table() . ' WHERE record_id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$recordId]
        );

        return $row ? self::typed($row) : null;
    }

    /**
     * Rows after $afterId with a citation or a reference.
     *
     * @return list<array{record_id: int, citation_date: int, citation_blob_id: ?int, grant_linked: bool, blob_found: bool}>
     */
    public function page(RowKind $kind, int $afterId, int $limit): array
    {
        $rows = $this->db->fetchAll(
            'SELECT r.record_id, r.citation_date, r.citation_blob_id, ' . self::GRANT_LINKED . ' AS grant_linked,
                b.blob_id AS found_blob_id
            FROM ' . $kind->table() . ' AS r
            LEFT JOIN ' . BlobStore::TABLE . ' AS b ON b.blob_id = r.citation_blob_id
            WHERE r.record_id > ? AND (r.citation_date <> 0 OR r.citation_blob_id IS NOT NULL)
            ORDER BY r.record_id
            LIMIT ' . $limit,
            [$afterId]
        );

        return array_map(
            fn (array $row) => self::typed($row) + [
                'record_id' => (int) $row['record_id'],
                'blob_found' => $row['found_blob_id'] !== null,
            ],
            $rows
        );
    }

    /**
     * @return list<array{kind: RowKind, record_id: int}>
     */
    public function on(int $blobId, bool $lock = false): array
    {
        $found = [];
        foreach (RowKind::cases() as $kind) {
            $ids = $this->db->fetchAllColumn(
                'SELECT record_id FROM ' . $kind->table() . ' WHERE citation_blob_id = ?' . ($lock ? ' FOR UPDATE' : ''),
                [$blobId]
            );
            foreach ($ids as $id) {
                $found[] = ['kind' => $kind, 'record_id' => (int) $id];
            }
        }

        return $found;
    }

    public function hasReference(int $blobId): bool
    {
        foreach (RowKind::cases() as $kind) {
            $found = $this->db->fetchOne(
                'SELECT 1 FROM ' . $kind->table() . ' WHERE citation_blob_id = ? LIMIT 1',
                [$blobId]
            );
            if ($found) {
                return true;
            }
        }

        return false;
    }

    public function point(RowKind $kind, int $recordId, int $blobId): void
    {
        $this->db->update($kind->table(), ['citation_blob_id' => $blobId], 'record_id = ?', [$recordId]);
    }

    /**
     * Rows move by what they point at now, so one that left in the meantime stays put.
     */
    public function move(int $fromBlobId, int $toBlobId): int
    {
        $moved = 0;
        foreach (RowKind::cases() as $kind) {
            $moved += $this->db->update(
                $kind->table(),
                ['citation_blob_id' => $toBlobId],
                'citation_blob_id = ?',
                [$fromBlobId]
            );
        }

        return $moved;
    }

    /**
     * @return array<int, int> reference count by blob id, blobs with none left out
     */
    public function referenceCounts(): array
    {
        $counts = [];
        foreach (RowKind::cases() as $kind) {
            $pairs = $this->db->fetchPairs(
                'SELECT citation_blob_id, COUNT(*) FROM ' . $kind->table() . '
                WHERE citation_blob_id IS NOT NULL
                GROUP BY citation_blob_id'
            );
            foreach ($pairs as $blobId => $count) {
                $counts[(int) $blobId] = ($counts[(int) $blobId] ?? 0) + (int) $count;
            }
        }

        return $counts;
    }

    /**
     * SQL matching a blob aliased $alias that no row points at, read from the reference
     * columns rather than ref_count, which lags until the next recount.
     */
    public static function unreferencedSql(string $alias): string
    {
        $clauses = array_map(
            fn (RowKind $kind) => "NOT EXISTS (SELECT 1 FROM {$kind->table()} WHERE citation_blob_id = $alias.blob_id)",
            RowKind::cases()
        );

        return implode(' AND ', $clauses);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{citation_date: int, citation_blob_id: ?int, grant_linked: bool}
     */
    private static function typed(array $row): array
    {
        return [
            'citation_date' => (int) $row['citation_date'],
            'citation_blob_id' => $row['citation_blob_id'] !== null ? (int) $row['citation_blob_id'] : null,
            'grant_linked' => (bool) $row['grant_linked'],
        ];
    }
}
