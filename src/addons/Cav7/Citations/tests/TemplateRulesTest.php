<?php

/**
 * Template versions' states, what a grant form offers, and signature pickers and freezing.
 * The entities' use of these rules is checked on a dev stack.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/TemplateRulesTest.php
 */

namespace Cav7\Citations\Tests;

spl_autoload_register(function (string $class): void {
    foreach (['Render', 'Template'] as $directory) {
        $prefix = "Cav7\\Citations\\$directory\\";
        if (str_starts_with($class, $prefix)) {
            require __DIR__ . "/../$directory/" . substr($class, strlen($prefix)) . '.php';
        }
    }
});

use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\Version;
use Cav7\Citations\Template\PayGradeSeed;
use Cav7\Citations\Template\SignatureChoices;
use Cav7\Citations\Template\SignatureRules;
use Cav7\Citations\Template\TemplateChoices;
use Cav7\Citations\Template\VersionLifecycle;

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

// G-9, G-10: what a save may do from each state.
$draft = 'draft';
$published = 'published';
$retired = 'retired';

foreach ([
    'a new row is a draft' => [null, $draft, ['title'], null],
    'a new row can\'t arrive published' => [null, $published, [], VersionLifecycle::NOT_A_DRAFT],
    'a draft edits freely' => [$draft, $draft, ['plate_hash', 'field_definitions', 'rank_scope'], null],
    'publish writes its whole set in one save' => [$draft, $published, ['state', 'font_hashes', 'publish_user_id', 'publish_date'], null],
    'a draft can\'t be retired' => [$draft, $retired, ['state', 'retire_date'], VersionLifecycle::BAD_TRANSITION],
    'retire touches state and date' => [$published, $retired, ['state', 'retire_date'], null],
    'a retired version goes back' => [$retired, $published, ['state', 'retire_date'], null],
    'a published version can\'t go back to draft' => [$published, $draft, ['state'], VersionLifecycle::BAD_TRANSITION],
    'a retired version can\'t go back to draft' => [$retired, $draft, ['state'], VersionLifecycle::BAD_TRANSITION],
    'a published plate is frozen' => [$published, $published, ['plate_hash'], VersionLifecycle::VERSION_FROZEN],
    'published fields are frozen' => [$published, $published, ['field_definitions'], VersionLifecycle::VERSION_FROZEN],
    'pinned fonts are frozen' => [$published, $published, ['font_hashes'], VersionLifecycle::VERSION_FROZEN],
    'a retired version is frozen too' => [$retired, $retired, ['rank_scope'], VersionLifecycle::VERSION_FROZEN],
    'retiring can\'t smuggle an edit' => [$published, $retired, ['state', 'retire_date', 'plate_hash'], VersionLifecycle::VERSION_FROZEN],
    'a save with no change is fine' => [$published, $published, [], null],
] as $label => [$prior, $state, $changed, $expected]) {
    $got = VersionLifecycle::refusal($prior, $state, $changed);
    check($label, $got === $expected, var_export($got, true));
}

// G-3, G-11.
check('only a draft is deletable', VersionLifecycle::isDraft($draft) && !VersionLifecycle::isDraft($published) && !VersionLifecycle::isDraft($retired));

// G-1, G-2.
check('a new template starts at version 1', VersionLifecycle::nextNumber([]) === 1);
check('a draft takes the next number, even past a gap', VersionLifecycle::nextNumber([1, 3, 2]) === 4);

$copied = VersionLifecycle::draftFrom([
    'template_version_id' => 7, 'template_id' => 2, 'version_number' => 3, 'state' => $published,
    'plate_hash' => 'abc', 'plate_format' => 'png', 'plate_width' => 1275, 'plate_height' => 1650,
    'rank_scope' => 'grant', 'field_definitions' => '[1]', 'input_definitions' => '[2]',
    'font_hashes' => '{"a":"b"}', 'create_user_id' => 9, 'create_date' => 5,
    'publish_user_id' => 9, 'publish_date' => 6, 'retire_date' => 7,
]);
ksort($copied);
check('a draft copies the design and nothing of the version\'s history', $copied === [
    'field_definitions' => '[1]', 'input_definitions' => '[2]', 'plate_format' => 'png', 'plate_hash' => 'abc',
    'plate_height' => 1650, 'plate_width' => 1275, 'rank_scope' => 'grant',
], json_encode($copied));

// G-14, G-15, G-16.
function versionFacts(int $id, int $template, int $number, string $state, array $kinds): array
{
    return [
        'template_version_id' => $id, 'template_id' => $template, 'version_number' => $number,
        'state' => $state, 'kinds' => $kinds,
    ];
}

$v1 = versionFacts(11, 1, 1, $published, ['award:5', 'award:6']);
$v2 = versionFacts(12, 1, 2, $published, ['award:5']);
$v3Draft = versionFacts(13, 1, 3, $draft, ['award:5', 'award:7']);
$other = versionFacts(21, 2, 1, $published, ['award:5']);
$retiredOnly = versionFacts(31, 3, 1, $retired, ['award:5']);

foreach ([
    'the newest published version is the one offered' => [[$v1, $v2], 'award:5', [12]],
    'version order in the list doesn\'t matter' => [[$v2, $v1], 'award:5', [12]],
    'an older published version is not offered, even for a kind only it serves' => [[$v1, $v2], 'award:6', []],
    'a draft is never offered' => [[$v2, $v3Draft], 'award:7', []],
    'several templates can serve one kind' => [[$v2, $other], 'award:5', [12, 21]],
    'one version can serve several kinds' => [[$v1], 'award:6', [11]],
    'retiring the newest makes the next published current' => [[$v1, ['state' => $retired] + $v2], 'award:6', [11]],
    'a template with only a retired version offers nothing' => [[$retiredOnly], 'award:5', []],
    'a kind with no published template has nothing to list' => [[$v2], 'record_type:5', []],
] as $label => [$versions, $kind, $expected]) {
    $got = TemplateChoices::offered($versions, $kind);
    check($label, $got === $expected, json_encode($got));
}

// G-18.
function renderVersion(array $fields, array $inputs, string $rankScope): Version
{
    return Version::fromJson(json_encode($fields), json_encode($inputs), $rankScope);
}

function text(string $pattern): array
{
    return [
        'kind' => 'text', 'key' => 'line', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 50, 'pattern' => $pattern,
        'font' => 'tinos-regular', 'size' => 20, 'legible_size' => 0, 'leading' => 1.2, 'mode' => 'line',
        'align' => 'center', 'caps' => false, 'colour' => '#000000',
    ];
}

$art = ['kind' => 'rank_art', 'key' => 'insignia', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10];
$memberInput = ['key' => 'unit', 'label' => 'Unit', 'scope' => 'member', 'required' => false];
$grantInput = ['key' => 'unit', 'label' => 'Unit', 'scope' => 'grant', 'required' => false];

foreach ([
    'a PUC version with no fields' => [[], [], 'none', true],
    'a grant rank and a grant input' => [[text('{rank} {input:unit}')], [$grantInput], 'grant', true],
    'a name placeholder' => [[text('{name}')], [], 'none', false],
    'a member rank' => [[text('The regiment')], [], 'member', false],
    'a member input in a pattern' => [[text('{input:unit}')], [$memberInput], 'none', false],
    'a member input no pattern uses' => [[text('The regiment')], [$memberInput], 'none', false],
] as $label => [$fields, $inputs, $scope, $expected]) {
    check("regiment grant, $label", TemplateChoices::servesRegiment(renderVersion($fields, $inputs, $scope)) === $expected);
}

// G-20.
$secondArt = ['key' => 'collar'] + $art;
foreach ([
    'no rank-art field leaves every rank' => [[], [], [1, 2, 3]],
    'rank art limits the pick to ranks with art' => [[$art], ['insignia' => [3, 1]], [1, 3]],
    'a rank-art field with no art leaves nothing' => [[$art], [], []],
    'a rank must have art on every rank-art field' => [[$art, $secondArt], ['insignia' => [1, 2], 'collar' => [2, 3]], [2]],
] as $label => [$fields, $art_ranks, $expected]) {
    $got = TemplateChoices::pickableRanks(renderVersion($fields, [], 'grant'), [1, 2, 3], $art_ranks);
    check($label, $got === $expected, json_encode($got));
}

// G-24, G-25.
$slot = new SignatureSlot('sig', 0, 0, 100, 100, 1, 50, 'tinos-regular', 20, 0, 1.2, '#000000');
$sig = fn (int $id, int $billet, string $state): array => ['signature_id' => $id, 'billet_id' => $billet, 'state' => $state];
$active = $sig(1, 1, 'active');
$acting = $sig(2, 1, 'active');
$old = $sig(3, 1, 'retired');
$elsewhere = $sig(4, 2, 'active');
$elsewhereRetired = $sig(5, 2, 'retired');

foreach ([
    'one active signature preselects' => [[$active, $elsewhere], null, false, [1], 1],
    'a retired one doesn\'t count against the preselection' => [[$active, $old], null, false, [1], 1],
    'two active signatures leave the slot blank' => [[$active, $acting], null, false, [1, 2], null],
    'none to show leaves it blank' => [[$elsewhere], null, false, [], null],
    'showing retired adds them and leaves the slot blank' => [[$active, $old], null, true, [1, 3], null],
    'showing retired with one to show still leaves it blank' => [[$active], null, true, [1], null],
    'showing retired stays within the billet' => [[$active, $elsewhereRetired], null, true, [1], null],
    'a correction starts on the current signature' => [[$active, $acting], 2, false, [1, 2], 2],
    'a correction lists a retired current signature' => [[$active, $old], 3, false, [1, 3], 3],
    'a correction lists a current signature from another billet' => [[$active, $elsewhere], 4, false, [1, 4], 4],
    'a correction with retired shown still starts on the current one' => [[$active, $old], 1, true, [1, 3], 1],
    'a current signature that is gone leaves the slot blank' => [[$active], 9, false, [1], null],
] as $label => [$signatures, $currentId, $showRetired, $ids, $preselected]) {
    $got = SignatureChoices::for($slot, $signatures, $currentId, $showRetired);
    check(
        $label,
        array_column($got->shown, 'signature_id') === $ids && $got->preselected === $preselected,
        json_encode($got)
    );
}

// G-22, G-23.
foreach ([
    'an unused signature edits freely' => [false, ['printed_lines', 'ink_hash', 'billet_id'], false],
    'a frozen signature refuses new lines' => [true, ['printed_lines'], true],
    'a frozen signature refuses new ink' => [true, ['ink_hash', 'ink_width', 'ink_height'], true],
    'a frozen signature refuses another billet' => [true, ['billet_id'], true],
    'a frozen signature still retires and comes back' => [true, ['state'], false],
] as $label => [$frozen, $changed, $expected]) {
    check($label, SignatureRules::editRefused($frozen, $changed) === $expected);
}

foreach ([
    'one line' => [['Silas Castellan'], true],
    'four lines' => [['a', 'b', 'c', 'd'], true],
    'no lines' => [[], false],
    'five lines' => [['a', 'b', 'c', 'd', 'e'], false],
    'a blank line' => [['a', '   '], false],
    'something that is not a list' => [['a' => 'b'], false],
    'a line that is not a string' => [[1], false],
    'not an array at all' => ['a', false],
] as $label => [$lines, $expected]) {
    check("printed lines: $label", SignatureRules::linesValid($lines) === $expected);
}

// G-26.
$slotDefinition = fn (int $billet): string => json_encode([[
    'kind' => 'signature', 'key' => 'sig', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'billet_id' => $billet,
    'ink_height' => 50, 'font' => 'tinos-regular', 'size' => 20, 'legible_size' => 0, 'leading' => 1.2, 'colour' => '#000000',
]]);
check('a slot in any version holds its billet', SignatureRules::billetInSlots(2, ['[]', $slotDefinition(1), $slotDefinition(2)]));
check('a billet no slot names is free', !SignatureRules::billetInSlots(3, ['[]', $slotDefinition(1), $slotDefinition(2)]));

// The pay grade seed, by title.
$seeded = PayGradeSeed::rows([
    7 => 'Staff Sergeant', 8 => 'Private', 9 => 'Private First Class', 10 => 'Warrant Officer 1',
    11 => 'Recruit', 12 => 'Reservist', 13 => 'Tester', 14 => 'Grand Poobah', 15 => 'staff sergeant',
]);
check('a matching title seeds, whatever its id, and everything else stays blank', $seeded === [
    ['rank_id' => 7, 'pay_grade' => 'E-6', 'abbreviation' => 'SSG'],
    ['rank_id' => 8, 'pay_grade' => 'E-2', 'abbreviation' => 'PVT'],
    ['rank_id' => 9, 'pay_grade' => 'E-3', 'abbreviation' => 'PFC'],
    ['rank_id' => 10, 'pay_grade' => 'W-1', 'abbreviation' => 'WO1'],
], json_encode($seeded));

exit($failures === 0 ? 0 : 1);
