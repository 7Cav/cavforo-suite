<?php

/**
 * What refuses a row save, what attaching writes, and what the picker lists, with its real
 * SQL. The vendor's save actions, the image services and the row lock are checked on a dev
 * stack.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/GrantLinksTest.php
 */

namespace Cav7\Citations\Tests;

require __DIR__ . '/support/Harness.php';
require __DIR__ . '/../Grant/RowSaveRules.php';
require __DIR__ . '/../Grant/LinkedRows.php';

use Cav7\Citations\Grant\LinkedRows;
use Cav7\Citations\Grant\RowSaveRules as Rules;
use Cav7\Citations\Storage\RowKind;
use XF\Db\AbstractAdapter;

const DISCIPLINARY = 4;
const PROMOTION = 2;

/** The defaults are a record save on a plain row with cutover off and nothing to check. */
function refusal(
    RowKind $kind = RowKind::Record,
    int $type = PROMOTION,
    bool $linked = false,
    int $rowMilpac = 10,
    bool $file = false,
    int $pick = 0,
    ?int $pickMilpac = null,
    bool $cutover = false,
): ?string {
    return Rules::refusal($kind, $type, $linked, $rowMilpac, $file, $pick, $pickMilpac, $cutover);
}

foreach ([
    ['a save with nothing to check passes', refusal(), null],
    ['a file on a plain row passes with cutover off', refusal(file: true), null],
    ['a file on a linked row is refused with cutover off', refusal(file: true, linked: true), Rules::FILE_ON_LINKED_ROW],
    ['and with it on', refusal(file: true, linked: true, cutover: true), Rules::FILE_ON_LINKED_ROW],
    ['even on a Disciplinary row', refusal(file: true, linked: true, type: DISCIPLINARY, cutover: true), Rules::FILE_ON_LINKED_ROW],
    ['a linked row saved with no file passes', refusal(linked: true, cutover: true), null],
    ['a file on an award is refused with cutover on', refusal(RowKind::Award, 0, file: true, cutover: true), Rules::FILE_ON_CLOSED_KIND],
    ['an award with type 4 in the post is still closed', refusal(RowKind::Award, DISCIPLINARY, file: true, cutover: true), Rules::FILE_ON_CLOSED_KIND],
    ['a file on a closed record is refused with cutover on', refusal(file: true, cutover: true), Rules::FILE_ON_CLOSED_KIND],
    ['so is a file on a record with no type', refusal(type: 0, file: true, cutover: true), Rules::FILE_ON_CLOSED_KIND],
    ['a Disciplinary record takes a file with cutover on', refusal(type: DISCIPLINARY, file: true, cutover: true), null],
    ['a pick with cutover off is refused', refusal(pick: 5, pickMilpac: 10), Rules::PICK_BEFORE_CUTOVER],
    ['a pick on a Disciplinary record is refused', refusal(type: DISCIPLINARY, pick: 5, pickMilpac: 10, cutover: true), Rules::PICK_ON_DISCIPLINARY],
    ['a pick that has gone is refused', refusal(pick: 5, cutover: true), Rules::NO_SUCH_PICK],
    ['a pick from another milpac is refused', refusal(pick: 5, pickMilpac: 11, cutover: true), Rules::PICK_FROM_ANOTHER_MILPAC],
    ['a pick from the row\'s milpac passes', refusal(pick: 5, pickMilpac: 10, cutover: true), null],
    ['a pick swaps on a linked row', refusal(linked: true, pick: 5, pickMilpac: 10, cutover: true), null],
    ['a file refusal comes before a pick refusal', refusal(linked: true, file: true, pick: 5), Rules::FILE_ON_LINKED_ROW],
] as [$label, $actual, $expected]) {
    check("save: $label", $actual === $expected, (string) $actual);
}

check('attach: a pick writes the link, clears the rest and stamps', Rules::attachValues(null, 5, 900) === [
    'citation_grant_member_id' => 5,
    'citation_regiment_grant_id' => null,
    'citation_blob_id' => null,
    'citation_date' => 900,
]);
check('attach: a swap stamps again', Rules::attachValues(4, 5, 900)['citation_date'] === 900);
check('attach: what the row holds writes nothing', Rules::attachValues(5, 5, 900) === null);

$db = new AbstractAdapter();
$db->query('CREATE TABLE xf_nf_rosters_award (award_id INTEGER PRIMARY KEY, title TEXT)');
$db->query('CREATE TABLE xf_nf_rosters_record_type (record_type_id INTEGER PRIMARY KEY, title TEXT)');
$db->query('CREATE TABLE xf_cav7_citation_grant (grant_id INTEGER PRIMARY KEY, kind_title TEXT, grant_date INTEGER)');
$db->query('CREATE TABLE xf_cav7_citation_grant_member (grant_member_id INTEGER PRIMARY KEY, grant_id INTEGER, relation_id INTEGER)');
$db->query('CREATE TABLE xf_nf_rosters_user_award (record_id INTEGER PRIMARY KEY, relation_id INTEGER, award_id INTEGER,
    award_date INTEGER, citation_grant_member_id INTEGER NULL)');
$db->query('CREATE TABLE xf_nf_rosters_service_record (record_id INTEGER PRIMARY KEY, relation_id INTEGER, record_type_id INTEGER,
    record_date INTEGER, citation_grant_member_id INTEGER NULL)');

$db->insert('xf_nf_rosters_award', ['award_id' => 1, 'title' => 'Bronze Star']);
$db->insert('xf_nf_rosters_record_type', ['record_type_id' => 2, 'title' => 'Promotion']);
foreach ([[1, 'Older', 100], [2, 'Newer', 300], [3, 'Same day, later grant', 300], [4, 'Someone else\'s', 500]] as [$id, $title, $date]) {
    $db->insert('xf_cav7_citation_grant', ['grant_id' => $id, 'kind_title' => $title, 'grant_date' => $date]);
}
foreach ([[11, 1, 10], [12, 2, 10], [13, 3, 10], [14, 4, 77]] as [$id, $grantId, $relationId]) {
    $db->insert('xf_cav7_citation_grant_member', ['grant_member_id' => $id, 'grant_id' => $grantId, 'relation_id' => $relationId]);
}
$db->insert('xf_nf_rosters_user_award', ['record_id' => 1, 'relation_id' => 10, 'award_id' => 1, 'award_date' => 50, 'citation_grant_member_id' => 11]);
$db->insert('xf_nf_rosters_user_award', ['record_id' => 2, 'relation_id' => 10, 'award_id' => 99, 'award_date' => 60, 'citation_grant_member_id' => 11]);
$db->insert('xf_nf_rosters_service_record', ['record_id' => 3, 'relation_id' => 10, 'record_type_id' => 2, 'record_date' => 70, 'citation_grant_member_id' => 11]);
$db->insert('xf_nf_rosters_user_award', ['record_id' => 4, 'relation_id' => 10, 'award_id' => 1, 'award_date' => 80]);
$db->insert('xf_nf_rosters_user_award', ['record_id' => 5, 'relation_id' => 77, 'award_id' => 1, 'award_date' => 90, 'citation_grant_member_id' => 14]);

$linked = new LinkedRows($db);
$picker = $linked->picker(10);

check('picker: newest first, then the later grant', array_column($picker, 'grant_member_id') === [13, 12, 11]);
check('picker: an unattached citation is listed with no rows', $picker[0]['rows'] === [] && $picker[0]['kind_title'] === 'Same day, later grant');
check('picker: it carries the date', $picker[0]['grant_date'] === 300);
check('picker: an attached one stays, with every row on either table', array_column($picker[2]['rows'], 'record_id') === [1, 2, 3]);
check('picker: rows carry type, kind title and date', $picker[2]['rows'][0] === [
    'row_type' => 'award', 'record_id' => 1, 'relation_id' => 10, 'kind_title' => 'Bronze Star', 'row_date' => 50,
]);
check('picker: a record row reads its record type', $picker[2]['rows'][2]['row_type'] === 'record' && $picker[2]['rows'][2]['kind_title'] === 'Promotion');
check('picker: a deleted kind reads as empty', $picker[2]['rows'][1]['kind_title'] === '');
check('picker: another member\'s citation never appears', !in_array(14, array_column($picker, 'grant_member_id'), true));
check('picker: a milpac with nothing lists nothing', $linked->picker(999) === []);

check('rows: every row linking the member, on either table', array_column($linked->onMember(11), 'record_id') === [1, 2, 3]);
check('rows: a member on no rows has none', $linked->onMember(12) === []);

finish();
