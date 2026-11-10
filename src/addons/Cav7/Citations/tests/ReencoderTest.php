<?php

/**
 * The re-encode run and the shared encoder profile, through real Imagick. The refusals at
 * the command's door are ReencodeRules. Holding the lock is checked on a dev stack.
 *
 * Needs the imagick extension. CI's test job declares it.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ReencoderTest.php
 */

namespace Cav7\Citations\Tests;

require __DIR__ . '/support/Harness.php';

use Cav7\Citations\Render\JpegEncoder;
use Cav7\Citations\Storage\BlobFile;
use Cav7\Citations\Storage\BlobStore;
use Cav7\Citations\Storage\Reencoder;
use Cav7\Citations\Storage\ReencodeRules;
use Cav7\Citations\Storage\RowKind;

$award = RowKind::Award;
$record = RowKind::Record;
$now = 1_800_000_000;

date_default_timezone_set('America/Chicago');

foreach ([
    ['a full timestamp', '2026-11-05T14:30:00Z', 1793889000],
    ['with an offset', '2026-11-05T09:30:00-05:00', 1793889000],
    ['a date alone', '2026-11-05', null],
    ['no timezone', '2026-11-05T14:30:00', null],
    ['relative words', 'yesterday', null],
    ['a month that does not exist', '2026-13-05T14:30:00Z', null],
] as [$label, $value, $expected]) {
    check("--synced-at: $label", ReencodeRules::parseSyncedAt($value) === $expected);
}

foreach ([
    ['off in the options', [false, true, ['JPEG'], '2026-11-05T14:30:00Z', $now], true],
    ['no Imagick', [true, false, [], '2026-11-05T14:30:00Z', $now], true],
    ['Imagick without JPEG', [true, true, [], '2026-11-05T14:30:00Z', $now], true],
    ['no --synced-at', [true, true, ['JPEG'], null, $now], true],
    ['unparseable', [true, true, ['JPEG'], 'soon', $now], true],
    ['in the future', [true, true, ['JPEG'], '2030-01-01T00:00:00Z', $now], true],
    ['all in order', [true, true, ['JPEG'], '2026-11-05T14:30:00Z', $now], false],
] as [$label, $facts, $refused]) {
    check("refusal: $label", (ReencodeRules::refusal(...$facts) !== null) === $refused);
}

check('equal size keeps the source', ReencodeRules::keepsSource(100, 100));
check('a byte smaller replaces it', !ReencodeRules::keepsSource(99, 100));

check(
    'libjpeg reads from the JPEG line',
    ReencodeRules::libjpegVersionIn("  JNG* PNG  rw-\n     JPEG* JPEG      rw-   Joint Photographic Experts Group JFIF format (libjpeg-turbo 3.1.3)\n")
        === 'libjpeg-turbo 3.1.3'
);
check('no binary means no version', ReencodeRules::libjpegVersionIn('') === '');

// The profile, read from the output's own markers.
function encoded(string $png): string
{
    $image = new \Imagick();
    $image->setFormat('png');
    $image->readImageBlob($png);

    return JpegEncoder::encode($image);
}

/**
 * @return array<int, string> marker => payload for each JPEG segment before the scan
 */
function segments(string $jpeg): array
{
    $segments = [];
    $offset = 2;
    while ($offset + 4 <= strlen($jpeg) && $jpeg[$offset] === "\xFF") {
        $marker = ord($jpeg[$offset + 1]);
        $length = unpack('n', substr($jpeg, $offset + 2, 2))[1];
        $segments[] = [$marker, substr($jpeg, $offset + 4, $length - 2)];
        if ($marker === 0xDA) {
            break;
        }
        $offset += 2 + $length;
    }

    return $segments;
}

$withAlpha = new \Imagick();
$withAlpha->newImage(40, 40, new \ImagickPixel('transparent'));
$withAlpha->setImageFormat('png');
$jpeg = encoded($withAlpha->getImageBlob());
check('output is JPEG even after a png read', BlobFile::format($jpeg) === 'jpeg');

$corner = new \Imagick();
$corner->readImageBlob($jpeg);
$pixel = $corner->getImagePixelColor(1, 1)->getColor();
check('transparency flattens onto white', $pixel['r'] >= 254 && $pixel['g'] >= 254 && $pixel['b'] >= 254);

$jpeg = encoded(photoPng());
$found = segments($jpeg);
$markers = array_column($found, 0);
$sof = current(array_filter($found, fn (array $s) => $s[0] === 0xC0));
check('baseline, not progressive', $sof !== false && !in_array(0xC2, $markers, true));
check(
    '4:4:4 sampling',
    $sof !== false && substr($sof[1], 7, 1) === "\x11" && substr($sof[1], 10, 1) === "\x11" && substr($sof[1], 13, 1) === "\x11"
);
$readBack = new \Imagick();
$readBack->readImageBlob($jpeg);
check('quality 85, as estimated from the quantisation tables', $readBack->getImageCompressionQuality() === 85);

// The code-length counts of each Huffman table, which tell the standard Annex K tables apart.
$huffmanCounts = [];
foreach (array_filter($found, fn (array $s) => $s[0] === 0xC4) as [, $payload]) {
    for ($at = 0; $at + 17 <= strlen($payload); $at += 17 + array_sum(unpack('C16', substr($payload, $at + 1, 16)))) {
        $huffmanCounts[] = bin2hex(substr($payload, $at + 1, 16));
    }
}
$annexK = [
    '00010501010101010100000000000000',
    '00030101010101010101010000000000',
    '0002010303020403050504040000017d',
    '00020102040403040705040400010277',
];
check('optimised Huffman tables, none of them Annex K', count($huffmanCounts) === 4 && !array_intersect($huffmanCounts, $annexK));

$tagged = new \Imagick();
$tagged->setFormat('png');
$tagged->readImageBlob(photoPng());
$tagged->setImageResolution(300, 300);
$tagged->setImageUnits(\Imagick::RESOLUTION_PIXELSPERINCH);
$tagged->setImageProperty('comment', 'a comment');
$tagged->setImageFormat('png');
check('sources differing only in metadata encode to the same bytes', encoded($tagged->getImageBlob()) === $jpeg);
check(
    'no ICC, comment or EXIF survives',
    !array_filter($markers, fn (int $marker) => in_array($marker, [0xE1, 0xE2, 0xFE], true))
);

// A run.
$stage = new Stage();
$bytes = photoPng();
$source = $stage->blob($bytes);
$stage->row($award, 1, 500, (int) $source['blob_id']);
$stage->row($record, 1, 501, (int) $source['blob_id']);
$stage->row($award, 2, 502);
$stage->legacyFile($award, 2, png());
$stage->sweep();

$result = $stage->reencode();
$encodedId = $stage->refOf($award, 1);
$encoded = $stage->blobs->byId($encodedId);
check('both tables move onto the encoded blob', $encodedId !== (int) $source['blob_id'] && $stage->refOf($record, 1) === $encodedId);
check('it is an encoded JPEG', $encoded['origin'] === 'encoded' && $encoded['format'] === 'jpeg');
check('it records the label and versions', $encoded['encoder_label'] === 'q85-444-islow-huffopt-white-v1'
    && $encoded['imagemagick_version'] === 'test-im' && $encoded['libjpeg_version'] === 'test-jpeg');
$encodedSize = getimagesize($stage->disk->path(BlobFile::keyOf($encoded)));
$sourceSize = getimagesizefromstring($bytes);
check('it decodes at the source\'s dimensions', [$encodedSize[0], $encodedSize[1]] === [$sourceSize[0], $sourceSize[1]]);
check('citation_date is untouched', $stage->rows->facts($award, 1)['citation_date'] === 500);
check('the source stays on disk with no references', $stage->exists(BlobFile::keyOf($source)) && !$stage->rows->hasReference((int) $source['blob_id']));

$entries = $stage->db->fetchAll('SELECT * FROM ' . Reencoder::LOG_TABLE . " WHERE source_blob_id = ? ORDER BY row_kind", [$source['blob_id']]);
check('one log entry per row, from both tables', count($entries) === 2 && $entries[0]['row_kind'] === 'award' && $entries[1]['row_kind'] === 'record');
check(
    'each names the source hash and archive key',
    $entries[0]['source_hash'] === $source['content_hash'] && $entries[0]['source_archive_key'] === BlobFile::keyOf($source)
        && (int) $entries[0]['encoded_blob_id'] === $encodedId
);

$run = $stage->db->fetchRow('SELECT * FROM ' . Reencoder::RUN_TABLE . ' WHERE run_id = ?', [$result['run_id']]);
check('the run row is closed with its counts', (int) $run['finished_date'] > 0 && (int) $run['encoded_count'] === 1 && (int) $run['rows_moved'] === 2);

$before = $stage->fingerprint();
$rerun = $stage->reencode();
$stage->db->query('DELETE FROM ' . Reencoder::RUN_TABLE . ' WHERE run_id = ?', [$rerun['run_id']]);
check('a rerun before any sweep does nothing', $rerun['encoded'] === 0 && $rerun['kept'] === 0 && $stage->fingerprint() === $before);

$stage->sweep();
check('the next sweep leaves the replaced source alone', $stage->blobs->byId((int) $source['blob_id']) !== null);

// Eligibility.
function sourceWith(Stage $stage, string $bytes, array $fields, int $recordId): int
{
    $blob = $stage->blob($bytes);
    $stage->db->update(BlobStore::TABLE, $fields, 'blob_id = ?', [$blob['blob_id']]);
    $stage->row(RowKind::Award, $recordId, 500, (int) $blob['blob_id']);

    return (int) $blob['blob_id'];
}

$stage = new Stage();
$sync = 2_000_000;
$eligible = sourceWith($stage, photoPng(1), ['ref_count' => 1, 'created_date' => $sync - 1], 1);
$tooNew = sourceWith($stage, photoPng(2), ['ref_count' => 1, 'created_date' => $sync], 2);
$kept = sourceWith($stage, photoPng(3), ['ref_count' => 1, 'created_date' => 1, 'keep_reason' => 'reverted'], 3);
$unreferenced = sourceWith($stage, photoPng(4), ['ref_count' => 0, 'created_date' => 1], 4);
$alreadyEncoded = sourceWith($stage, photoPng(5), ['ref_count' => 1, 'created_date' => 1, 'origin' => 'encoded'], 5);
$stage->reencode($sync);
check('a source from before the sync moves', $stage->refOf($award, 1) !== $eligible);
check('one created at the sync time does not', $stage->refOf($award, 2) === $tooNew);
check('nor does a kept source', $stage->refOf($award, 3) === $kept);
check('nor one the recount says has no references', $stage->refOf($award, 4) === $unreferenced);
check('nor an encoded blob', $stage->refOf($award, 5) === $alreadyEncoded);

// Kept reasons and damage.
function run(array $sources): array
{
    $stage = new Stage();
    $ids = [];
    foreach ($sources as $i => $bytes) {
        $blob = $stage->blob($bytes);
        $stage->db->update(BlobStore::TABLE, ['ref_count' => 1, 'created_date' => 1], 'blob_id = ?', [$blob['blob_id']]);
        $stage->row(RowKind::Award, $i + 1, 500, (int) $blob['blob_id']);
        $ids[$i] = (int) $blob['blob_id'];
    }

    return [$stage, $ids];
}

$gif = new \Imagick();
$gif->newImage(8, 8, new \ImagickPixel('red'));
$gif->setImageFormat('gif');
$jpegSource = new \Imagick();
$jpegSource->newImage(8, 8, new \ImagickPixel('blue'));
$jpegSource->setImageFormat('jpeg');
$tinyPng = png(4, 4);
$truncated = substr(photoPng(), 0, 4000);

[$stage, $ids] = run([$jpegSource->getImageBlob(), $gif->getImageBlob(), $tinyPng, $truncated]);
$result = $stage->reencode();
$reason = fn (int $id) => $stage->blobs->byId($id)['keep_reason'];
check('a JPEG source is kept as already_jpeg', $reason($ids[0]) === 'already_jpeg');
check('a GIF source is kept as gif', $reason($ids[1]) === 'gif');
check('a PNG that grows is kept as grew', $reason($ids[2]) === 'grew');
check('a truncated PNG is kept as decode_failed', $reason($ids[3]) === 'decode_failed');
check('its failure is logged', $result['damage'] === 1 && count($stage->errorLog) === 1);
check('kept sources keep their rows', $stage->refOf($award, 1) === $ids[0] && $stage->refOf($award, 4) === $ids[3]);
check('and each counts as kept', $result['kept'] === 4 && $result['encoded'] === 0);

[$stage, $ids] = run([photoPng(1), photoPng(2)]);
unlink($stage->disk->path(BlobFile::keyOf($stage->blobs->byId($ids[0]))));
file_put_contents($stage->disk->path(BlobFile::keyOf($stage->blobs->byId($ids[1]))), 'tampered');
$result = $stage->reencode();
check('a missing or changed source is damage', $result['damage'] === 2);
check('with no keep_reason, so a later run tries again', $stage->blobs->byId($ids[0])['keep_reason'] === null && $stage->blobs->byId($ids[1])['keep_reason'] === null);
check('its rows stay', $stage->refOf($award, 1) === $ids[0]);

// Two sources with the same pixels merge.
$plain = photoPng();
$tagged->setImageFormat('png');
[$stage, $ids] = run([$plain, $tagged->getImageBlob()]);
$result = $stage->reencode();
check('identical encodes merge onto one blob', $result['encoded'] === 2 && $stage->refOf($award, 1) === $stage->refOf($award, 2));
check('and both rows are logged against it', (int) $stage->db->fetchOne('SELECT COUNT(*) FROM ' . Reencoder::LOG_TABLE) === 2);

// A match on an existing source leaves it a source.
$jpeg = encoded(photoPng());
[$stage, $ids] = run([$jpeg, photoPng()]);
$stage->db->update(BlobStore::TABLE, ['keep_reason' => 'already_jpeg'], 'blob_id = ?', [$ids[0]]);
$stage->reencode();
$target = $stage->blobs->byId($ids[0]);
check('output landing on a source blob moves the rows there', $stage->refOf($award, 2) === $ids[0]);
check('and it stays a source blob', $target['origin'] === 'source' && $target['encoder_label'] === '');

finish();
