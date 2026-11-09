<?php

namespace Cav7\Citations\Storage;

use XF\Db\AbstractAdapter;

/**
 * The sweep. CLI and job both drive advance(), one unit of work at a time, so there is
 * one code path. Order inside a run: take-in, row pass, orphans, recount, blob pass,
 * zero-reference blobs.
 *
 * Orphans are counted and then deleted by walking the legacy tree again, judging each
 * file by its row at that moment. Nothing carries a list of files between steps. The job
 * drops the lock between them, so the delete pass stops at the count the brake judged.
 */
final class Sweeper
{
    private const ROW_PAGE = 1000;

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

    public function run(SweepProgress $p): void
    {
        while (!$p->done()) {
            $this->advance($p);
        }
    }

    public function advance(SweepProgress $p): void
    {
        $this->damage->total = $p->damage;

        match ($p->phase) {
            SweepProgress::TAKE_IN => $this->walkLegacy(
                $p,
                SweepProgress::ROWS,
                fn (RowKind $kind, int $shard, string $name, array $entry) => $this->visitForTakeIn($p, $kind, $shard, $name, $entry),
                true
            ),
            SweepProgress::ROWS => $this->rowPass($p),
            SweepProgress::ORPHAN_COUNT => $this->walkLegacy(
                $p,
                SweepProgress::ORPHAN_VERDICT,
                fn (RowKind $kind, int $shard, string $name, array $entry) => $this->visitForCount($p, $kind, $shard, $name, $entry)
            ),
            SweepProgress::ORPHAN_VERDICT => $p->enter(
                $this->mayDelete($p, 'orphans', $p->orphans) ? SweepProgress::ORPHAN_DELETE : SweepProgress::RECOUNT
            ),
            SweepProgress::ORPHAN_DELETE => $this->walkLegacy(
                $p,
                SweepProgress::RECOUNT,
                fn (RowKind $kind, int $shard, string $name, array $entry) => $this->visitForDelete($p, $kind, $shard, $name, $entry)
            ),
            SweepProgress::RECOUNT => $this->recount($p),
            SweepProgress::BLOBS => $this->blobPass($p),
            SweepProgress::ZERO_COUNT => $this->settleZeroReference($p),
        };

        $p->damage = $this->damage->total;

        if ($p->done()) {
            $this->damage->finish();
        }
    }

    /**
     * @param \Closure(RowKind, int, string, array): void $visit
     */
    private function walkLegacy(SweepProgress $p, string $next, \Closure $visit, bool $reportStrays = false): void
    {
        $kind = RowKind::cases()[$p->kindIndex] ?? null;
        if ($kind === null) {
            $p->enter($next);
            return;
        }

        $tree = $kind->legacyTree();
        $shards = $this->shards($tree, $reportStrays && $p->cursor < 0);
        $remaining = array_filter($shards, fn (int $shard) => $shard > $p->cursor);

        if (!$remaining) {
            $p->kindIndex++;
            $p->cursor = -1;
            return;
        }

        $shard = min($remaining);
        foreach ($this->disk->entries("$tree/$shard") as $name => $entry) {
            $visit($kind, $shard, (string) $name, $entry);
        }
        $p->cursor = $shard;
    }

    /**
     * @return list<int>
     */
    private function shards(string $tree, bool $reportStrays): array
    {
        $shards = [];
        foreach ($this->disk->entries($tree) as $name => $entry) {
            $name = (string) $name;
            if ($entry['dir'] && $name === (string) (int) $name) {
                $shards[] = (int) $name;
            } elseif ($reportStrays && $name !== 'index.html') {
                $this->damage->report('unexpected entry', "$tree/$name");
            }
        }

        return $shards;
    }

    /**
     * What a legacy file is right now, judged by its row at this moment, or null for a
     * file this run leaves alone.
     *
     * @return array{outcome: string, record_id: int, row: ?array}|null
     */
    private function judge(RowKind $kind, int $shard, string $name, array $entry): ?array
    {
        $recordId = SweepRules::legacyRecordId($name, $shard);
        if ($recordId === null || $entry['dir'] || !SweepRules::isSettled($entry['mtime'], time())) {
            return null;
        }

        $row = $this->rows->facts($kind, $recordId);

        return ['outcome' => SweepRules::classify($row), 'record_id' => $recordId, 'row' => $row];
    }

    private function visitForTakeIn(SweepProgress $p, RowKind $kind, int $shard, string $name, array $entry): void
    {
        $key = "{$kind->legacyTree()}/$shard/$name";

        if ($name === 'index.html') {
            return;
        }
        if ($entry['dir'] || SweepRules::legacyRecordId($name, $shard) === null) {
            $this->damage->report('unexpected entry', $key);
            return;
        }

        $file = $this->judge($kind, $shard, $name, $entry);
        if ($file === null) {
            return;
        }

        $recordId = $file['record_id'];
        if ($file['outcome'] === SweepRules::DAMAGE) {
            $this->damage->report('legacy file on a row with no citation', $key, "{$kind->value}:$recordId");
        } elseif ($file['outcome'] === SweepRules::TAKE_IN) {
            $p->dryRun
                ? $this->previewTakeIn($p, $key, $kind, $recordId)
                : $this->takeIn($p, $key, $kind, $recordId, $file['row']['citation_date']);
        }
    }

    private function previewTakeIn(SweepProgress $p, string $key, RowKind $kind, int $recordId): void
    {
        if (BlobFile::format($this->disk->leadingBytes($key)) === null) {
            $this->damage->report('no known image signature', $key, "{$kind->value}:$recordId");
            return;
        }

        $p->tookIn++;
    }

    private function takeIn(SweepProgress $p, string $key, RowKind $kind, int $recordId, int $seenDate): void
    {
        $seenFile = $this->disk->stat($key);
        if ($seenFile === null) {
            return;
        }

        $temp = tempnam(sys_get_temp_dir(), 'cav7cit');
        try {
            if (!@copy($this->disk->path($key), $temp)) {
                $this->damage->report('legacy file unreadable', $key, "{$kind->value}:$recordId");
                return;
            }

            try {
                $blob = $this->blobs->findOrCreate($temp, 'source');
            } catch (BlobDamage $e) {
                $this->damage->report($e->getMessage(), $key, "{$kind->value}:$recordId", hash_file('sha256', $temp, true));
                return;
            }
        } finally {
            @unlink($temp);
        }

        $this->db->beginTransaction();
        $row = $this->rows->facts($kind, $recordId, true);
        $unchanged = $row !== null
            && $row['citation_date'] === $seenDate
            && !$row['grant_linked']
            && $this->disk->stat($key) === $seenFile;

        if ($unchanged) {
            $this->rows->point($kind, $recordId, (int) $blob['blob_id']);
        }
        $this->db->commit();

        if ($unchanged) {
            $p->tookIn++;
            if (!$this->disk->delete($key)) {
                $this->damage->report('legacy file could not be deleted', $key, "{$kind->value}:$recordId");
            }
        }
    }

    private function rowPass(SweepProgress $p): void
    {
        $kind = RowKind::cases()[$p->kindIndex] ?? null;
        if ($kind === null) {
            $p->enter(SweepProgress::ORPHAN_COUNT);
            return;
        }

        $page = $this->rows->page($kind, $p->cursor, self::ROW_PAGE);
        if (!$page) {
            $p->kindIndex++;
            $p->cursor = -1;
            return;
        }

        foreach ($page as $row) {
            $id = $row['record_id'];
            $legacyKey = $kind->legacyKey($id);
            $what = SweepRules::rowDamage(
                $row['citation_date'],
                $row['citation_blob_id'],
                $row['grant_linked'],
                $row['blob_found'],
                $row['citation_blob_id'] !== null || $this->disk->stat($legacyKey) !== null
            );
            foreach ($what as $line) {
                $this->damage->report($line, $legacyKey, "{$kind->value}:$id");
            }
            $p->cursor = $id;
        }
    }

    private function visitForCount(SweepProgress $p, RowKind $kind, int $shard, string $name, array $entry): void
    {
        $orphan = $this->orphanReason($kind, $shard, $name, $entry);
        if ($orphan === null) {
            return;
        }

        $p->orphans++;
        if ($p->dryRun) {
            $key = "{$kind->legacyTree()}/$shard/$name";
            $this->listing($orphan, $key, $entry['size'], $this->disk->hash($key));
        }
    }

    private function visitForDelete(SweepProgress $p, RowKind $kind, int $shard, string $name, array $entry): void
    {
        if ($p->orphansDeleted >= $p->orphans) {
            return;
        }

        $orphan = $this->orphanReason($kind, $shard, $name, $entry);
        $key = "{$kind->legacyTree()}/$shard/$name";
        $hash = $orphan !== null ? $this->disk->hash($key) : null;
        if ($hash === null) {
            return;
        }

        $this->blobs->logDeletion($orphan, $key, $entry['size'], $hash, null, null, $p->liftBrake);
        if ($this->disk->delete($key)) {
            $p->orphansDeleted++;
        } else {
            $this->damage->report('legacy file could not be deleted', $key, '', $hash);
        }
    }

    private function orphanReason(RowKind $kind, int $shard, string $name, array $entry): ?string
    {
        $outcome = $this->judge($kind, $shard, $name, $entry)['outcome'] ?? null;

        return in_array($outcome, [SweepRules::ORPHAN_ROWLESS, SweepRules::ORPHAN_GRANT_LINKED], true) ? $outcome : null;
    }

    private function mayDelete(SweepProgress $p, string $what, int $count): bool
    {
        $held = SweepRules::brakeHolds($count, $p->liftBrake);
        $line = sprintf('Cav7/Citations sweep: %d %s, brake limit %d', $count, $what, SweepRules::BRAKE_LIMIT);

        if ($held) {
            $p->braked[] = $what;
            $line .= ', refused';
            $p->dryRun ? ($this->say)($line) : $this->damage->notice($line);
        } elseif ($p->dryRun) {
            ($this->say)($line . ', clear');
        }

        return !$held && !$p->dryRun;
    }

    private function recount(SweepProgress $p): void
    {
        if (!$p->dryRun) {
            $counts = $this->rows->referenceCounts();
            $stored = $this->db->fetchPairs('SELECT blob_id, ref_count FROM ' . BlobStore::TABLE);

            foreach ($stored as $blobId => $count) {
                $actual = $counts[(int) $blobId] ?? 0;
                if ($actual === (int) $count) {
                    continue;
                }

                $this->db->update(BlobStore::TABLE, ['ref_count' => $actual], 'blob_id = ?', [$blobId]);
                if ($actual === 0) {
                    $p->lastRefCounts[(int) $blobId] = (int) $count;
                }
            }
        }

        $p->enter(SweepProgress::BLOBS);
    }

    private function blobPass(SweepProgress $p): void
    {
        $shard = $p->cursor + 1;
        if ($shard > $this->lastBlobShard($p)) {
            $p->enter(SweepProgress::ZERO_COUNT);
            return;
        }

        $dir = BlobFile::TREE . "/$shard";
        $entries = $this->disk->entries($dir);
        $blobs = $this->db->fetchAll(
            'SELECT * FROM ' . BlobStore::TABLE . ' WHERE blob_id >= ? AND blob_id < ?',
            [$shard * 1000, ($shard + 1) * 1000]
        );

        foreach ($blobs as $blob) {
            $key = BlobFile::keyOf($blob);
            $name = basename($key);
            $entry = $entries[$name] ?? null;
            unset($entries[$name]);

            if ($entry === null) {
                $this->damage->report('blob file missing', $key, '', $blob['content_hash']);
            } elseif ($entry['size'] !== (int) $blob['byte_size']) {
                $this->damage->report('blob file size differs', $key, '', $blob['content_hash']);
            } elseif ($p->verifyHashes && $this->disk->hash($key) !== $blob['content_hash']) {
                $this->damage->report('blob file hash mismatch', $key, '', $blob['content_hash']);
            }
        }

        foreach (array_keys($entries) as $name) {
            if ($name !== 'index.html') {
                $this->damage->report('blob tree file with no blob row', "$dir/$name");
            }
        }

        $p->cursor = $shard;
    }

    private function lastBlobShard(SweepProgress $p): int
    {
        $onDisk = $this->shards(BlobFile::TREE, $p->cursor < 0);
        $inTable = BlobFile::shard((int) $this->db->fetchOne('SELECT MAX(blob_id) FROM ' . BlobStore::TABLE));

        return max($inTable, ...($onDisk ?: [0]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function zeroReferenceBlobs(): array
    {
        return $this->db->fetchAll(
            'SELECT b.* FROM ' . BlobStore::TABLE . ' AS b
            WHERE ' . Rows::unreferencedSql('b') . '
                AND NOT EXISTS (SELECT 1 FROM ' . Reencoder::LOG_TABLE . ' WHERE source_blob_id = b.blob_id)
            ORDER BY b.blob_id'
        );
    }

    /**
     * Counts and deletes in one step, because the job drops the lock between steps and the
     * brake has to judge the set it deletes.
     */
    private function settleZeroReference(SweepProgress $p): void
    {
        $blobs = $this->zeroReferenceBlobs();
        $p->zeroReference = count($blobs);

        if ($p->dryRun) {
            foreach ($blobs as $blob) {
                $this->listing('zero_reference', BlobFile::keyOf($blob), (int) $blob['byte_size'], $blob['content_hash']);
            }
        }

        if ($this->mayDelete($p, 'zero-reference blobs', $p->zeroReference)) {
            foreach ($blobs as $blob) {
                $blobId = (int) $blob['blob_id'];
                if ($this->rows->hasReference($blobId)) {
                    continue;
                }

                $this->blobs->delete($blob, 'zero_reference', $p->lastRefCounts[$blobId] ?? (int) $blob['ref_count'], $p->liftBrake);
                $p->zeroDeleted++;
            }
        }

        $p->enter(SweepProgress::DONE);
    }

    private function listing(string $what, string $key, int $size, ?string $hash): void
    {
        ($this->say)(sprintf('%s %s %d %s', $what, $key, $size, $hash !== null ? bin2hex($hash) : '-'));
    }
}
