<?php

/**
 * What refuses a grant, what a correction remakes, and who may do what. The services and
 * entities that carry these out are checked on a dev stack.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/GrantRulesTest.php
 */

namespace Cav7\Citations\Tests;

spl_autoload_register(function (string $class): void {
    foreach (['Render', 'Template', 'Grant'] as $directory) {
        $prefix = "Cav7\\Citations\\$directory\\";
        if (str_starts_with($class, $prefix)) {
            require __DIR__ . "/../$directory/" . substr($class, strlen($prefix)) . '.php';
        }
    }
});

use Cav7\Citations\Grant\Actor;
use Cav7\Citations\Grant\Correction;
use Cav7\Citations\Grant\FieldChange;
use Cav7\Citations\Grant\GrantChanges;
use Cav7\Citations\Grant\GrantForm;
use Cav7\Citations\Grant\GrantRules;
use Cav7\Citations\Grant\GrantValues;
use Cav7\Citations\Grant\IssueRequest;
use Cav7\Citations\Grant\LogSummary;
use Cav7\Citations\Grant\MemberChanges;
use Cav7\Citations\Grant\MemberValues;
use Cav7\Citations\Grant\PageAccess;
use Cav7\Citations\Grant\PreviewFit;
use Cav7\Citations\Grant\RemakePlan;
use Cav7\Citations\Grant\RetypesetReport;
use Cav7\Citations\Grant\RetypesetSelection;
use Cav7\Citations\Grant\Typesetting;
use Cav7\Citations\Grant\TypesettingDiff;
use Cav7\Citations\Render\ImageFile;
use Cav7\Citations\Render\InputDefinition;
use Cav7\Citations\Render\Line;
use Cav7\Citations\Render\PinnedSignature;
use Cav7\Citations\Render\PlacedImage;
use Cav7\Citations\Render\RankArtField;
use Cav7\Citations\Render\RankSnapshot;
use Cav7\Citations\Render\SetField;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\TextField;
use Cav7\Citations\Render\Version;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}

function text(string $key, string $pattern): TextField
{
    return new TextField($key, 0, 0, 100, 100, $pattern, 'tinos-regular', 20.0, 0.0, 1.2, 'wrap', 'left', false, '#000000');
}

function slot(string $key, int $billetId): SignatureSlot
{
    return new SignatureSlot($key, 0, 0, 100, 100, $billetId, 40, 'tinos-regular', 12.0, 0.0, 1.2, '#000000');
}

/** @param list<array{field: string, rule: string}> $problems */
function rules(array $problems): array
{
    $rules = array_map(fn (array $problem): string => trim("{$problem['rule']} {$problem['field']}"), $problems);
    sort($rules);
    return $rules;
}

/** @param list<TextField|SignatureSlot|RankArtField> $fields */
function keys(array $fields): array
{
    $keys = array_map(fn (object $field): string => $field->key, $fields);
    sort($keys);
    return $keys;
}

$rank = new RankSnapshot('Sergeant', 'E-5', 'SGT');
$newRank = new RankSnapshot('Staff Sergeant', 'E-6', 'SSG');

// A written citation with a member rank, a member input, a grant input and one slot.
$written = new Version(
    [
        text('recipient', '{rank} {name}'),
        text('body', '{citation_text}'),
        text('dated', '{day} {month} {year}'),
        text('unit_line', '{input:unit}'),
        text('role_line', '{input:role} for {input:unit}'),
        slot('sig', 5),
        new RankArtField('insignia', 0, 0, 10, 10),
    ],
    [
        new InputDefinition('unit', 'Unit', 'grant', true),
        new InputDefinition('role', 'Role', 'member', true),
        new InputDefinition('note', 'Note', 'grant', false),
    ],
    'member',
);

// What a regiment grant can use: no member, a rank on the grant with art.
$regimentVersion = new Version(
    [text('dated', '{year}'), text('rank_line', '{rank}'), slot('sig', 5), new RankArtField('insignia', 0, 0, 10, 10)],
    [],
    'grant',
);

$generic = new Version([text('dated', '{year}')], [], 'none');

$signed = ['sig' => 11];
$billets = [11 => 5, 12 => 9];
$member = fn (int $relationId = 1, array $inputs = ['role' => 'Rifleman'], string $name = 'Ewan McQuill', ?int $rankId = 7): MemberValues
    => new MemberValues($relationId, $name, $rankId, $inputs, $rankId === null ? null : $rank);
$values = fn (array ...$overrides): GrantValues => new GrantValues(...array_merge(
    ['date' => '2026-06-29', 'citationText' => 'For valour.', 'signatures' => $signed, 'inputs' => ['unit' => 'A Troop'], 'members' => [$member()]],
    ...$overrides,
));
$clerk = new Actor(3, 'Clerk', [Actor::ISSUE]);
$manager = new Actor(4, 'Manager', [Actor::REGIMENT]);

// I-2.
foreach ([
    'a listed award is a regiment grant' => ['award', 61, [61, 62], true],
    'an unlisted award is a member grant' => ['award', 12, [61, 62], false],
    'a record type is never listed' => ['record_type', 61, [61, 62], false],
    'an empty list makes no regiment grant' => ['award', 61, [], false],
] as $label => [$type, $id, $listed, $expected]) {
    check($label, GrantRules::isRegiment($type, $id, $listed) === $expected);
}

// I-4, G-17.
$kinds = ['award:12', 'award:61', 'record_type:61'];
check('a clerk\'s form leaves out regiment awards', GrantRules::listableKinds($kinds, [61], $clerk) === ['award:12', 'record_type:61']);
check('a manager\'s form lists only what they can issue', GrantRules::listableKinds($kinds, [61], $manager) === ['award:61']);

// I-6.
foreach ([
    'a day is midnight UTC' => ['2026-06-29', 1782691200],
    'a leap day is real' => ['2024-02-29', 1709164800],
    'a date never rolls over' => ['2026-13-40', null],
    'February 30 never rolls over' => ['2026-02-30', null],
    'blank is no date' => ['', null],
    'a time of day is no date' => ['2026-06-29 12:00', null],
    'nor is another format' => ['29/06/2026', null],
] as $label => [$entered, $expected]) {
    check($label, GrantRules::day($entered) === $expected, var_export(GrantRules::day($entered), true));
}

// The option's text.
foreach ([
    'a list of ids' => ['61, 62,63', [61, 62, 63]],
    'a repeat counts once' => ['61,61', [61]],
    'blank is no awards' => ['', []],
    'stray commas are nothing' => [',61,', [61]],
    'a word isn\'t an id' => ['61,PUC', null],
    'nor a negative' => ['-61', null],
] as $label => [$text, $expected]) {
    check($label, GrantRules::awardList($text) === $expected);
}

// Issuing, one mutation per row of a request that otherwise passes.
$issue = function (array $with = []) use ($written, $regimentVersion, $generic, $values, $clerk, $manager, $billets, $rank): array {
    $with += [
        'version' => $written, 'kind' => ['award', 12], 'shown' => 40, 'state' => 'published',
        'kinds' => ['award:12', 'award:61'], 'title' => 'Bronze', 'duplicate' => false, 'actor' => $clerk,
        'values' => $values(), 'art' => ['insignia' => [7]],
    ];
    $request = new IssueRequest($with['kind'][0], $with['kind'][1], $with['shown'], $with['values'], [61]);

    return rules(GrantRules::issueProblems(
        $request, $with['values'], $with['version'], $with['state'], $with['kinds'],
        $with['title'], $with['duplicate'], $with['actor'], $billets, $with['art'],
    ));
};

$regimentValues = new GrantValues('2026-06-29', null, $signed, [], 7, [], $rank);

foreach ([
    'a whole member grant passes' => [[], []],
    'I-3 a member grant needs a milpac' => [['values' => $values(['members' => []])], ['no_members']],
    'I-3 the same milpac twice' => [['values' => $values(['members' => [$member(1), $member(1)]])], ['duplicate_member 1']],
    'I-4 a clerk can\'t issue a listed award' => [
        ['kind' => ['award', 61], 'version' => $regimentVersion, 'values' => $regimentValues],
        ['regiment_needs_permission'],
    ],
    'I-5 a draft' => [['state' => 'draft'], ['version_not_published']],
    'I-5 a retired version' => [['state' => 'retired'], ['version_not_published']],
    'I-5 a kind the version doesn\'t serve' => [['kinds' => ['award:99']], ['kind_not_served']],
    'I-5 a record type under an award\'s id' => [['kind' => ['record_type', 12]], ['kind_not_served']],
    'an award that no longer exists' => [['title' => null], ['no_such_kind']],
    'I-6 a bad date' => [['values' => $values(['date' => '2026-13-40'])], ['bad_date']],
    'I-8 a written version with no text' => [['values' => $values(['citationText' => null])], ['citation_text_required']],
    'I-8 whitespace is no text' => [['values' => $values(['citationText' => " \n "])], ['citation_text_required']],
    'I-9 an empty slot' => [['values' => $values(['signatures' => []])], ['slot_empty sig']],
    'I-9 the wrong billet' => [['values' => $values(['signatures' => ['sig' => 12]])], ['signature_wrong_billet sig']],
    'I-9 a signature that\'s gone' => [['values' => $values(['signatures' => ['sig' => 99]])], ['signature_wrong_billet sig']],
    'I-10 a required grant input' => [['values' => $values(['inputs' => []])], ['input_required unit']],
    'I-10 an optional one blocks nothing' => [['values' => $values(['inputs' => ['unit' => 'A Troop', 'note' => '']])], []],
    'I-10 a required member input' => [['values' => $values(['members' => [$member(1, [])]])], ['input_required 1: role']],
    'I-11 a blank name where one prints' => [['values' => $values(['members' => [$member(1, ['role' => 'x'], '  ')]])], ['name_required 1']],
    'I-12 no member rank' => [['values' => $values(['members' => [$member(1, ['role' => 'x'], 'Ewan', null)]])], ['rank_required 1']],
    'I-12 a member rank with no snapshot is no rank' => [
        ['values' => $values(['members' => [new MemberValues(1, 'Ewan', 7, ['role' => 'x'])]])],
        ['no_such_rank 1'],
    ],
    'every refusal at once' => [
        ['values' => $values(['citationText' => '', 'signatures' => [], 'inputs' => []])],
        ['citation_text_required', 'input_required unit', 'slot_empty sig'],
    ],
] as $label => [$with, $expected]) {
    $got = $issue($with);
    check($label, $got === $expected, json_encode($got));
}

$regiment = ['kind' => ['award', 61], 'version' => $regimentVersion, 'values' => $regimentValues, 'actor' => $manager];
$memberVersion = new Version([text('who', '{name}')], [], 'none');
$memberInput = new Version([text('dated', '{year}')], [new InputDefinition('role', 'Role', 'member', false)], 'none');
$memberRank = new Version([text('dated', '{year}')], [], 'member');

foreach ([
    'a whole regiment grant passes' => [[], []],
    'I-3 a regiment grant takes no members' => [['values' => new GrantValues('2026-06-29', null, $signed, [], 7, [new MemberValues(1)], $rank)], ['regiment_takes_no_members']],
    'I-16 a version printing a name' => [['version' => $memberVersion], ['version_needs_member']],
    'I-16 a version with a member input' => [['version' => $memberInput], ['version_needs_member']],
    'I-16 a version with a member rank' => [['version' => $memberRank], ['version_needs_member']],
    'I-17 a second live grant on that date' => [['duplicate' => true], ['regiment_duplicate']],
    'I-12 a grant rank is required' => [['values' => new GrantValues('2026-06-29', null, $signed)], ['rank_required']],
    'G-20 a rank the art doesn\'t cover' => [['art' => ['insignia' => [8]]], ['rank_without_art']],
] as $label => [$with, $expected]) {
    $got = $issue($with + $regiment);
    check($label, $got === $expected, json_encode($got));
}

$got = $issue(['version' => $generic, 'values' => $values(['citationText' => null, 'signatures' => [], 'inputs' => []])]);
check('I-8 a generic citation needs no text, I-12 no rank, G-33 no input', $got === [], json_encode($got));

// I-8, I-12: what a version keeps of a form's values.
$fitted = $values(['rankId' => 9, 'inputs' => ['unit' => 'A', 'stray' => 'x', 'role' => 'x']])->fittedTo($written);
check('I-10 an undeclared or wrongly scoped input isn\'t stored', $fitted->inputs === ['unit' => 'A'], json_encode($fitted->inputs));
check('I-12 a grant rank goes on a member-rank version', $fitted->rankId === null);
$fitted = $values(['members' => [new MemberValues(1, 'Ewan', 7, ['role' => 'x', 'unit' => 'y'], $rank)]])->fittedTo($generic);
check('I-8 a generic citation stores no text', $fitted->citationText === null);
check('I-12 with no rank scope the member holds none', $fitted->members[0]->rankId === null && $fitted->members[0]->rank === null);
check('a member keeps only member inputs', $fitted->members[0]->inputs === []);

// G-30, G-31: a snapshot is taken once.
$held = new MemberValues(1, 'Ewan', 7, [], $rank);
$picked = new MemberValues(2, 'Niamh', 8);
$snapped = (new GrantValues('2026-06-29', null, [], [], 7, [$held, $picked]))->snapshotted([7 => $newRank, 8 => $newRank]);
check('a picked rank takes its snapshot', $snapped->members[1]->rank === $newRank && $snapped->rank === $newRank);
check('a held snapshot never moves', $snapped->members[0]->rank === $rank);
check('only ranks with no snapshot are pending', (new GrantValues('2026-06-29', null, [], [], null, [$held, $picked]))->pendingRanks() === [8]);

// M-1 to M-5, I-11.
foreach ([
    'M-1 a member grant takes a member' => [false, [2], $member(1), []],
    'M-1 a grant with no members left still does' => [false, [], $member(1), []],
    'M-1 a regiment grant doesn\'t' => [true, [], $member(1), ['not_a_member_grant']],
    'M-4 a milpac already on it' => [false, [1, 2], $member(1), ['duplicate_member 1']],
    'M-5 a required member input' => [false, [], $member(1, []), ['input_required 1: role']],
] as $label => [$isRegiment, $held, $adding, $expected]) {
    $got = rules(GrantRules::addProblems($isRegiment, $held, $written, $adding));
    check($label, $got === $expected, json_encode($got));
}

// W-1.
foreach ([
    'W-1 a live regiment grant withdraws' => [true, 0, 'Issued in error', []],
    'W-1 a reason is required' => [true, 0, '  ', ['reason_required']],
    'W-1 a member grant can\'t' => [false, 0, 'x', ['not_a_regiment_grant']],
    'W-5 nor can a withdrawn one' => [true, 1000, 'x', ['already_withdrawn']],
] as $label => [$isRegiment, $withdrawn, $reason, $expected]) {
    $got = rules(GrantRules::withdrawProblems($isRegiment, $withdrawn, $reason));
    check($label, $got === $expected, json_encode($got));
}

// C-7: which fields each change remakes.
$two = fn (array ...$overrides): GrantValues => new GrantValues(...array_merge(
    [
        'date' => '2026-06-29', 'citationText' => 'For valour.', 'signatures' => $signed, 'inputs' => ['unit' => 'A Troop'],
        'members' => [401 => new MemberValues(1, 'Ewan McQuill', 7, ['role' => 'Rifleman'], $rank), 402 => new MemberValues(2, 'Niamh Lorcan', 7, ['role' => 'Medic'], $rank)],
    ],
    ...$overrides,
));
$remade = fn (GrantValues $new): array => (function () use ($written, $two, $new): array {
    $plan = Correction::between($written, $two(), $new)->remake;
    return ['shared' => keys($plan->shared), 'members' => array_map('Cav7\Citations\Tests\keys', $plan->members)];
})();
$changing = fn (GrantChanges $changes): GrantValues => $two()->changed($changes, $written)->snapshotted([8 => $newRank]);

foreach ([
    'a name fix remakes that member\'s name fields, nothing else' => [
        new GrantChanges(members: [401 => new MemberChanges(name: 'Ewan McQuillan')]),
        ['shared' => [], 'members' => [401 => ['recipient']]],
    ],
    'a member\'s rank remakes that member\'s rank fields' => [
        new GrantChanges(members: [402 => new MemberChanges(rankId: 8)]),
        ['shared' => [], 'members' => [402 => ['insignia', 'recipient']]],
    ],
    'a member\'s input remakes that member' => [
        new GrantChanges(members: [402 => new MemberChanges(inputs: ['role' => 'Scout'])]),
        ['shared' => [], 'members' => [402 => ['role_line']]],
    ],
    'citation text remakes the shared field alone' => [
        new GrantChanges(citationText: 'For gallantry.'),
        ['shared' => ['body'], 'members' => []],
    ],
    'a date remakes what prints a date' => [
        new GrantChanges(date: '2026-07-01'),
        ['shared' => ['dated'], 'members' => []],
    ],
    'a signature remakes its slot' => [
        new GrantChanges(signatures: ['sig' => 12]),
        ['shared' => ['sig'], 'members' => []],
    ],
    'a shared value in a member-row field remakes it for every member' => [
        new GrantChanges(inputs: ['unit' => 'B Troop']),
        ['shared' => ['unit_line'], 'members' => [401 => ['role_line'], 402 => ['role_line']]],
    ],
    'a shared and a member change together' => [
        new GrantChanges(inputs: ['unit' => 'B Troop'], members: [401 => new MemberChanges(name: 'Ewan McQuillan')]),
        ['shared' => ['unit_line'], 'members' => [401 => ['recipient', 'role_line'], 402 => ['role_line']]],
    ],
    'C-5 restating what\'s there remakes nothing' => [
        new GrantChanges(date: '2026-06-29', citationText: 'For valour.', members: [401 => new MemberChanges(name: 'Ewan McQuill', rankId: 7)]),
        ['shared' => [], 'members' => []],
    ],
    'an unused optional input set blank is no change' => [
        new GrantChanges(inputs: ['note' => '']),
        ['shared' => [], 'members' => []],
    ],
] as $label => [$changes, $expected]) {
    $got = $remade($changing($changes));
    check($label, $got === $expected, json_encode($got));
}

// C-1: the log's old and new values.
$correction = Correction::between($written, $two(), $changing(new GrantChanges(
    citationText: 'For gallantry.',
    members: [402 => new MemberChanges(name: 'Niamh Lorcan-Hale', rankId: 8)],
)));
check('C-1 a shared change carries old and new', in_array(['what' => 'citation_text', 'old' => 'For valour.', 'new' => 'For gallantry.'], $correction->changes, true));
check('C-1 a member\'s change names the member', in_array(['what' => 'name', 'old' => 'Niamh Lorcan', 'new' => 'Niamh Lorcan-Hale', 'member' => 402], $correction->changes, true));
check('C-1 a rank change names the ranks, with the new snapshot', in_array([
    'what' => 'rank',
    'old' => ['rank_id' => 7, 'rank_title' => 'Sergeant'],
    'new' => ['rank_id' => 8, 'rank_title' => 'Staff Sergeant'],
    'member' => 402,
], $correction->changes, true), json_encode($correction->changes));
check('C-5 no change is empty', Correction::between($written, $two(), $two())->isEmpty());

// G-29, G-30: a correction that doesn't touch a rank leaves its snapshot alone.
$kept = $two()->changed(new GrantChanges(citationText: 'x', members: [401 => new MemberChanges(rankId: 7)]), $written);
check('restating a member\'s rank keeps the held snapshot', $kept->members[401]->rank === $rank && $kept->pendingRanks() === []);

// Corrections that refuse.
$reasoned = fn (GrantChanges $changes, string $reason = 'Typo'): array
    => rules(GrantRules::correctionProblems($written, $two()->changed($changes, $written)->snapshotted([]), $changes, $reason, false, $billets, []));

foreach ([
    'a whole correction passes' => [new GrantChanges(citationText: 'For gallantry.'), 'Typo', []],
    'C-1 a reason is required' => [new GrantChanges(citationText: 'For gallantry.'), ' ', ['reason_required']],
    'I-8 text can\'t be corrected away' => [new GrantChanges(citationText: ''), 'Typo', ['citation_text_required']],
    'I-6 nor a date made bad' => [new GrantChanges(date: '2026-02-30'), 'Typo', ['bad_date']],
    'I-9 a signature of another billet' => [new GrantChanges(signatures: ['sig' => 12]), 'Typo', ['signature_wrong_billet sig']],
    'I-10 a required input blanked' => [new GrantChanges(inputs: ['unit' => '']), 'Typo', ['input_required unit']],
    'I-11 a name blanked' => [new GrantChanges(members: [401 => new MemberChanges(name: '')]), 'Typo', ['name_required 1']],
    'a rank that doesn\'t exist' => [new GrantChanges(members: [401 => new MemberChanges(rankId: 999)]), 'Typo', ['no_such_rank 1']],
    'a member no longer on the grant' => [new GrantChanges(members: [499 => new MemberChanges(name: 'x')]), 'Typo', ['no_such_member 499']],
] as $label => [$changes, $reason, $expected]) {
    $got = $reasoned($changes, $reason);
    check($label, $got === $expected, json_encode($got));
}

check('I-17 a date landing on a live regiment grant refuses', rules(GrantRules::correctionProblems(
    $regimentVersion,
    $regimentValues,
    new GrantChanges(date: '2026-07-01'),
    'Wrong day',
    true,
    $billets,
    ['insignia' => [7]],
)) === ['regiment_duplicate']);

// C-9, G-34: moving to another version.
$moved = fn (Version $target, GrantChanges $extra = new GrantChanges()): GrantValues
    => $two()->movedTo($target, $extra, $billets)->snapshotted([8 => $newRank]);

$slotsMoved = new Version([text('b', '{citation_text}'), slot('co_sig', 5), slot('xo_sig', 9)], [new InputDefinition('unit', 'Unit', 'grant', true)], 'none');
$got = $moved($slotsMoved);
check('C-9 a slot fills from the pinned signature with its billet', $got->signatures === ['co_sig' => 11], json_encode($got->signatures));
check('C-9 a slot nothing fills comes back missing', rules(GrantRules::valueProblems($slotsMoved, $got, $billets, [])) === ['slot_empty xo_sig']);
$got = $moved($slotsMoved, new GrantChanges(signatures: ['xo_sig' => 12]));
check('C-9 supplying it clears the list', rules(GrantRules::valueProblems($slotsMoved, $got, $billets, [])) === []);

$needsInput = new Version([text('b', '{input:award_number}')], [new InputDefinition('award_number', 'Number', 'grant', true), new InputDefinition('unit', 'Unit', 'grant', true)], 'none');
$got = $moved($needsInput);
check('G-34 a value the grant holds carries by key', $got->inputs === ['unit' => 'A Troop']);
check('G-34 a required input it doesn\'t hold is missing', rules(GrantRules::valueProblems($needsInput, $got, $billets, [])) === ['input_required award_number']);
$got = $moved(new Version([text('b', '{year}')], [], 'none'));
check('G-34 a value the new version doesn\'t use is kept', $got->inputs === ['unit' => 'A Troop'] && $got->citationText === 'For valour.');

$needsGrantRank = new Version([text('b', '{rank}')], [], 'grant');
check('G-34 a rank the new version needs is missing', rules(GrantRules::valueProblems($needsGrantRank, $moved($needsGrantRank), $billets, [])) === ['rank_required']);
$got = $moved($needsGrantRank, new GrantChanges(rankId: 8));
check('G-34 supplying the rank snapshots it', $got->rank === $newRank && rules(GrantRules::valueProblems($needsGrantRank, $got, $billets, [])) === []);

$plan = RemakePlan::everything($written, [401, 402]);
check('C-9 a move remakes every field', keys($plan->shared) === ['body', 'dated', 'sig', 'unit_line'] && array_keys($plan->members) === [401, 402] && keys($plan->members[402]) === ['insignia', 'recipient', 'role_line']);
check('M-6 an added member sets only their own rows', RemakePlan::forMember($written, 0)->shared === [] && keys(RemakePlan::forMember($written, 0)->members[0]) === ['insignia', 'recipient', 'role_line']);

foreach ([
    'a published version of the template passes' => ['Moved', false, 'award:12', 2, 'published', ['award:12'], $written, []],
    'C-1 a reason is required' => ['', false, 'award:12', 2, 'published', ['award:12'], $written, ['reason_required']],
    'C-9 a draft is no target' => ['Moved', false, 'award:12', 2, 'draft', ['award:12'], $written, ['version_not_published']],
    'C-9 nor a retired version' => ['Moved', false, 'award:12', 2, 'retired', ['award:12'], $written, ['version_not_published']],
    'C-9 nor one missing' => ['Moved', false, 'award:12', null, null, [], null, ['version_not_published']],
    'C-9 nor another template\'s' => ['Moved', false, 'award:12', 3, 'published', ['award:12'], $written, ['wrong_template']],
    'C-9 it must serve the grant\'s kind' => ['Moved', false, 'award:12', 2, 'published', ['award:13'], $written, ['kind_not_served']],
    'I-16 a regiment grant needs a version with no member' => ['Moved', true, 'award:61', 2, 'published', ['award:61'], $written, ['version_needs_member']],
] as $label => [$reason, $isRegiment, $kind, $templateId, $state, $kinds, $target, $expected]) {
    $got = rules(GrantRules::moveProblems($reason, $isRegiment, $kind, 2, $templateId, $state, $kinds, $target));
    check($label, $got === $expected, json_encode($got));
}

// What the typesetter gets for each scope.
$ink = new PinnedSignature(['Col'], new ImageFile('hash', 10, 10));
$both = $two(['rankId' => 9, 'rank' => $newRank]);
$printed = $both->printed($written, $both->members[402], ['sig' => $ink], []);
check('a member rank prints the member\'s', $printed->rank === $rank && $printed->name === 'Niamh Lorcan');
check('grant and member inputs merge', $printed->inputs === ['role' => 'Medic', 'unit' => 'A Troop']);
$printed = $both->printed($regimentVersion, null, [], []);
check('a grant rank prints the grant\'s', $printed->rank === $newRank && $printed->name === '');
check('no rank scope prints no rank', $both->printed($generic, $both->members[401], [], [])->rank === null);
check('rank art follows the printed rank', $both->rankIdFor($written, $both->members[402]) === 7 && $both->rankIdFor($regimentVersion, null) === 9 && $both->rankIdFor($generic, null) === null);

// G-54, G-55.
$line = fn (string $t, float $x = 10.0, float $y = 20.0, float $w = 500.0, float $iw = 0.0): Line => new Line($t, $x, $y, $w, $iw);
$body = new SetField('body', 24.75, false, [$line('For a single act'), $line('of courage')]);
$ink = new PlacedImage('hash', 1, 2, 3, 4);
$old = new Typesetting(['body' => $body, 'sig' => new SetField('sig', 14.0, false, [], ink: $ink)], [401 => ['who' => new SetField('who', 40.0, false, [$line('EWAN')])]]);
$with = fn (array $shared = [], array $who = []): Typesetting => new Typesetting(
    $shared + $old->shared,
    [401 => $who + $old->members[401]],
);

foreach ([
    'nothing moved is no change' => [$old, []],
    'a remeasured width alone is no change, since the draw doesn\'t read it' => [
        $with(['body' => new SetField('body', 24.75, false, [$line('For a single act', w: 499.0), $line('of courage', w: 120.0)])]),
        [],
    ],
    'a smaller size is a change' => [
        $with(['body' => new SetField('body', 24.5, false, $body->lines)]),
        [[0, 'body', 24.75, 24.5, 2, 2, false, null]],
    ],
    'a moved line is a change, breaks unmoved' => [
        $with(['body' => new SetField('body', 24.75, false, [$line('For a single act', y: 21.0), $line('of courage')])]),
        [[0, 'body', 24.75, 24.75, 2, 2, false, null]],
    ],
    'an interword change is a change' => [
        $with(['body' => new SetField('body', 24.75, false, [$line('For a single act', iw: 3.0), $line('of courage')])]),
        [[0, 'body', 24.75, 24.75, 2, 2, false, null]],
    ],
    'new breaks say so' => [
        $with(['body' => new SetField('body', 24.75, false, [$line('For a single'), $line('act of'), $line('courage')])]),
        [[0, 'body', 24.75, 24.75, 2, 3, true, null]],
    ],
    'crossing below the threshold says so' => [
        $with(['body' => new SetField('body', 22.0, true, $body->lines)]),
        [[0, 'body', 24.75, 22.0, 2, 2, false, 'below']],
    ],
    'moved ink is a change' => [
        $with(['sig' => new SetField('sig', 14.0, false, [], ink: new PlacedImage('hash', 1, 9, 3, 4))]),
        [[0, 'sig', 14.0, 14.0, 0, 0, false, null]],
    ],
    'a member\'s field names the member' => [
        $with([], ['who' => new SetField('who', 39.75, false, [$line('EWAN')])]),
        [[401, 'who', 40.0, 39.75, 1, 1, false, null]],
    ],
] as $label => [$new, $expected]) {
    $got = array_map(fn (FieldChange $change): array => [
        $change->grantMemberId, $change->fieldKey, $change->oldSize, $change->newSize,
        $change->oldLines, $change->newLines, $change->breaksMoved, $change->crossing,
    ], TypesettingDiff::between($old, $new, ['body' => 23.0]));
    check("G-54 $label", $got === $expected, json_encode($got));
}

$below = new Typesetting(['body' => new SetField('body', 22.0, true, $body->lines)], []);
$above = new Typesetting(['body' => new SetField('body', 24.0, false, $body->lines)], []);
check('G-55 crossing back up says so', TypesettingDiff::between($below, $above, [])[0]->crossing === FieldChange::ABOVE);
check('a field with no stored row counts as a change', count(TypesettingDiff::between(new Typesetting([], []), $above, [])) === 1);

$report = new RetypesetReport();
$block = $report->grant(812, 41, 2, TypesettingDiff::between($old, $with(['body' => new SetField('body', 22.0, true, [$line('x')])]), ['body' => 23.0]));
$report->grant(813, 41, 1, []);
$report->failed();
check('G-55 a grant with no change prints no block', $report->grant(814, 41, 0, []) === []);
check('G-55 the block leads with the grant', str_contains($block[0], '812') && count($block) === 2);
check('G-55 the summary counts every grant selected', str_starts_with($report->summary(true), 'Selected 4 grant(s). 1 would change, 1 field(s). 1 cross(es) below'), $report->summary(true));

// G-51, G-52.
foreach ([
    'one grant' => [['12'], null, null, true, null, 'grant', [12]],
    'repeated grants' => [['12', '13'], null, null, false, 'FreeType moved', 'grant', [12, 13]],
    'a template version' => [[], '41', null, true, null, 'template-version', [41]],
    'an addon version' => [[], null, '1010070', false, 'Rebuild', 'addon-version', [1010070]],
] as $label => [$grants, $templateVersion, $addonVersion, $dryRun, $reason, $kind, $ids]) {
    $selection = RetypesetSelection::parse($grants, $templateVersion, $addonVersion, $dryRun, $reason);
    check("G-51 $label", $selection->kind === $kind && $selection->ids === $ids);
}
check('the selector logs as typed', RetypesetSelection::parse(['12', '13'], null, null, true, null)->label() === '--grant=12 --grant=13');

foreach ([
    'G-51 no selector' => [[], null, null, true, null],
    'G-51 two kinds' => [['12'], '41', null, true, null],
    'G-52 neither dry run nor reason' => [['12'], null, null, false, null],
    'G-52 a blank reason' => [['12'], null, null, false, '  '],
    'a selector that isn\'t an id' => [['twelve'], null, null, true, null],
] as $label => [$grants, $templateVersion, $addonVersion, $dryRun, $reason]) {
    try {
        RetypesetSelection::parse($grants, $templateVersion, $addonVersion, $dryRun, $reason);
        check($label, false);
    } catch (\InvalidArgumentException) {
        check($label, true);
    }
}

// Section 3: what each permission opens.
$actor = fn (string ...$held): Actor => new Actor(1, 'x', $held);
$none = $actor();
foreach ([
    'holding nothing opens nothing' => [
        !$none->canOpenGrantList() && !$none->canOpenGrant(false) && !$none->canOpenGrant(true)
        && !$none->canOpenTemplateManager() && !$none->canReadTemplates(),
    ],
    'the list opens for view, issue, remove or regiment' => [
        $actor(Actor::VIEW)->canOpenGrantList() && $actor(Actor::ISSUE)->canOpenGrantList()
        && $actor(Actor::REMOVE_MEMBERS)->canOpenGrantList() && $actor(Actor::REGIMENT)->canOpenGrantList()
        && !$actor(Actor::EDIT_DRAFTS, Actor::PUBLISH)->canOpenGrantList(),
    ],
    'a member grant opens for view, issue or remove' => [
        $actor(Actor::VIEW)->canOpenGrant(false) && $actor(Actor::ISSUE)->canOpenGrant(false)
        && $actor(Actor::REMOVE_MEMBERS)->canOpenGrant(false) && !$actor(Actor::REGIMENT)->canOpenGrant(false),
    ],
    'a regiment grant opens for view or regiment' => [
        $actor(Actor::VIEW)->canOpenGrant(true) && $actor(Actor::REGIMENT)->canOpenGrant(true)
        && !$actor(Actor::ISSUE, Actor::REMOVE_MEMBERS)->canOpenGrant(true),
    ],
    'the template manager opens for view, drafts, publish or issue' => [
        $actor(Actor::VIEW)->canOpenTemplateManager() && $actor(Actor::EDIT_DRAFTS)->canOpenTemplateManager()
        && $actor(Actor::PUBLISH)->canOpenTemplateManager() && $actor(Actor::ISSUE)->canOpenTemplateManager()
        && !$actor(Actor::REGIMENT, Actor::REMOVE_MEMBERS)->canOpenTemplateManager(),
    ],
    'issue permission doesn\'t reach a regiment grant, and the regiment one doesn\'t reach members' => [
        $actor(Actor::ISSUE)->canIssue(false) && !$actor(Actor::ISSUE)->canIssue(true)
        && $actor(Actor::REGIMENT)->canIssue(true) && !$actor(Actor::REGIMENT)->canIssue(false),
    ],
    'removing has its own permission' => [
        $actor(Actor::REMOVE_MEMBERS)->canRemoveMembers() && !$actor(Actor::ISSUE, Actor::REGIMENT)->canRemoveMembers(),
    ],
    'G-4 drafts and publishing are apart' => [
        $actor(Actor::EDIT_DRAFTS)->canEditDrafts() && !$actor(Actor::EDIT_DRAFTS)->canPublish()
        && $actor(Actor::PUBLISH)->canPublish() && !$actor(Actor::PUBLISH)->canEditDrafts(),
    ],
    'reading a template\'s design takes view or either template permission' => [
        $actor(Actor::VIEW)->canReadTemplates() && $actor(Actor::EDIT_DRAFTS)->canReadTemplates()
        && $actor(Actor::PUBLISH)->canReadTemplates() && !$actor(Actor::ISSUE)->canReadTemplates(),
    ],
] as $label => [$ok]) {
    check($label, $ok);
}

// Every grant page answers to one table, a crafted POST included. Null is a page with no kind known yet.
$none = $actor();
$view = $actor(Actor::VIEW);
$issue = $actor(Actor::ISSUE);
$remove = $actor(Actor::REMOVE_MEMBERS);
$regimentManager = $actor(Actor::REGIMENT);
$templates = $actor(Actor::EDIT_DRAFTS, Actor::PUBLISH);

foreach ([
    ['index', null, [$view, $issue, $remove, $regimentManager], [$none, $templates]],
    ['index', false, [$view, $issue, $remove], [$none, $regimentManager, $templates]],
    ['index', true, [$view, $regimentManager], [$none, $issue, $remove, $templates]],
    ['issue', null, [$issue, $regimentManager], [$none, $view, $remove, $templates]],
    ['issue', false, [$issue], [$none, $view, $remove, $regimentManager]],
    ['issue', true, [$regimentManager], [$none, $view, $remove, $issue]],
    ['form', false, [$issue], [$none, $view, $regimentManager]],
    ['form', true, [$regimentManager], [$none, $view, $issue]],
    ['preview', false, [$issue], [$none, $view, $regimentManager]],
    ['preview', true, [$regimentManager], [$none, $view, $issue]],
    ['correct', false, [$issue], [$none, $view, $remove, $regimentManager]],
    ['correct', true, [$regimentManager], [$none, $view, $remove, $issue]],
    ['move', false, [$issue], [$none, $view, $remove, $regimentManager]],
    ['move', true, [$regimentManager], [$none, $view, $remove, $issue]],
    ['addmember', false, [$issue], [$none, $view, $remove, $regimentManager]],
    ['addmember', true, [], [$issue, $regimentManager]],
    ['removemember', false, [$remove], [$none, $view, $issue, $regimentManager]],
    ['removemember', true, [], [$remove, $regimentManager]],
    ['withdraw', true, [$regimentManager], [$none, $view, $issue, $remove]],
    ['withdraw', false, [], [$regimentManager, $issue]],
    ['anythingelse', null, [], [$view, $issue, $remove, $regimentManager, $templates]],
] as [$action, $regiment, $allowed, $refused]) {
    $kind = var_export($regiment, true);
    foreach ($allowed as $i => $who) {
        check("access: $action on $kind opens for allowed #$i", PageAccess::allows($action, $who, $regiment));
    }
    foreach ($refused as $i => $who) {
        check("access: $action on $kind refuses #$i", !PageAccess::allows($action, $who, $regiment));
    }
}

// A form's post. Members arrive as a raw array, so junk in it must come out as nothing.
$posted = [
    'date' => '2026-06-29',
    'citation_text' => 'For valour.',
    'signatures' => ['sig_co' => 6, 'sig_xo' => 0],
    'inputs' => ['operation' => '  Lantern '],
    'rank_id' => 0,
    'members' => [
        3 => ['relation_id' => '10', 'name' => ' Silas Castellan ', 'rank_id' => '4', 'inputs' => ['unit' => ' A Troop ']],
        4 => ['relation_id' => '11', 'name' => 'Rook Tallis', 'rank_id' => '', 'inputs' => ['unit' => ['nested']]],
        5 => 'junk',
        6 => ['name' => 'no milpac'],
    ],
] + GrantForm::blank();
$values = GrantForm::values($posted);

foreach ([
    'a kind reads as its type and id' => [GrantForm::kind('award:15'), ['award', 15]],
    'a record type reads too' => [GrantForm::kind('record_type:3'), ['record_type', 3]],
    'a bad kind reads as nothing' => [GrantForm::kind('award:15;drop'), null],
    'no choice reads as nothing' => [GrantForm::kind(''), null],
    'an unchosen slot is left out' => [$values->signatures, ['sig_co' => 6]],
    'inputs are trimmed' => [$values->inputs, ['operation' => 'Lantern']],
    'no grant rank is null' => [$values->rankId, null],
    'junk and a member with no milpac drop out' => [array_keys($values->members), [3, 4]],
    'a member keeps their posted key, trimmed' => [[$values->members[3]->relationId, $values->members[3]->name, $values->members[3]->rankId, $values->members[3]->inputs], [10, 'Silas Castellan', 4, ['unit' => 'A Troop']]],
    'a blank member rank is null' => [$values->members[4]->rankId, null],
    'a nested input reads as empty' => [$values->members[4]->inputs, ['unit' => '']],
    'a correction carries its member changes by id' => [array_keys(GrantForm::changes($posted)->members), [3, 4, 6]],
    'a correction leaves an unposted rank alone' => [GrantForm::changes($posted)->rankId, null],
    'remove takes that member off' => [array_keys(GrantForm::acted($posted, 'remove:3')['members']), [4, 5, 6]],
    'apply copies the fill-all value to everyone' => [
        array_column(array_column(array_filter(
            GrantForm::acted(['fill_all' => ['unit' => 'B Troop']] + $posted, 'apply:unit')['members'],
            'is_array'
        ), 'inputs'), 'unit'),
        ['B Troop', 'B Troop', 'B Troop'],
    ],
    'no action leaves the form alone' => [GrantForm::acted($posted, ''), $posted],
] as $label => [$actual, $expected]) {
    check("form: $label", $actual === $expected, var_export($actual, true));
}

$reopened = GrantForm::values(GrantForm::fromValues(new GrantValues(
    '2026-06-29',
    'For valour.',
    ['sig_co' => 6],
    ['operation' => 'Lantern'],
    9,
    [14 => new MemberValues(10, 'Silas Castellan', 4, ['unit' => 'A Troop'])],
)));
check('form: a stored grant opens as the same values', [$reopened->date, $reopened->citationText, $reopened->signatures, $reopened->rankId, array_keys($reopened->members), $reopened->members[14]->name]
    === ['2026-06-29', 'For valour.', ['sig_co' => 6], 9, [14], 'Silas Castellan']);

// The stored form keys members by grant member id, so the blank tab asks for a key that isn't there.
$pair = new GrantValues('2026-06-29', members: [14 => new MemberValues(10, 'First'), 15 => new MemberValues(11, 'Second')]);
check('preview: the member asked for', $pair->previewedMember(15)->name === 'Second');
check('preview: otherwise the first', $pair->previewedMember(0)->name === 'First');
check('preview: nobody on a grant with none', (new GrantValues('2026-06-29'))->previewedMember(0) === null);

// G-47, and what a half-filled form can print.
$slot = new SignatureSlot('sig_co', 0, 0, 100, 100, 1, 50, 'tinos-regular', 19.0, 14.0, 1.2, '#000000');
$art = new RankArtField('art', 0, 0, 10, 10);
$body = new TextField('body', 0, 0, 100, 100, '{citation_text}', 'tinos-regular', 30.0, 23.0, 1.2, 'wrap', 'justify', false, '#000000');
$dateLine = new TextField('date_line', 0, 0, 100, 100, 'On {day_ordinal} {month}', 'tinos-regular', 24.0, 0.0, 1.2, 'line', 'center', false, '#000000');
$nameLine = new TextField('name_line', 0, 0, 100, 100, '{name}', 'tinos-regular', 40.0, 26.0, 1.2, 'line', 'center', false, '#000000');
$design = new Version([$body, $dateLine, $nameLine, $slot, $art], [], 'none');
$keys = fn (array $fields): array => array_map(fn (object $field): string => $field->key, $fields);

check('fit: a whole form prints everything', $keys(PreviewFit::printable($design, '2026-06-29', ['sig_co' => true])) === ['body', 'date_line', 'name_line', 'sig_co', 'art']);
check('fit: a slot with no signature waits', $keys(PreviewFit::printable($design, '2026-06-29', [])) === ['body', 'date_line', 'name_line', 'art']);
check('fit: so does a date line with no whole date', $keys(PreviewFit::printable($design, '2026-13', ['sig_co' => true])) === ['body', 'name_line', 'sig_co', 'art']);

$rows = PreviewFit::rows($design, [
    'body' => new SetField('body', 17.75, true, []),
    'name_line' => new SetField('name_line', 20.0, true, []),
]);
$fit = PreviewFit::of($design, $rows);
check('fit: a field left out still has a row to draw', array_keys($rows) === ['body', 'date_line', 'name_line', 'sig_co', 'art']);
check('fit: rank art stays art', $rows['art']->lines === null && $rows['sig_co']->lines === []);
check('fit: every flagged field warns', array_column($fit->below, 'key') === ['body', 'name_line']);
check('fit: a warning says which is the text and its threshold', $fit->below[0]['text'] && !$fit->below[1]['text'] && $fit->below[0]['legible'] === 23.0);
check('fit: the text size is reported', $fit->text === ['size' => 17.75, 'largest' => false]);
check('fit: at its cap it says so', PreviewFit::of($design, PreviewFit::rows($design, ['body' => new SetField('body', 30.0, false, [])]))->text['largest']);
check('fit: no text set reports none', PreviewFit::of($design, PreviewFit::rows($design, []))->text === null);

// The history's "what changed".
$names = [14 => 'Quenby.T'];
foreach ([
    'one line per kind of change, however many there are' => [
        LogSummary::of('corrected', ['changes' => [['what' => 'input:unit'], ['what' => 'input:operation'], ['what' => 'signature:sig_co'], ['what' => 'signature:sig_xo']]], $names),
        [['cav7_cit_log_input', []], ['cav7_cit_log_signature', []]],
    ],
    'a member\'s change names them' => [
        LogSummary::of('corrected', ['changes' => [['what' => 'name', 'member' => 14]]], $names),
        [['cav7_cit_log_member_name', ['name' => 'Quenby.T']]],
    ],
    'a member since removed falls back to their id' => [
        LogSummary::of('corrected', ['changes' => [['what' => 'rank', 'member' => 9]]], $names),
        [['cav7_cit_log_member_rank', ['name' => '#9']]],
    ],
    'an addition names the snapshot' => [LogSummary::of('member_added', ['name' => 'Tobin'], $names), [['cav7_cit_log_member_added', ['name' => 'Tobin']]]],
    'a removal too' => [LogSummary::of('member_removed', ['name' => 'Lorne'], $names), [['cav7_cit_log_member_removed', ['name' => 'Lorne']]]],
] as $label => [$actual, $expected]) {
    check("history: $label", $actual === $expected, var_export($actual, true));
}

exit($failures === 0 ? 0 : 1);
