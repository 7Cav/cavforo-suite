<?php

/**
 * Revert and purge, through real re-encode runs, so the log they read is the log a run
 * writes. Turning the option off on --all is the command's, checked on a dev stack.
 *
 * Needs the imagick extension. CI's test job declares it.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ReverterTest.php
 */

namespace Cav7\Citations\Tests;

require __DIR__ . '/support/Harness.php';

use Cav7\Citations\Storage\BlobFile;
use Cav7\Citations\Storage\BlobStore;
use Cav7\Citations\Storage\Reencoder;
use Cav7\Citations\Storage\RevertScope;
use Cav7\Citations\Storage\RowKind;

$award = RowKind::Award;
$record = RowKind::Record;

/**
 * A board after one re-encode run: awards 1 and 2 share one source, award 3 has another.
 */
function reencoded(): array
{
    $stage = new Stage();
    $award = RowKind::Award;
    $first = $stage->blob(photoPng(1));
    $second = $stage->blob(photoPng(2));
    $stage->row($award, 1, 500, (int) $first['blob_id']);
    $stage->row($award, 2, 501, (int) $first['blob_id']);
    $stage->row($award, 3, 502, (int) $second['blob_id']);
    $stage->sweep();
    $run = $stage->reencode();

    return [$stage, $first, $second, $run['run_id']];
}

function scope(Stage $stage, ?string $run = null, bool $all = false, ?string $blob = null, ?string $row = null): RevertScope
{
    return RevertScope::parse($run, $all, $blob, $row, $stage->rows);
}

function purgeAway(Stage $stage, array $blob): void
{
    $key = BlobFile::keyOf($blob);
    $list = bin2hex($blob['content_hash']) . "  $key\n";
    $stage->purger()->purge($list);
}

// Sources still on the box.
[$stage, $first, $second, $run] = reencoded();
$encodedId = $stage->refOf($award, 1);
check('a plan needs nothing while sources are on the box', $stage->reverter()->plan(scope($stage, all: true)) === []);

$result = $stage->reverter()->apply(scope($stage, run: (string) $run), null);
check('every row comes back to its source', $stage->refOf($award, 1) === (int) $first['blob_id']
    && $stage->refOf($award, 2) === (int) $first['blob_id'] && $stage->refOf($award, 3) === (int) $second['blob_id']);
check('the result counts them', $result['reverted'] === 3 && $result['left'] === 0 && $result['damage'] === 0);
check('citation_date is untouched', $stage->rows->facts($award, 2)['citation_date'] === 501);
check('a source brought back is kept as reverted', $stage->blobs->byId((int) $first['blob_id'])['keep_reason'] === 'reverted');
$entry = $stage->db->fetchRow('SELECT * FROM ' . Reencoder::LOG_TABLE . ' WHERE record_id = 1');
check('the log entry is closed', (int) $entry['reverted_date'] > 0 && (int) $entry['reverted_to_blob_id'] === (int) $first['blob_id']);
check('the revert deletes nothing', $stage->blobs->byId($encodedId) !== null && $stage->exists(BlobFile::keyOf($stage->blobs->byId($encodedId))));

$stage->sweep();
check('the next sweep deletes the zero-reference encoded blob', $stage->blobs->byId($encodedId) === null);
$stage->reencode();
check('no later run encodes a reverted source', $stage->refOf($award, 1) === (int) $first['blob_id']);

// A purged source comes back from the archive under a new id.
[$stage, $first, $second, $run] = reencoded();
$encodedId = $stage->refOf($award, 1);
$bytes = file_get_contents($stage->disk->path(BlobFile::keyOf($first)));
purgeAway($stage, $first);
check('the purged source has left the box', $stage->blobs->byId((int) $first['blob_id']) === null);

$plan = $stage->reverter()->plan(scope($stage, blob: (string) $encodedId));
check('the plan lists its key and hash once for both rows', $plan === [$first['content_hash'] => BlobFile::keyOf($first)]);

$fetched = $stage->root . '/fetched';
$fetchedFile = $fetched . '/' . BlobFile::keyOf($first);
mkdir(dirname($fetchedFile), 0777, true);

file_put_contents($fetchedFile, 'wrong bytes');
$result = $stage->reverter()->apply(scope($stage, blob: (string) $encodedId), $fetched);
check('a fetched file with the wrong hash is damage for its rows', $result['damage'] === 2 && $result['reverted'] === 0);
check('and they stay encoded', $stage->refOf($award, 1) === $encodedId);

unlink($fetchedFile);
$result = $stage->reverter()->apply(scope($stage, blob: (string) $encodedId), $fetched);
check('a missing file is damage too', $result['damage'] === 2 && $stage->refOf($award, 2) === $encodedId);

file_put_contents($fetchedFile, $bytes);
$result = $stage->reverter()->apply(scope($stage, blob: (string) $encodedId), $fetched);
$restored = $stage->refOf($award, 1);
$blob = $stage->blobs->byId($restored);
check('the right bytes come back as a new blob', $restored !== (int) $first['blob_id'] && $blob['content_hash'] === $first['content_hash']);
check('with a new token and URL', $blob['url_token'] !== $first['url_token']);
check('both rows share it', $stage->refOf($award, 2) === $restored && $result['reverted'] === 2);
check('and it is a kept source', $blob['origin'] === 'source' && $blob['keep_reason'] === 'reverted');
check('the other run\'s rows are out of scope', $stage->refOf($award, 3) !== (int) $second['blob_id']);

// What the revert leaves alone.
[$stage, $first, $second, $run] = reencoded();
$encodedId = $stage->refOf($award, 1);
$elsewhere = $stage->blob(png(9, 9));
$stage->rows->point($award, 1, (int) $elsewhere['blob_id']);
$stage->db->query("DELETE FROM {$award->table()} WHERE record_id = 2");
$stage->row($record, 50, 500, $encodedId);
$result = $stage->reverter()->apply(scope($stage, blob: (string) $encodedId), null);
check('a row that moved on stays where it went', $stage->refOf($award, 1) === (int) $elsewhere['blob_id']);
check('a deleted row is passed over', $result['left'] === 2 && $result['reverted'] === 0);
check('a row on the encoded blob with no entry stays', $stage->refOf($record, 50) === $encodedId);

// Scope.
[$stage, $first, $second, $run] = reencoded();
$other = $stage->refOf($award, 3);
$stage->reverter()->apply(scope($stage, row: 'award:1'), null);
check('naming a row reverts every row on its blob', $stage->refOf($award, 2) === (int) $first['blob_id']);
check('and only that blob', $stage->refOf($award, 3) === $other);

foreach ([
    ['no scope', [null, false, null, null]],
    ['two scopes', ['1', true, null, null]],
    ['a row with no kind', [null, false, null, '5']],
    ['an unknown kind', [null, false, null, 'post:5']],
    ['a row with no reference', [null, false, null, 'award:999']],
    ['not a number', ['one', false, null, null]],
] as [$label, $args]) {
    $refused = false;
    try {
        RevertScope::parse($args[0], $args[1], $args[2], $args[3], $stage->rows);
    } catch (\InvalidArgumentException $e) {
        $refused = true;
    }
    check("scope refused: $label", $refused);
}

// Purge.
[$stage, $first, $second, $run] = reencoded();
$list = $stage->purger()->candidateList();
$firstLine = bin2hex($first['content_hash']) . '  ' . BlobFile::keyOf($first) . "\n";
$secondLine = bin2hex($second['content_hash']) . '  ' . BlobFile::keyOf($second) . "\n";
check('the list names each replaced source by hash and key', $list === $firstLine . $secondLine);

$stage->rows->point($award, 3, (int) $second['blob_id']);
$later = $stage->blob(photoPng(8));
$stage->db->insert(Reencoder::LOG_TABLE, [
    'run_id' => $run, 'row_kind' => 'award', 'record_id' => 9, 'source_blob_id' => $later['blob_id'],
    'source_hash' => $later['content_hash'], 'source_archive_key' => BlobFile::keyOf($later), 'encoded_blob_id' => 1,
]);
$result = $stage->purger()->purge($list);
check('a listed blob with references again is skipped', $stage->blobs->byId((int) $second['blob_id']) !== null);
check('one that wasn\'t on the list stays', $stage->blobs->byId((int) $later['blob_id']) !== null);
check('the rest go', $result === ['purged' => 1, 'skipped' => 1] && !$stage->exists(BlobFile::keyOf($first)));
$log = $stage->deletions('replaced_source');
check('with a deletion log entry naming the archive key', count($log) === 1 && $log[0]['path'] === BlobFile::keyOf($first)
    && $log[0]['content_hash'] === $first['content_hash'] && (int) $log[0]['blob_id'] === (int) $first['blob_id']);

$stage = reencoded()[0];
$blob = $stage->db->fetchRow('SELECT * FROM ' . BlobStore::TABLE . ' ORDER BY blob_id');
$wrongHash = str_repeat('0', 64) . '  ' . BlobFile::keyOf($blob) . "\n";
check('a list whose hash doesn\'t match goes nowhere', $stage->purger()->purge($wrongHash) === ['purged' => 0, 'skipped' => 1]);
$wrongKey = bin2hex($blob['content_hash']) . "  roster_citation_blobs/0/1-other.png\n";
check('nor one whose key doesn\'t match', $stage->purger()->purge($wrongKey) === ['purged' => 0, 'skipped' => 1]);

$malformed = false;
try {
    $stage->purger()->purge(bin2hex($blob['content_hash']) . '  ' . BlobFile::keyOf($blob) . "\nnot a line\n");
} catch (\InvalidArgumentException $e) {
    $malformed = true;
}
check('a malformed list is refused', $malformed);
check('before anything is deleted', $stage->blobs->byId((int) $blob['blob_id']) !== null);

$stage = new Stage();
for ($i = 1; $i <= 60; $i++) {
    $blob = $stage->blob(png(1, 1, sprintf('#%06x', $i)));
    $stage->db->insert(Reencoder::LOG_TABLE, [
        'run_id' => 1, 'row_kind' => 'award', 'record_id' => $i, 'source_blob_id' => $blob['blob_id'],
        'source_hash' => $blob['content_hash'], 'source_archive_key' => BlobFile::keyOf($blob), 'encoded_blob_id' => 1,
    ]);
}
check('no brake applies to a purge', $stage->purger()->purge($stage->purger()->candidateList())['purged'] === 60);

finish();
