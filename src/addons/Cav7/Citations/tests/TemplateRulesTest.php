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
    foreach (['Render', 'Template', 'Grant'] as $directory) {
        $prefix = "Cav7\\Citations\\$directory\\";
        if (str_starts_with($class, $prefix)) {
            require __DIR__ . "/../$directory/" . substr($class, strlen($prefix)) . '.php';
        }
    }
});

use Cav7\Citations\Grant\Actor;
use Cav7\Citations\Grant\PreviewFit;
use Cav7\Citations\Render\FieldDefinitions;
use Cav7\Citations\Render\InputDefinition;
use Cav7\Citations\Render\Line;
use Cav7\Citations\Render\PlacedImage;
use Cav7\Citations\Render\RankArtField;
use Cav7\Citations\Render\SetField;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\TextField;
use Cav7\Citations\Render\Version;
use Cav7\Citations\Template\FieldForm;
use Cav7\Citations\Template\ManagerAccess;
use Cav7\Citations\Template\PayGradeSeed;
use Cav7\Citations\Template\PlateFit;
use Cav7\Citations\Template\SampleValues;
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
    'a retired version stays retired' => [$retired, $published, ['state', 'retire_date'], VersionLifecycle::BAD_TRANSITION],
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
check('the signatures page counts every slot naming a billet', SignatureRules::slotCounts(['[]', $slotDefinition(1), $slotDefinition(2), $slotDefinition(1)]) === [1 => 2, 2 => 1]);

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

// The template manager's access table: every action, whoever holds what.
$held = fn (string ...$permissions): Actor => new Actor(1, 'Sample', $permissions);
$none = $held();
$view = $held(Actor::VIEW);
$issue = $held(Actor::ISSUE);
$remove = $held(Actor::REMOVE_MEMBERS);
$regiment = $held(Actor::REGIMENT);
$edit = $held(Actor::EDIT_DRAFTS);
$publish = $held(Actor::PUBLISH);

$opens = [$view, $issue, $edit, $publish];
$opensNot = [$none, $remove, $regiment];
$previews = [$view, $edit, $publish];
$previewsNot = [$none, $issue, $remove, $regiment];
$edits = [$edit];
$editsNot = [$none, $view, $issue, $remove, $regiment, $publish];
$publishes = [$publish];
$publishesNot = [$none, $view, $issue, $remove, $regiment, $edit];

foreach ([
    [['templates/index', 'templates/paygrades', 'templates/legibility', 'versions/index', 'versions/plate', 'versions/art',
        'versions/field', 'versions/rankart', 'signatures/index', 'signatures/ink'], $opens, $opensNot],
    [['versions/preview'], $previews, $previewsNot],
    [['templates/add', 'templates/newdraft', 'versions/uploadplate', 'versions/settings', 'versions/savefield',
        'versions/removefield', 'versions/movefield', 'versions/saverankart', 'versions/delete'], $edits, $editsNot],
    [['templates/savepaygrades', 'versions/publish', 'versions/retire', 'signatures/add', 'signatures/edit',
        'signatures/delete', 'signatures/retire', 'signatures/reinstate', 'signatures/addbillet', 'signatures/editbillet',
        'signatures/deletebillet'], $publishes, $publishesNot],
    [['versions/movecitations'], [$issue, $regiment], [$none, $view, $remove, $edit, $publish]],
    [['templates/anythingelse', 'versions/anythingelse', 'signatures/anythingelse', 'signatures/publish', 'versions/add', 'versions/reinstate'], [], [$view, $issue, $remove, $regiment, $edit, $publish]],
] as [$actions, $allowed, $refused]) {
    foreach ($actions as $action) {
        [$page, $name] = explode('/', $action);
        foreach ($allowed as $i => $who) {
            check("manager access: $action opens for allowed #$i", ManagerAccess::allows($page, $name, $who));
        }
        foreach ($refused as $i => $who) {
            check("manager access: $action refuses #$i", !ManagerAccess::allows($page, $name, $who));
        }
    }
}

// The field form. What it makes has to survive the real definitions, for every kind.
$posted = [
    'key' => 'recipient', 'x' => '40', 'y' => '486', 'w' => '1195', 'h' => '46', 'pattern' => '{rank} {name}', 'font' => 'tinos-bold',
    'size' => '40', 'legible_size' => '26', 'leading' => '1.2', 'align' => 'center', 'caps' => '1', 'colour' => '#3A3A3A',
    'billet_id' => '4', 'ink_height' => '20',
];
[$fromForm] = FieldDefinitions::fields(json_encode([FieldForm::definition($posted + ['kind' => 'text'])]));
check('a text field comes through the form', $fromForm instanceof TextField && $fromForm->y === 486 && $fromForm->size === 40.0
    && $fromForm->caps && $fromForm->mode === 'line' && $fromForm->colour === '#3a3a3a');
[$wrapped] = FieldDefinitions::fields(json_encode([FieldForm::definition($posted + ['kind' => 'text', 'wrap' => '1'])]));
check('the wrap box sets wrap mode', $wrapped->mode === 'wrap');
[$fromForm] = FieldDefinitions::fields(json_encode([FieldForm::definition($posted + ['kind' => 'signature'])]));
check('a signature slot comes through the form with only its own attributes', $fromForm instanceof SignatureSlot && $fromForm->billet_id === 4 && $fromForm->ink_height === 20);
[$fromForm] = FieldDefinitions::fields(json_encode([FieldForm::definition($posted + ['kind' => 'rank_art'])]));
check('a rank art field is only its box', $fromForm instanceof RankArtField && $fromForm->w === 1195);
check('a new field starts on a definition that saves', count(FieldDefinitions::fields(json_encode([
    ['key' => 'x'] + FieldForm::definition(array_map('strval', FieldForm::blank(1275))),
]))) === 1);
$blankSlot = ['key' => 'x', 'kind' => 'signature'] + array_map('strval', FieldForm::blank(1275));
check('a new signature slot is valid as it opens', count(FieldDefinitions::fields(json_encode([FieldForm::definition($blankSlot)]))) === 1);
check('a new field fits a narrow plate', FieldForm::blank(511)['w'] === 431);

$a = ['key' => 'a'];
$b = ['key' => 'b'];
$c = ['key' => 'c'];
$keys = fn (array $definitions): string => implode('', array_column($definitions, 'key'));
foreach ([
    'a new field draws last' => [FieldForm::placed([$a, $b], null, $c), 'abc'],
    'an edit keeps its place' => [FieldForm::placed([$a, $b, $c], 'b', ['key' => 'z']), 'azc'],
    'a field removed goes, the rest keep order' => [FieldForm::without([$a, $b, $c], 'b'), 'ac'],
    'a step down swaps with the next' => [FieldForm::stepped([$a, $b, $c], 'a', 1), 'bac'],
    'a step up swaps with the last' => [FieldForm::stepped([$a, $b, $c], 'c', -1), 'acb'],
    'a step off the top changes nothing' => [FieldForm::stepped([$a, $b, $c], 'a', -1), 'abc'],
    'a step off the bottom changes nothing' => [FieldForm::stepped([$a, $b, $c], 'c', 1), 'abc'],
    'a step on a key not there changes nothing' => [FieldForm::stepped([$a, $b], 'z', 1), 'ab'],
] as $label => [$definitions, $expected]) {
    check("draw order: $label", $keys($definitions) === $expected && array_is_list($definitions), $keys($definitions));
}

$inputs = FieldForm::inputs([
    ['key' => 'unit', 'label' => 'Unit', 'scope' => 'member', 'required' => '1'],
    ['key' => '', 'label' => 'left blank', 'scope' => 'grant'],
    ['key' => 'class', 'label' => 'Class', 'scope' => 'grant'],
]);
check('input rows make input definitions, a blank key dropping out', count(FieldDefinitions::inputs(json_encode($inputs))) === 2
    && $inputs[0]['required'] === true && $inputs[1]['required'] === false);

// Sample values: blank stays blank, nothing posted means the defaults.
$unit = new InputDefinition('unit', 'Unit', 'member', true);
$sample = SampleValues::from([], [$unit], '2026-11-06', 12);
check('the sample starts on the defaults', $sample->name === SampleValues::NAME && $sample->rankId === 12 && $sample->date === '2026-11-06'
    && $sample->citationText === SampleValues::TEXT && $sample->inputs === ['unit' => SampleValues::INPUT]);
$sample = SampleValues::from(
    ['name' => '', 'rank_id' => '7', 'date' => '', 'text' => 'Typed.', 'inputs' => ['unit' => 'B Troop', 'old' => 'x'], 'signatures' => ['sig' => '9']],
    [$unit],
    '2026-11-06',
    12
);
check('what S1 typed wins, blanks included', $sample->name === '' && $sample->rankId === 7 && $sample->date === '' && $sample->citationText === 'Typed.'
    && $sample->inputs === ['unit' => 'B Troop'] && $sample->signatures === ['sig' => 9]);
check('the long sample replaces what is typed', SampleValues::from(['text' => 'Typed.', 'long' => '1'], [], 'x', 1)->citationText === SampleValues::LONG_TEXT);

// The preview's report: every field, drawn or not, and why not.
$text = fn (string $key, string $pattern): TextField => new TextField($key, 0, 0, 100, 100, $pattern, 'tinos-regular', 30.0, 23.0, 1.2, 'line', 'left', false, '#000000');
$slot = new SignatureSlot('sig', 0, 0, 100, 100, 1, 50, 'tinos-regular', 19.0, 14.0, 1.2, '#000000');
$artField = new RankArtField('art', 0, 0, 100, 100);
$design = new Version([$text('good', '{name}'), $text('small', '{name}'), $text('bare', '{input:unit}'), $text('broken', '[{name}'), $slot, $artField], [], 'member');

check('a draft\'s broken pattern comes out of what gets set', array_column(PlateFit::settable($design)->fields, 'key') === ['good', 'small', 'bare', 'sig', 'art']);

$line = new Line('Sample', 0.0, 0.0, 50.0, 0.0);
$rows = PreviewFit::rows($design, [
    'good' => new SetField('good', 30.0, false, [$line]),
    'small' => new SetField('small', 18.5, true, [$line]),
    'bare' => new SetField('bare', 0.0, false, []),
    'art' => new SetField('art', 0.0, false, null),
]);
$report = array_column(PlateFit::report($design, $rows), null, 'key');
check('a field that drew reports its size and nothing else', $report['good']['size'] === 30.0 && !$report['good']['below'] && $report['good']['undrawn'] === '');
check('a field under its threshold says so', $report['small']['below'] && $report['small']['size'] === 18.5 && $report['small']['legible'] === 23.0);
check('a text field that came out blank says so', $report['bare']['undrawn'] === PlateFit::NOTHING_TO_PRINT);
check('a broken pattern says where', $report['broken']['undrawn'] === PlateFit::BAD_PATTERN && $report['broken']['error'] === ['message' => 'Unclosed [', 'at' => 1]);
check('a slot with no signature says so', $report['sig']['undrawn'] === PlateFit::NO_SIGNATURE);
check('rank art with none for the rank says so', $report['art']['undrawn'] === PlateFit::NO_ART);
$rows['sig'] = new SetField('sig', 19.0, false, [$line], ink: new PlacedImage('a', 0, 0, 10, 10));
$rows['art'] = new SetField('art', 0.0, false, null, art: new PlacedImage('b', 0, 0, 10, 10));
$report = array_column(PlateFit::report($design, $rows), null, 'key');
check('a slot and rank art that drew say nothing', $report['sig']['undrawn'] === '' && $report['art']['undrawn'] === '');
check('a pattern error counts in characters, from 1', PlateFit::patternError('Né {nope}') === ['message' => 'Unknown placeholder {nope}', 'at' => 4]);
check('a good pattern has no error', PlateFit::patternError('{rank}[ ({pay_grade})]') === null);

// G-48: the threshold the list shows comes off the grant's version.
$entries = PlateFit::withThresholds(
    [['template_version_id' => '5', 'field_key' => 'small'], ['template_version_id' => '5', 'field_key' => 'sig'], ['template_version_id' => '6', 'field_key' => 'small']],
    [5 => $design]
);
check('the list reads each field\'s threshold from its version', array_column($entries, 'legible') === [23.0, 14.0, 0.0], json_encode($entries));

exit($failures === 0 ? 0 : 1);
