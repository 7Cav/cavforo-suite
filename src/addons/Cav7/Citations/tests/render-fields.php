<?php

/**
 * Field and input definitions, which fields a change remakes, and the pattern
 * checks publish adds.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/render-fields.php
 */

namespace Cav7\Citations\Tests;

spl_autoload_register(function (string $class): void {
    $prefix = 'Cav7\\Citations\\Render\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../Render/' . substr($class, strlen($prefix)) . '.php';
    }
});

use Cav7\Citations\Render\FieldDefinitions;
use Cav7\Citations\Render\FieldDependencies;
use Cav7\Citations\Render\RankArtField;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\TextField;
use Cav7\Citations\Render\Version;
use Cav7\Citations\Render\VersionRules;

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

function textDefinition(array $overrides = []): array
{
    return $overrides + [
        'kind' => 'text', 'key' => 'recipient', 'x' => 40, 'y' => 486, 'w' => 1195, 'h' => 46,
        'pattern' => '{rank} {name}', 'font' => 'tinos-bold', 'size' => 40, 'legible_size' => 26,
        'leading' => 1.2, 'mode' => 'line', 'align' => 'center', 'caps' => true, 'colour' => '#3a3a3a',
    ];
}

function signatureDefinition(array $overrides = []): array
{
    return $overrides + [
        'kind' => 'signature', 'key' => 'sig_commander', 'x' => 473, 'y' => 1300, 'w' => 330, 'h' => 180,
        'billet_id' => 1, 'ink_height' => 90, 'font' => 'tinos-regular', 'size' => 19, 'legible_size' => 14,
        'leading' => 1.2, 'colour' => '#16161a',
    ];
}

function artDefinition(array $overrides = []): array
{
    return $overrides + ['kind' => 'rank_art', 'key' => 'insignia', 'x' => 560, 'y' => 300, 'w' => 160, 'h' => 160];
}

function version(array $definitions, array $inputs = [], string $rankScope = 'member'): Version
{
    return Version::fromJson(json_encode($definitions), json_encode($inputs), $rankScope);
}

function keys(array $fields): array
{
    return array_map(fn (object $field): string => $field->key, $fields);
}

$fields = FieldDefinitions::fields(json_encode([
    textDefinition(),
    textDefinition(['key' => 'citation_body', 'pattern' => '{citation_text}', 'mode' => 'wrap', 'align' => 'justify', 'size' => 30.25]),
    signatureDefinition(),
    artDefinition(),
]));
check('each kind decodes to its class', $fields[0] instanceof TextField
    && $fields[1] instanceof TextField
    && $fields[2] instanceof SignatureSlot
    && $fields[3] instanceof RankArtField);
check('order is kept, since it is draw order', keys($fields) === ['recipient', 'citation_body', 'sig_commander', 'insignia']);
check('a size on the grid survives decoding', $fields[1]->size === 30.25);
check('a whole-number size in JSON is a float', $fields[0]->size === 40.0);

$printed = $fields[2]->printedBlock();
check('printed lines take the box below the ink', [$printed->y, $printed->h] === [1390, 90]);

// The factory throws on anything unknown.
foreach ([
    'an unknown kind' => [['kind' => 'medal'] + textDefinition()],
    'no kind' => [array_diff_key(textDefinition(), ['kind' => 1])],
    'an unknown attribute' => [textDefinition(['flourish' => true])],
    'a missing attribute' => [array_diff_key(textDefinition(), ['colour' => 1])],
    'a box as a string' => [textDefinition(['x' => '40'])],
    'a fractional box' => [textDefinition(['x' => 40.5])],
    'caps as a number' => [textDefinition(['caps' => 1])],
    'a bad key' => [textDefinition(['key' => 'Recipient'])],
    'a key too long' => [textDefinition(['key' => str_repeat('a', 51)])],
    'a duplicate key' => [textDefinition(), textDefinition()],
    'a zero-width box' => [textDefinition(['w' => 0])],
    'a size off the grid' => [textDefinition(['size' => 30.1])],
    'a size below the last resort' => [textDefinition(['size' => 0.5])],
    'a negative threshold' => [textDefinition(['legible_size' => -1])],
    'an unknown mode' => [textDefinition(['mode' => 'fit'])],
    'an unknown align' => [textDefinition(['align' => 'middle'])],
    'justify without wrap' => [textDefinition(['align' => 'justify'])],
    'a bad colour' => [textDefinition(['colour' => 'red'])],
    'ink taller than the slot' => [signatureDefinition(['ink_height' => 180])],
    'a definition that is not an object' => [[1, 2, 3]],
] as $label => $definitions) {
    try {
        FieldDefinitions::fields(json_encode($definitions));
        check("$label is refused", false, 'decoded');
    } catch (\InvalidArgumentException) {
        check("$label is refused", true);
    }
}

try {
    FieldDefinitions::fields('{"kind":"text"}');
    check('definitions that are not a list are refused', false);
} catch (\InvalidArgumentException) {
    check('definitions that are not a list are refused', true);
}

check(
    'a font dropped from the catalog still decodes, since a published version pins it',
    FieldDefinitions::fields(json_encode([textDefinition(['font' => 'long-gone'])]))[0]->font === 'long-gone'
);
check('a PUC version has no fields', version([])->fields === []);

foreach ([
    'an unknown scope' => ['key' => 'a', 'label' => 'A', 'scope' => 'regiment', 'required' => true],
    'a bad key' => ['key' => 'A', 'label' => 'A', 'scope' => 'grant', 'required' => true],
    'a missing attribute' => ['key' => 'a', 'label' => 'A', 'scope' => 'grant'],
] as $label => $definition) {
    try {
        FieldDefinitions::inputs(json_encode([$definition]));
        check("an input with $label is refused", false);
    } catch (\InvalidArgumentException) {
        check("an input with $label is refused", true);
    }
}

try {
    $input = ['key' => 'a', 'label' => 'A', 'scope' => 'grant', 'required' => true];
    FieldDefinitions::inputs(json_encode([$input, $input]));
    check('a duplicate input key is refused', false);
} catch (\InvalidArgumentException) {
    check('a duplicate input key is refused', true);
}

// Which row a field lives on.
$inputs = [
    ['key' => 'class_number', 'label' => 'Class number', 'scope' => 'grant', 'required' => true],
    ['key' => 'unit_line', 'label' => 'Unit line', 'scope' => 'member', 'required' => false],
];

foreach ([
    ['{name}', 'none', true, ['name']],
    ['{rank}', 'member', true, ['rank']],
    ['{pay_grade} {rank_abbr}', 'member', true, ['rank']],
    ['{rank}', 'grant', false, ['rank']],
    ['{input:unit_line}', 'none', true, ['input:unit_line']],
    ['{input:class_number}', 'none', false, ['input:class_number']],
    ['{day_ordinal} of {month} {year} {day}', 'member', false, ['date']],
    ['{citation_text}', 'member', false, ['citation_text']],
    ['Literal prose', 'member', false, []],
    ['[({pay_grade})] {citation_text}', 'grant', false, ['rank', 'citation_text']],
    ['[{name}] {citation_text}', 'grant', true, ['name', 'citation_text']],
] as [$pattern, $scope, $member, $uses]) {
    $version = version([textDefinition(['pattern' => $pattern])], $inputs, $scope);
    $got = FieldDependencies::of($version->fields[0], $version);
    check(
        "`$pattern` with rank scope $scope is " . ($member ? 'a member row' : 'shared'),
        $got->member === $member && $got->uses === $uses,
        json_encode($got)
    );
}

$slotVersion = version([signatureDefinition(), artDefinition()], [], 'member');
$slot = FieldDependencies::of($slotVersion->fields[0], $slotVersion);
check('a signature slot is always shared', $slot->member === false && $slot->uses === ['signature:sig_commander']);
check('rank art with a member rank is a member row', FieldDependencies::of($slotVersion->fields[1], $slotVersion)->member);
$grantRank = version([artDefinition()], [], 'grant');
check('rank art with a grant rank is shared', !FieldDependencies::of($grantRank->fields[0], $grantRank)->member);

// What a change remakes.
$bsm = version([
    textDefinition(['key' => 'award', 'pattern' => 'BRONZE STAR MEDAL']),
    textDefinition(['key' => 'recipient', 'pattern' => '{rank} {name}']),
    textDefinition(['key' => 'citation_body', 'pattern' => '{citation_text}', 'mode' => 'wrap']),
    textDefinition(['key' => 'date_line', 'pattern' => 'ON THIS {day_ordinal} DAY OF {month}, {year}']),
    textDefinition(['key' => 'note', 'pattern' => '{name}, {month} {year}']),
    textDefinition(['key' => 'unit', 'pattern' => '[{input:unit_line}]']),
    signatureDefinition(),
    artDefinition(),
], $inputs, 'member');

foreach ([
    'a name fix' => [['name'], ['recipient', 'note']],
    'a citation text fix' => [['citation_text'], ['citation_body']],
    'a date fix, shared value in a member field included' => [['date'], ['date_line', 'note']],
    'a rank fix' => [['rank'], ['recipient', 'insignia']],
    'a member input fix' => [['input:unit_line'], ['unit']],
    'a signature swap' => [['signature:sig_commander'], ['sig_commander']],
    'two changes at once' => [['name', 'citation_text'], ['recipient', 'citation_body', 'note']],
    'nothing changed' => [[], []],
] as $label => [$changed, $expected]) {
    $got = keys(FieldDependencies::remadeBy($bsm, $changed));
    check("$label remakes the right fields", $got === $expected, json_encode($got));
}

check(
    'a member addition sets only the member fields',
    keys(FieldDependencies::memberFields($bsm)) === ['recipient', 'note', 'unit', 'insignia']
);
check(
    'issue sets the shared fields once',
    keys(FieldDependencies::sharedFields($bsm)) === ['award', 'citation_body', 'date_line', 'sig_commander']
);
$regiment = version([textDefinition(['pattern' => 'THE REGIMENT']), signatureDefinition()], [], 'none');
check('a version fit for a regiment grant has no member fields', FieldDependencies::memberFields($regiment) === []);
check(
    'generic is a version with no citation text and nothing per member, so a named class certificate is not',
    FieldDependencies::generic($regiment) && FieldDependencies::generic(version([]))
        && !FieldDependencies::generic($bsm)
        && !FieldDependencies::generic(version([textDefinition(['pattern' => 'PRIVATE {name}']), signatureDefinition()], [], 'none'))
);

// G-33, G-34.
foreach ([
    'a blank required input' => [['class_number' => '  '], ['class_number']],
    'an absent required input' => [[], ['class_number']],
    'a filled required input' => [['class_number' => '005'], []],
    'an unused value left on the grant' => [['class_number' => '005', 'old_key' => 'kept'], []],
] as $label => [$values, $expected]) {
    $got = version([], $inputs, 'none')->missingInputs($values);
    check("missing inputs with $label", $got === $expected, json_encode($got));
}

check('a version names each font it sets, and rank art sets none', version([
    textDefinition(), textDefinition(['key' => 'b', 'font' => 'tinos-regular']), signatureDefinition(), artDefinition(),
])->fontKeys() === ['tinos-bold', 'tinos-regular']);
check('a PUC version pins no fonts', version([])->fontKeys() === []);

// The publish checks.
foreach ([
    'a declared input' => [[textDefinition(['pattern' => '{input:unit_line}'])], 'member', []],
    'an undeclared input' => [[textDefinition(['pattern' => '{input:nope}'])], 'member', [VersionRules::UNDECLARED_INPUT]],
    'citation text in a wrap field' => [[textDefinition(['pattern' => '{citation_text}', 'mode' => 'wrap'])], 'member', []],
    'citation text in a line field' => [[textDefinition(['pattern' => '{citation_text}'])], 'member', [VersionRules::CITATION_TEXT_OUTSIDE_WRAP]],
    'rank with a rank scope' => [[textDefinition(['pattern' => '{rank}'])], 'grant', []],
    'rank with no rank scope' => [[textDefinition(['pattern' => '[{pay_grade}]'])], 'none', [VersionRules::RANK_WITHOUT_SCOPE]],
    'a date in a narrow font' => [[textDefinition(['pattern' => 'Given {day_ordinal} {month}', 'font' => 'unifraktur-maguntia'])], 'none', []],
    'a name in a narrow font' => [[textDefinition(['pattern' => '{name}', 'font' => 'unifraktur-maguntia'])], 'none', [VersionRules::NARROW_FONT_VALUE]],
    'a signature in a narrow font' => [[signatureDefinition(['font' => 'unifraktur-maguntia'])], 'none', [VersionRules::NARROW_FONT_VALUE]],
    'a pattern that does not parse' => [[textDefinition(['pattern' => '{nope}'])], 'none', [VersionRules::DOES_NOT_PARSE]],
] as $label => [$definitions, $scope, $expected]) {
    $problems = VersionRules::patternProblems(version($definitions, $inputs, $scope));
    check("publish check: $label", array_column($problems, 'rule') === $expected, json_encode($problems));
}
check(
    'a problem names its field',
    VersionRules::patternProblems(version([textDefinition(['pattern' => '{nope}'])], [], 'none'))[0]['field'] === 'recipient'
);

// G-5, G-6: the rest of what publish refuses. A plate 1275 by 1650, one kind, billet 1.
foreach ([
    'a clean version' => [[textDefinition(), signatureDefinition(), artDefinition()], 'member', [], []],
    'a PUC version with no fields' => [[], 'none', [], []],
    'a box past the right edge' => [[textDefinition(['x' => 100, 'w' => 1176])], 'member', [], [['recipient', VersionRules::BOX_OFF_PLATE]]],
    'a box touching the right edge' => [[textDefinition(['x' => 100, 'w' => 1175])], 'member', [], []],
    'a box past the bottom' => [[artDefinition(['y' => 1500, 'h' => 151])], 'member', [], [['insignia', VersionRules::BOX_OFF_PLATE]]],
    'a slot past the bottom' => [[signatureDefinition(['y' => 1500, 'h' => 151])], 'member', [], [['sig_commander', VersionRules::BOX_OFF_PLATE]]],
    'a slot naming a billet that is gone' => [[signatureDefinition(['billet_id' => 9])], 'member', [], [['sig_commander', VersionRules::NO_BILLET]]],
    'a slot with no billet picked' => [[signatureDefinition(['billet_id' => 0])], 'member', [], [['sig_commander', VersionRules::NO_BILLET]]],
    'a text field with no catalog font' => [[textDefinition(['font' => 'comic-sans'])], 'member', [], [['recipient', VersionRules::NO_CATALOG_FONT]]],
    'a slot with no catalog font' => [[signatureDefinition(['font' => ''])], 'member', [], [['sig_commander', VersionRules::NO_CATALOG_FONT]]],
    'rank art with rank scope none' => [[artDefinition()], 'none', [], [['insignia', VersionRules::RANK_ART_WITHOUT_SCOPE]]],
    'an input moved to another scope' => [[], 'none', ['unit_line' => 'grant'], [['unit_line', VersionRules::INPUT_SCOPE_CHANGED]]],
    'an input keeping its scope' => [[], 'none', ['class_number' => 'grant', 'unit_line' => 'member'], []],
    'an earlier input this version dropped' => [[], 'none', ['retired_key' => 'grant'], []],
    'a section 2 check alongside the rest' => [
        [textDefinition(['pattern' => '{nope}', 'font' => 'gone'])], 'none', [],
        [['recipient', VersionRules::NO_CATALOG_FONT], ['recipient', VersionRules::DOES_NOT_PARSE]],
    ],
] as $label => [$definitions, $scope, $earlier, $expected]) {
    $problems = VersionRules::publishProblems(version($definitions, $inputs, $scope), 1275, 1650, 1, [1], $earlier);
    $got = array_map(fn (array $problem): array => [$problem['field'], $problem['rule']], $problems);
    check("publish: $label", $got === $expected, json_encode($problems));
}

$bare = VersionRules::publishProblems(version([textDefinition(['x' => 5000])], [], 'member'), 0, 0, 0, [], []);
check(
    'no plate and no kind are the version\'s own, and no plate means no box is measured',
    $bare === [['field' => '', 'rule' => VersionRules::NO_PLATE], ['field' => '', 'rule' => VersionRules::NO_KIND]],
    json_encode($bare)
);
check(
    'every refusal shows at once',
    count(VersionRules::publishProblems(version([artDefinition(), signatureDefinition(['font' => 'x', 'billet_id' => 9])], [], 'none'), 0, 0, 0, [1], [])) === 5
);

exit($failures === 0 ? 0 : 1);
