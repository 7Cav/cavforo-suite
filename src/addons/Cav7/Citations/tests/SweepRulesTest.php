<?php

/**
 * The sweep's plain decisions, and blob format and path rules.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/SweepRulesTest.php
 */

namespace Cav7\Citations\Tests;

require __DIR__ . '/support/Harness.php';

use Cav7\Citations\Storage\BlobFile;
use Cav7\Citations\Storage\RowKind;
use Cav7\Citations\Storage\SweepRules;

foreach ([
    ['JPEG signature', "\xFF\xD8\xFF\xE0rest", 'jpeg'],
    ['PNG signature', "\x89PNG\r\n\x1A\nrest", 'png'],
    ['GIF87a', 'GIF87a..', 'gif'],
    ['GIF89a', 'GIF89a..', 'gif'],
    ['a PNG named nothing else still needs all 8 bytes', "\x89PNG\r\n", null],
    ['empty bytes', '', null],
    ['a WebP is not a format we store', 'RIFF....WEBP', null],
] as [$label, $bytes, $expected]) {
    check("format: $label", BlobFile::format($bytes) === $expected);
}

check(
    'the path shards by floor(id/1000) and carries the format\'s extension',
    BlobFile::key(41234, 'abc', 'jpeg') === 'roster_citation_blobs/41/41234-abc.jpg'
        && BlobFile::key(999, 'abc', 'png') === 'roster_citation_blobs/0/999-abc.png'
);

$threw = false;
try {
    BlobFile::key(1, 'abc', 'webp');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('no path for a format with no extension', $threw);

foreach ([
    ['the vendor\'s own name', '12345.jpg', 12, 12345],
    ['first id of a shard', '12000.jpg', 12, 12000],
    ['id in the wrong shard', '12345.jpg', 11, null],
    ['a png name', '12345.png', 12, null],
    ['leading zeros are not the vendor\'s', '012345.jpg', 12, null],
    ['not digits', 'abc.jpg', 0, null],
    ['index.html', 'index.html', 0, null],
] as [$label, $name, $shard, $expected]) {
    check("legacy name: $label", SweepRules::legacyRecordId($name, $shard) === $expected);
}

foreach ([
    ['no row is an orphan', null, SweepRules::ORPHAN_ROWLESS],
    ['grant-linked is an orphan whatever the date', ['citation_date' => 0, 'grant_linked' => true], SweepRules::ORPHAN_GRANT_LINKED],
    ['grant-linked with a date is too', ['citation_date' => 9, 'grant_linked' => true], SweepRules::ORPHAN_GRANT_LINKED],
    ['a row with no citation is damage', ['citation_date' => 0, 'grant_linked' => false], SweepRules::DAMAGE],
    ['a row with a citation is taken in', ['citation_date' => 9, 'grant_linked' => false], SweepRules::TAKE_IN],
    ['a signed date is still a citation', ['citation_date' => -5, 'grant_linked' => false], SweepRules::TAKE_IN],
] as [$label, $row, $expected]) {
    check("classify: $label", SweepRules::classify($row) === $expected);
}

check('a file at exactly an hour is settled', SweepRules::isSettled(1000, 1000 + 3600));
check('a file a second under an hour is not', !SweepRules::isSettled(1000, 1000 + 3599));

foreach ([
    ['50 passes', 50, false, false],
    ['51 holds', 51, false, true],
    ['the flag lifts it', 5000, true, false],
] as [$label, $count, $lifted, $expected]) {
    check("brake: $label", SweepRules::brakeHolds($count, $lifted) === $expected);
}

foreach ([
    ['a healthy blob row', [9, 3, false, true, false], 0],
    ['a row waiting on its legacy file', [9, null, false, false, true], 0],
    ['citation, nothing behind it', [9, null, false, false, false], 1],
    ['grant link needs no file', [9, null, true, false, false], 0],
    ['reference to a missing blob', [9, 3, false, false, false], 1],
    ['reference with no citation', [0, 3, false, true, false], 1],
    ['reference on a grant-linked row', [9, 3, true, true, false], 1],
] as [$label, $facts, $expected]) {
    check("row damage: $label", count(SweepRules::rowDamage(...$facts)) === $expected);
}

check(
    'a legacy key is the vendor\'s path',
    RowKind::Award->legacyKey(1234) === 'roster_award_citations/1/1234.jpg'
        && RowKind::Record->legacyKey(7) === 'roster_record_citations/0/7.jpg'
);

finish();
