<?php

/**
 * The whole sweep, driven through Sweeper against real files and real SQL.
 *
 * Row locks and GET_LOCK can't run here. They are checked on a dev stack with a lock held
 * from a second connection, along with xf_error_log's forced write. Re-check both after an
 * XF upgrade.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/SweeperTest.php
 */

namespace Cav7\Citations\Tests;

require __DIR__ . '/support/Harness.php';

use Cav7\Citations\Storage\BlobFile;
use Cav7\Citations\Storage\BlobStore;
use Cav7\Citations\Storage\Reencoder;
use Cav7\Citations\Storage\RowKind;
use Cav7\Citations\Storage\SweepProgress;

$award = RowKind::Award;
$record = RowKind::Record;

function blobCount(Stage $stage): int
{
    return (int) $stage->db->fetchOne('SELECT COUNT(*) FROM ' . BlobStore::TABLE);
}

function damageMentions(Stage $stage, string $needle): bool
{
    return (bool) array_filter($stage->errorLog, fn (string $line) => str_contains($line, $needle));
}

// A shared image across both tables.
$stage = new Stage();
$shared = png();
$stage->row($award, 1001, 500);
$stage->row($award, 1002, 501);
$stage->row($record, 7, 502);
$files = [
    $stage->legacyFile($award, 1001, $shared),
    $stage->legacyFile($award, 1002, $shared),
    $stage->legacyFile($record, 7, $shared),
];
$stage->legacyFile($award, 1003, png(30, 30));
$stage->row($award, 1003, 503);

$run = $stage->sweep();
check('every settled file with a citation is taken in', $run->tookIn === 4);
check('identical bytes across both tables make one blob', blobCount($stage) === 2);
check('both tables point at it', $stage->refOf($award, 1001) === $stage->refOf($record, 7) && $stage->refOf($award, 1001) !== null);
check('each legacy file is gone', !array_filter($files, fn (string $key) => $stage->exists($key)));
$blob = $stage->blobs->byId($stage->refOf($award, 1001));
check('the blob holds the legacy bytes unchanged', file_get_contents($stage->disk->path(BlobFile::keyOf($blob))) === $shared);
check('the recount sees all three rows', (int) $blob['ref_count'] === 3);
check('citation_date is untouched', $stage->rows->facts($award, 1002)['citation_date'] === 501);
check('a clean run reports no damage', $run->damage === 0 && !$stage->errorLog);

$before = $stage->fingerprint();
$stage->sweep();
check('a second run changes nothing', $stage->fingerprint() === $before);

// A re-upload: the legacy file wins over the reference the row holds.
$stage = new Stage();
$old = $stage->blob(png(11, 11));
$stage->row($award, 1, 500, (int) $old['blob_id']);
$stage->legacyFile($award, 1, png(12, 12));
$run = $stage->sweep();
check('a legacy file beats an existing reference', $stage->refOf($award, 1) !== (int) $old['blob_id']);
check('the old blob goes in the same run', $stage->blobs->byId((int) $old['blob_id']) === null && $run->zeroDeleted === 1);
$log = $stage->deletions('zero_reference');
check('its deletion is logged with its hash', count($log) === 1 && $log[0]['content_hash'] === $old['content_hash']);

// The age guard, for files with a row and without.
$stage = new Stage();
$stage->row($award, 1, 500);
$fresh = $stage->legacyFile($award, 1, png(), 3000);
$freshOrphan = $stage->legacyFile($award, 2, png(9, 9), 3000);
$run = $stage->sweep();
check('a file under an hour old is left, row or no row', $stage->exists($fresh) && $stage->exists($freshOrphan));
check('and a young file behind a row is not damage', $run->damage === 0);

// An upload landing around the row lock. findOrCreate's own transaction comes first, so
// the race is staged on the second.
$race = function (\Closure $upload, string $when = 'beforeBegin') use ($award): array {
    $stage = new Stage();
    $stage->row($award, 1, 500);
    $key = $stage->legacyFile($award, 1, png());
    $stage->db->$when = [null, fn () => $upload($stage, $key)];
    $stage->sweep();

    return [$stage, $key];
};

[$stage, $key] = $race(function (Stage $stage, string $key) use ($award): void {
    $stage->db->update($award->table(), ['citation_date' => 900], 'record_id = 1');
});
check('a changed citation_date leaves the file for the next run', $stage->exists($key) && $stage->refOf($award, 1) === null);

[$stage, $key] = $race(function (Stage $stage, string $key): void {
    file_put_contents($stage->disk->path($key), png(70, 70));
});
check('a changed file is left for the next run', $stage->exists($key) && file_get_contents($stage->disk->path($key)) === png(70, 70));
check('and the row isn\'t pointed at the old bytes', $stage->refOf($award, 1) === null);

// The vendor writes the file before it stamps citation_date, whose save clears the reference.
[$stage, $key] = $race(function (Stage $stage, string $key): void {
    file_put_contents($stage->disk->path($key), png(70, 70));
}, 'afterCommit');
check('a re-upload after the commit is not deleted', $stage->exists($key) && file_get_contents($stage->disk->path($key)) === png(70, 70));

// A failure between the move aside and the commit.
$stage = new Stage();
$stage->row($award, 1, 500);
$key = $stage->legacyFile($award, 1, png());
$stage->db->query("CREATE TRIGGER refuse BEFORE UPDATE OF citation_blob_id ON {$award->table()} BEGIN SELECT RAISE(ABORT, 'refused'); END");
try {
    $stage->sweep();
    $threw = false;
} catch (\RuntimeException) {
    $threw = true;
}
check('a failure before the commit puts the legacy file back and rethrows', $threw && $stage->exists($key) && $stage->refOf($award, 1) === null);
$stage->db->query('DROP TRIGGER refuse');
$stage->sweep();
check('and the next run takes it in', $stage->refOf($award, 1) !== null && !$stage->exists($key));

// A process killed between the move aside and the commit, or just after the commit.
$aside = fn (string $key) => "$key.taken-0badf00d";
$stage = new Stage();
$stage->row($award, 1, 500);
$killedBefore = $stage->legacyFile($award, 1, png());
$made = $stage->blob(png());
rename($stage->disk->path($killedBefore), $stage->disk->path($aside($killedBefore)));
$stage->row($award, 2, 500);
$killedAfter = $stage->legacyFile($award, 2, png(17, 17));
$stage->db->update($award->table(), ['citation_blob_id' => $stage->blob(png(17, 17))['blob_id']], 'record_id = 2');
rename($stage->disk->path($killedAfter), $stage->disk->path($aside($killedAfter)));
$stage->row($award, 3, 500, (int) $stage->blob(png(19, 19))['blob_id']);
$stale = $stage->legacyFile($award, 3, png(18, 18));
rename($stage->disk->path($stale), $stage->disk->path($aside($stale)));
$run = $stage->sweep();
check(
    'a file left aside before the commit is taken in onto the blob made for it',
    $stage->refOf($award, 1) === (int) $made['blob_id'] && !$stage->exists($aside($killedBefore)) && !$stage->exists($killedBefore)
);
check('one left after the commit is deleted', !$stage->exists($aside($killedAfter)) && $stage->refOf($award, 2) !== null);
check(
    'one older than the blob its row points at is kept and reported',
    $stage->exists($aside($stale)) && !$stage->exists($stale) && damageMentions($stage, 'legacy file kept aside') && $run->damage === 1
);

// Damage: each stays where it is.
$stage = new Stage();
$stage->row($award, 1, 0);
$noCitation = $stage->legacyFile($award, 1, png());
$stage->row($award, 2, 500);
$badBytes = $stage->legacyFile($award, 2, 'this is not an image');
$stage->row($award, 3, 500);
$zeroBytes = $stage->legacyFile($award, 3, '');
$stray = $stage->legacyFile($award, 4, png());
rename($stage->disk->path($stray), $stage->disk->path('roster_award_citations/0/stray.png'));
$stage->legacyFile($record, 1, png(5, 5));
$stage->db->insert($record->table(), ['record_id' => 1, 'citation_date' => 0, 'citation_blob_id' => null]);
touch($stage->disk->path('roster_award_citations/0/stray.png'), time() - 7200);
file_put_contents($stage->disk->path('roster_award_citations/0/index.html'), ' ');
$run = $stage->sweep();
check('a file on a row with no citation is left', $stage->exists($noCitation));
check('an unknown signature is left in place', $stage->exists($badBytes));
check('so is a zero-byte file', $stage->exists($zeroBytes));
check('a stray name is left', $stage->exists('roster_award_citations/0/stray.png'));
check('index.html is neither deleted nor reported', $stage->exists('roster_award_citations/0/index.html') && !damageMentions($stage, 'index.html'));
check('no blob came of any of them', blobCount($stage) === 0 && $stage->refOf($award, 2) === null);
check('damage names the path and the row', damageMentions($stage, $noCitation) && damageMentions($stage, 'award:1'));
check('the run reports it', $run->damage >= 4);

// Row pass.
$stage = new Stage();
$live = $stage->blob(png());
$stage->row($award, 1, 500);
$stage->row($award, 2, 500, 9999);
$stage->row($award, 3, 0, (int) $live['blob_id']);
$stage->row($award, 4, 0);
$run = $stage->sweep();
check('a citation with nothing behind it is damage', damageMentions($stage, 'award:1'));
check('a reference to a missing blob is damage', damageMentions($stage, 'award:2'));
check('a reference with no citation is damage', damageMentions($stage, 'award:3'));
check('a row with no citation and no reference is fine', !damageMentions($stage, 'award:4'));
check('a referenced blob is not zero-reference even on a row with no citation', $stage->blobs->byId((int) $live['blob_id']) !== null);

// Blob pass.
$stage = new Stage();
$good = $stage->blob(png());
$short = $stage->blob(png(13, 13));
$gone = $stage->blob(png(14, 14));
$changed = $stage->blob(png(15, 15));
foreach ([$good, $short, $gone, $changed] as $i => $blob) {
    $stage->row($award, $i + 1, 500, (int) $blob['blob_id']);
}
file_put_contents($stage->disk->path(BlobFile::keyOf($short)), 'x');
unlink($stage->disk->path(BlobFile::keyOf($gone)));
$sameSize = file_get_contents($stage->disk->path(BlobFile::keyOf($changed)));
$sameSize[strlen($sameSize) - 1] = chr(ord($sameSize[strlen($sameSize) - 1]) ^ 1);
file_put_contents($stage->disk->path(BlobFile::keyOf($changed)), $sameSize);
$rowless = 'roster_citation_blobs/0/9999-feedface.png';
file_put_contents($stage->disk->path($rowless), 'a blob file with no row');

$stage->sweep();
check('a size that differs is damage', damageMentions($stage, BlobFile::keyOf($short)));
check('a missing blob file is damage', damageMentions($stage, BlobFile::keyOf($gone)));
check('a file with no blob row is damage', damageMentions($stage, $rowless));
check('and is never deleted', $stage->exists($rowless));
check('a same-size change goes unseen without --verify-hashes', !damageMentions($stage, BlobFile::keyOf($changed)));
$stage->sweep(false, false, true);
check('--verify-hashes finds it', damageMentions($stage, BlobFile::keyOf($changed)));

// Orphans.
$stage = new Stage();
$orphan = $stage->legacyFile($award, 5, png());
$hash = hash('sha256', png(), true);
$run = $stage->sweep();
check('a rowless file is deleted', !$stage->exists($orphan) && $run->orphansDeleted === 1);
$log = $stage->deletions('orphan_rowless');
check(
    'and logged with path, size and hash',
    count($log) === 1 && $log[0]['path'] === $orphan && $log[0]['content_hash'] === $hash
        && (int) $log[0]['byte_size'] === strlen(png()) && $log[0]['blob_id'] === null
);

foreach (['citation_grant_member_id', 'citation_regiment_grant_id'] as $link) {
    $stage = new Stage();
    $stage->row($award, 5, 500);
    $stage->db->update($award->table(), [$link => 9], 'record_id = ?', [5]);
    $file = $stage->legacyFile($award, 5, png());
    $run = $stage->sweep();
    check("a legacy file on a row with a $link is an orphan", !$stage->exists($file) && count($stage->deletions('orphan_grant_linked')) === 1);
    check('and the row takes no reference and reports no damage', $stage->refOf($award, 5) === null && $run->damage === 0);
}

// Brake on orphans: refuses that kind and carries on with everything else.
$stage = new Stage();
for ($id = 1; $id <= 51; $id++) {
    $orphans[$id] = $stage->legacyFile($award, $id, png(1, 1, sprintf('#%06x', $id)));
}
$stage->row($award, 900, 500);
$wanted = $stage->legacyFile($award, 900, png(40, 40));
$lost = $stage->blob(png(41, 41));
$run = $stage->sweep();
check('51 orphans trips the brake and none go', $run->orphansDeleted === 0 && $stage->exists($orphans[1]) && $stage->exists($orphans[51]));
check('the rest of the run still happens', !$stage->exists($wanted) && $stage->refOf($award, 900) !== null);
check('zero-reference blobs have their own brake', $stage->blobs->byId((int) $lost['blob_id']) === null);
check('one refusal line reaches the log', count(array_filter($stage->errorLog, fn (string $l) => str_contains($l, '51 orphans'))) === 1);
check('the run names what was refused', $run->braked === ['orphans']);

$run = $stage->sweep(true);
check('--lift-brake deletes them', $run->orphansDeleted === 51 && !$stage->exists($orphans[1]));
check('and says so in the log', count($stage->deletions('orphan_rowless')) === 51 && (int) $stage->deletions('orphan_rowless')[0]['brake_lifted'] === 1);

$stage = new Stage();
for ($id = 1; $id <= 50; $id++) {
    $stage->legacyFile($award, $id, png(1, 1, sprintf('#%06x', $id)));
}
check('exactly 50 is under the brake', $stage->sweep()->orphansDeleted === 50);

// Brake on zero-reference blobs.
$stage = new Stage();
for ($i = 1; $i <= 51; $i++) {
    $blob = $stage->blob(png(1, 1, sprintf('#%06x', $i)));
    $stage->row($award, $i, 500, (int) $blob['blob_id']);
}
$stage->sweep();
$stage->db->query("UPDATE {$award->table()} SET citation_blob_id = NULL, citation_date = 0");
$run = $stage->sweep();
check('51 zero-reference blobs trip their brake', $run->zeroDeleted === 0 && blobCount($stage) === 51 && $run->braked === ['zero-reference blobs']);
$run = $stage->sweep(true);
check('the flag lifts that one too', $run->zeroDeleted === 51 && blobCount($stage) === 0);

// last_ref_count is what the blob held before the recount.
$stage = new Stage();
$blob = $stage->blob(png());
$stage->row($award, 1, 500, (int) $blob['blob_id']);
$stage->row($award, 2, 500, (int) $blob['blob_id']);
$stage->sweep();
$stage->db->query("UPDATE {$award->table()} SET citation_blob_id = NULL, citation_date = 0");
$stage->sweep();
check('last_ref_count holds the count before the recount', (int) $stage->deletions('zero_reference')[0]['last_ref_count'] === 2);

// A replaced source stays, and doesn't feed the brake.
$stage = new Stage();
$source = $stage->blob(png());
$stage->db->insert(Reencoder::LOG_TABLE, [
    'run_id' => 1, 'row_kind' => 'award', 'record_id' => 1, 'source_blob_id' => $source['blob_id'],
    'source_hash' => $source['content_hash'], 'source_archive_key' => BlobFile::keyOf($source), 'encoded_blob_id' => 2,
]);
$run = $stage->sweep();
check('the sweep never deletes a replaced source', $stage->blobs->byId((int) $source['blob_id']) !== null);
check('and doesn\'t count it', $run->zeroReference === 0);

// A dry run writes nothing, however much there is to do.
$stage = new Stage();
$stage->row($award, 1, 500);
$stage->legacyFile($award, 1, png());
$stage->row($award, 2, 0);
$stage->legacyFile($award, 2, png(6, 6));
$stage->row($award, 3, 500);
$stage->legacyFile($award, 3, 'not an image');
$orphan = $stage->legacyFile($award, 4, png(7, 7));
$zero = $stage->blob(png(8, 8));
for ($id = 100; $id < 160; $id++) {
    $stage->legacyFile($record, $id, png(2, 2, sprintf('#%06x', $id)));
}
$before = $stage->fingerprint();
$run = $stage->sweep(false, true);
check('a dry run changes no byte and no row', $stage->fingerprint() === $before);
check('it writes nothing to the error log', !$stage->errorLog);
check('it counts files to take in', $run->tookIn === 1);
check('it prints every orphan with size and hash', (bool) array_filter(
    $stage->said,
    fn (string $line) => str_contains($line, $orphan) && str_contains($line, (string) strlen(png(7, 7)))
        && str_contains($line, hash('sha256', png(7, 7)))
));
check('it prints every zero-reference blob', (bool) array_filter(
    $stage->said,
    fn (string $line) => str_contains($line, BlobFile::keyOf($zero)) && str_contains($line, bin2hex($zero['content_hash']))
));
check('it gives the brake\'s verdict', $run->braked === ['orphans']);
check('it prints the damage', (bool) array_filter($stage->said, fn (string $line) => str_contains($line, 'award:2')));

// A crash between commit and delete heals on the next run.
$stage = new Stage();
$stage->row($award, 1, 500);
$key = $stage->legacyFile($award, 1, png());
$stage->sweep();
$blobId = $stage->refOf($award, 1);
$stage->legacyFile($award, 1, png());
$stage->sweep();
check('a file left by a crash re-points to the same blob and goes', $stage->refOf($award, 1) === $blobId && !$stage->exists($key));
check('and mints no second blob', blobCount($stage) === 1);

// Damage volume.
$stage = new Stage();
for ($id = 1; $id <= 120; $id++) {
    $stage->row($award, $id, 0);
    $stage->legacyFile($award, $id, png());
}
$run = $stage->sweep();
check('the log takes 100 entries and one total', count($stage->errorLog) === 101 && str_contains(end($stage->errorLog), '120'));
check('the run still counts all of it', $run->damage === 120);
check('the operator still sees all of it', count(array_filter($stage->said, fn (string $l) => str_contains($l, 'damage:'))) === 120);

// The job's path: fresh Sweeper and a serialised progress between every unit of work.
$stepped = new Stage();
$whole = new Stage();
foreach ([$stepped, $whole] as $board) {
    for ($id = 999; $id <= 1002; $id++) {
        $board->row($award, $id, 500);
        $board->legacyFile($award, $id, png(3, 3, sprintf('#%06x', $id)));
    }
    $board->legacyFile($record, 2500, png());
    $board->blob(png(50, 50));
}
$whole->sweep();
$progress = new SweepProgress();
while (!$progress->done()) {
    $stepped->sweeper()->advance($progress);
    $progress = SweepProgress::fromArray(unserialize(serialize($progress->toArray())));
}
$counts = fn (Stage $board) => [
    $board->db->fetchOne('SELECT COUNT(*) FROM ' . BlobStore::TABLE),
    $board->db->fetchOne('SELECT SUM(ref_count) FROM ' . BlobStore::TABLE),
    count($board->deletions('orphan_rowless')),
    count($board->deletions('zero_reference')),
];
check('a stepped run ends where a whole run ends', $counts($stepped) === $counts($whole));
check('and finishes the work', $progress->tookIn === 4 && $progress->orphansDeleted === 1 && $progress->zeroDeleted === 1);

// The job drops the lock between steps, so files can turn orphan and a revert can free
// blobs after the brake's count.
$stage = new Stage();
$stage->legacyFile($record, 2500, png());
$progress = new SweepProgress();
while (!$progress->done()) {
    $phase = $progress->phase;
    $stage->sweeper()->advance($progress);
    if ($phase === SweepProgress::ORPHAN_COUNT && $progress->phase !== $phase) {
        for ($id = 1; $id <= 51; $id++) {
            $stage->legacyFile($award, $id, png(1, 1, sprintf('#%06x', $id)));
        }
    }
    if ($phase === SweepProgress::ZERO_COUNT) {
        for ($i = 1; $i <= 51; $i++) {
            $stage->blob(png(1, 1, sprintf('#%06x', $i)));
        }
    }
}
check('the delete pass never deletes more orphans than it counted', $progress->orphans === 1 && $progress->orphansDeleted === 1);
check('blobs freed after the count wait for the next run', $progress->zeroDeleted === 0 && blobCount($stage) === 51);

finish();
