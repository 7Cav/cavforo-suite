<?php

/**
 * Fit, line breaking, placement, the legibility flag and the stored row shape,
 * against a fixture that behaves as Imagick was measured to: widths rounded up,
 * kerning the advance table can't see, interword spacing replacing the space.
 *
 * Self-contained: no XenForo, no framework, no Imagick. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/render-typesetter.php
 */

namespace Cav7\Citations\Tests;

spl_autoload_register(function (string $class): void {
    $prefix = 'Cav7\\Citations\\Render\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../Render/' . substr($class, strlen($prefix)) . '.php';
    }
});

use Cav7\Citations\Render\FontMetrics;
use Cav7\Citations\Render\ImageFile;
use Cav7\Citations\Render\Line;
use Cav7\Citations\Render\PinnedSignature;
use Cav7\Citations\Render\PlacedImage;
use Cav7\Citations\Render\RankArtField;
use Cav7\Citations\Render\RankSnapshot;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Render\SetField;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\TextField;
use Cav7\Citations\Render\Typesetter;
use Cav7\Citations\Render\Values;

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

/** Letters 0.5em, `i` 0.25em, `W` 1em, a space 0.25em. `AV` kerns by -0.2em. Ascent 0.8, descent -0.2. */
final class FixtureMetrics implements FontMetrics
{
    public int $widthCalls = 0;

    public function advance(string $fontPath, string $char): float
    {
        return ['i' => 0.25, 'W' => 1.0, ' ' => 0.25][$char] ?? 0.5;
    }

    public function width(string $fontPath, float $size, string $text, float $interword = 0): float
    {
        $this->widthCalls++;
        $spaces = substr_count($text, ' ');
        $ems = -0.2 * substr_count($text, 'AV');
        foreach (mb_str_split($text) as $char) {
            $ems += $this->advance($fontPath, $char);
        }
        $natural = $ems * $size;
        $set = $interword != 0 ? $natural + $spaces * ($interword - 0.25 * $size) : $natural;
        return ceil(round($set, 6));
    }

    public function vertical(string $fontPath): array
    {
        return [0.8, -0.2];
    }
}

function field(array $overrides = []): TextField
{
    $values = $overrides + [
        'key' => 'f', 'x' => 100, 'y' => 200, 'w' => 400, 'h' => 60, 'pattern' => '{citation_text}',
        'font' => 'serif', 'size' => 40.0, 'legible_size' => 0.0, 'leading' => 1.5, 'mode' => 'line',
        'align' => 'center', 'caps' => false, 'colour' => '#000000',
    ];
    return new TextField(...$values);
}

function setText(TextField $field, string $text): SetField
{
    return (new Typesetter(new FixtureMetrics()))->set(
        [$field],
        ['serif' => '/fonts/serif.ttf'],
        new Values('', null, '2026-03-21', $text)
    )[$field->key];
}

function linesOf(SetField $set): array
{
    return array_map(fn (Line $line): string => $line->t, $set->lines);
}

// line mode.
foreach ([
    'a line that fits stays at the cap' => [
        field(), str_repeat('a', 4), 40.0, 1,
    ],
    'a long line shrinks to the largest size on the grid' => [
        field(), str_repeat('a', 30), 26.5, 1,
    ],
    'a size whose rounded-up width still fits is not skipped' => [
        field(['w' => 232, 'size' => 40.25]), str_repeat('a', 14) . 'i', 32.0, 1,
    ],
    'hard lines shrink for height' => [
        field(), "aa\naa", 24.0, 2,
    ],
    'the widest hard line decides' => [
        field(['h' => 400]), "aa\n" . str_repeat('a', 40), 20.0, 2,
    ],
] as $label => [$field, $text, $size, $lineCount]) {
    $set = setText($field, $text);
    check($label, $set->size === $size && count($set->lines) === $lineCount, json_encode($set->row()));
}

$metrics = new FixtureMetrics();
(new Typesetter($metrics))->set(
    [field()],
    ['serif' => 'x'],
    new Values('', null, '2026-03-21', str_repeat('a', 30))
);
check('a line shrinks in a handful of measures, not a walk down the grid', $metrics->widthCalls <= 4, (string) $metrics->widthCalls);

$hopeless = setText(field(['legible_size' => 12.0]), str_repeat('W', 1000));
check(
    'a line that fits at no size stores at the last resort and overflows, flagged',
    $hopeless->size === 1.0 && $hopeless->belowLegibility && $hopeless->lines[0]->w === 1000.0
);

// Placement.
foreach ([
    'left' => 100.0,
    'center' => 260.0,
    'right' => 420.0,
] as $align => $x) {
    $set = setText(field(['align' => $align]), 'aaaa');
    check("$align sets x at $x", $set->lines[0]->x === $x, (string) $set->lines[0]->x);
}

$single = setText(field(), 'aaaa');
check('one line centres in its box, baseline at ascent', $single->lines[0]->y === 242.0, (string) $single->lines[0]->y);
check('a line keeps the measured width', $single->lines[0]->w === 80.0);
check('a line with no justification has no interword', $single->lines[0]->iw === 0.0);

$pair = setText(field(['h' => 200]), "aa\naa");
check(
    'two lines sit one leading apart, centred as a block',
    [$pair->lines[0]->y, $pair->lines[1]->y] === [282.0, 342.0],
    json_encode($pair->row())
);

check(
    'positions round to two places',
    setText(field(['size' => 33.75]), 'aaa')->lines[0]->y === 240.13
);

// wrap mode.
$wrapField = field(['w' => 300, 'h' => 200, 'mode' => 'wrap', 'align' => 'left', 'size' => 40.0]);
$words = implode(' ', array_fill(0, 10, 'aaaa'));
$wrapped = setText($wrapField, $words);
check('wrap takes the largest size whose lines fit the height', $wrapped->size === 36.25, (string) $wrapped->size);
check('greedy breaking fills each line', linesOf($wrapped) === [
    'aaaa aaaa aaaa', 'aaaa aaaa aaaa', 'aaaa aaaa aaaa', 'aaaa',
], json_encode(linesOf($wrapped)));

$short = setText($wrapField, 'aaaa aaaa');
check('short text stays at the cap', $short->size === 40.0 && linesOf($short) === ['aaaa aaaa']);

$paragraphs = setText(field(['w' => 300, 'h' => 400, 'mode' => 'wrap', 'align' => 'left']), "aaaa\naaaa");
check('a newline is a paragraph break even when the words would share a line', linesOf($paragraphs) === ['aaaa', 'aaaa']);

$nbsp = setText(field(['w' => 200, 'h' => 400, 'mode' => 'wrap', 'align' => 'left', 'size' => 20.0]), "aaaaaaaa aaaaaa\u{A0}aaaaaa");
check('U+00A0 is not a break', linesOf($nbsp) === ['aaaaaaaa', "aaaaaa\u{A0}aaaaaa"], json_encode(linesOf($nbsp)));

$wideWord = setText(field(['w' => 100, 'h' => 400, 'mode' => 'wrap', 'align' => 'left', 'legible_size' => 12.0]), 'aa ' . str_repeat('a', 20));
check('one word wider than the box forces the size down', $wideWord->size === 10.0, (string) $wideWord->size);
check('the size that word forces is flagged', $wideWord->belowLegibility);

$hairOver = setText(field(['w' => 100, 'h' => 400, 'mode' => 'wrap', 'align' => 'left']), str_repeat('a', 9) . 'i');
check('a word past the box by less than the tolerance still forces the size down', $hairOver->size === 21.0, (string) $hairOver->size);

$noFit = setText(field(['w' => 100, 'h' => 400, 'mode' => 'wrap', 'legible_size' => 12.0]), str_repeat('a', 400));
check('a word too wide at any size stores at the last resort, flagged', $noFit->size === 1.0 && $noFit->belowLegibility);
check('text that overflows at the last resort still saves its lines', linesOf($noFit) === [str_repeat('a', 400)]);

// The table's kerning ratio comes from the AV-heavy paragraph, so it runs short on the plain one.
$kerned = setText(
    field(['w' => 330, 'h' => 900, 'mode' => 'wrap', 'align' => 'left', 'size' => 44.0]),
    "AVAV AVAV AVAV AVAV AVAV\naaaaaaaa aaaaaaaa"
);
check('a line the table passes but the exact measure refuses moves to a lower size', $kerned->size === 40.0, (string) $kerned->size);
check('the refused line still fits on one line at the lower size', $kerned->lines[count($kerned->lines) - 1]->t === 'aaaaaaaa aaaaaaaa');

// Justification.
$justified = setText(
    field(['w' => 300, 'h' => 400, 'mode' => 'wrap', 'align' => 'justify']),
    "aaaa aaaa aaaa aaaa aaaa\naaaa aaaa aaaa aaaaaaa"
);
$line = $justified->lines[0];
check('a justified line starts at the box', $line->x === 100.0, (string) $line->x);
check('a justified line stretches its gaps to the box edge', $line->iw === 30.0, (string) $line->iw);
check('a justified line stores the width measured with its spacing', $line->w === 300.0, (string) $line->w);
$last = $justified->lines[1];
check('a paragraph\'s last line centres', $last->t === 'aaaa aaaa' && $last->x === 165.0 && $last->iw === 0.0, json_encode($last));

$oneWord = setText(field(['w' => 300, 'h' => 400, 'mode' => 'wrap', 'align' => 'justify']), str_repeat('a', 12) . ' ' . str_repeat('a', 12));
check('a line with no space centres', $oneWord->lines[0]->x === 130.0 && $oneWord->lines[0]->iw === 0.0, json_encode($oneWord->lines[0]));

// AV pairs drag the table's ratio down, so the first aim misses and only the correction lands it.
$corrected = setText(
    field(['w' => 300, 'h' => 900, 'mode' => 'wrap', 'align' => 'justify', 'size' => 40.0]),
    'aaaa aaaa aaaa aaaa AVAVAVAVAVAVAV'
);
check(
    'the correction brings a mis-aimed line to the box edge',
    $corrected->lines[0]->iw === 29.625 && $corrected->lines[0]->w === 300.0,
    json_encode($corrected->lines[0])
);

// Legibility.
foreach ([
    'at the threshold is not below it' => [26.5, false],
    'one step over the fitted size is below it' => [26.75, true],
    'a threshold of 0 means none' => [0.0, false],
] as $label => [$threshold, $below]) {
    $set = setText(field(['legible_size' => $threshold]), str_repeat('a', 30));
    check($label, $set->size === 26.5 && $set->belowLegibility === $below);
}

$blank = setText(field(['legible_size' => 26.0, 'pattern' => '[{citation_text}]']), '   ');
check(
    'an empty field stores no lines, size 0 and no flag',
    $blank->lines === [] && $blank->size === 0.0 && $blank->belowLegibility === false
);

// Signature slots and images.
$slot = new SignatureSlot('sig', 473, 1300, 330, 180, 1, 90, 'serif', 19.0, 14.0, 1.2, '#16161a');
$typesetter = new Typesetter(new FixtureMetrics());
$signed = fn (ImageFile $ink, array $printed = ['Silas Castellan', 'Colonel']) => $typesetter->set(
    [$slot],
    ['serif' => 'x'],
    new Values('', null, '2026-03-21', '', [], ['sig' => new PinnedSignature($printed, $ink)])
)['sig'];

$small = $signed(new ImageFile('ink', 236, 78));
check('ink smaller than its area is not scaled up', [$small->ink->w, $small->ink->h] === [236, 78]);
check('ink centres horizontally and sits on the bottom of its area', [$small->ink->x, $small->ink->y] === [520, 1312], json_encode($small->ink));

$large = $signed(new ImageFile('ink', 660, 90));
check('wide ink scales down to the box, keeping its aspect', [$large->ink->w, $large->ink->h, $large->ink->x, $large->ink->y] === [330, 45, 473, 1345], json_encode($large->ink));

$tall = $signed(new ImageFile('ink', 100, 180));
check('tall ink scales down to its area, not the slot', [$tall->ink->w, $tall->ink->h, $tall->ink->y] === [50, 90, 1300], json_encode($tall->ink));

check('printed lines keep their casing and order', linesOf($small) === ['Silas Castellan', 'Colonel']);
check('printed lines share one size', $small->size === 19.0);
check(
    'printed lines centre in the box below the ink',
    [$small->lines[0]->x, $small->lines[0]->y] === [571.5, 1429.3],
    json_encode($small->lines[0])
);

$longPrinted = $signed(new ImageFile('ink', 200, 50), [str_repeat('a', 60)]);
check('a printed line shrinks in its block and flags', $longPrinted->size === 11.0 && $longPrinted->belowLegibility, json_encode($longPrinted->row()));

try {
    $typesetter->set([$slot], ['serif' => 'x'], new Values('', null, '2026-03-21', ''));
    check('a slot with no pinned signature fails', false);
} catch (RenderFailed) {
    check('a slot with no pinned signature fails', true);
}

try {
    $typesetter->set([field()], [], new Values('', null, '2026-03-21', 'a'));
    check('a field with no font path fails', false);
} catch (RenderFailed) {
    check('a field with no font path fails', true);
}

$art = new RankArtField('insignia', 560, 300, 160, 160);
$rankValues = fn (array $art) => new Values('', new RankSnapshot('Sergeant', 'E-5', 'SGT'), '2026-03-21', '', [], [], $art);

$placed = $typesetter->set([$art], [], $rankValues(['insignia' => new ImageFile('art', 136, 140)]))['insignia'];
check('rank art centres in its box', [$placed->art->x, $placed->art->y, $placed->art->w, $placed->art->h] === [572, 310, 136, 140], json_encode($placed->art));

$scaled = $typesetter->set([$art], [], $rankValues(['insignia' => new ImageFile('art', 320, 160)]))['insignia'];
check('rank art scales down to its box', [$scaled->art->x, $scaled->art->y, $scaled->art->w, $scaled->art->h] === [560, 340, 160, 80], json_encode($scaled->art));

$noArt = $typesetter->set([$art], [], $rankValues([]))['insignia'];
check('a rank with no art stores no art', $noArt->art === null && $noArt->lines === null && $noArt->size === 0.0);

// The stored row shape.
foreach ([
    'text' => [
        new SetField('recipient', 40.0, false, [new Line('For a single act of courage', 139.45, 607.2, 996.0, 9.842)]),
        ['field_key' => 'recipient', 'size' => 40.0, 'below_legibility' => 0,
            'set_data' => '{"lines":[{"t":"For a single act of courage","x":139.45,"y":607.2,"w":996,"iw":9.842}]}'],
    ],
    'a blank text field' => [
        new SetField('blank', 0.0, false, []),
        ['field_key' => 'blank', 'size' => 0.0, 'below_legibility' => 0, 'set_data' => '{"lines":[]}'],
    ],
    'a signature' => [
        new SetField('sig', 19.0, true, [new Line('Silas Castellan', 560.5, 1412.6, 155.0, 0.0)], ink: new PlacedImage('abc', 520, 1312, 236, 78)),
        ['field_key' => 'sig', 'size' => 19.0, 'below_legibility' => 1,
            'set_data' => '{"ink":{"hash":"abc","x":520,"y":1312,"w":236,"h":78},"lines":[{"t":"Silas Castellan","x":560.5,"y":1412.6,"w":155,"iw":0}]}'],
    ],
    'rank art' => [
        new SetField('insignia', 0.0, false, null, art: new PlacedImage('abc', 572, 310, 136, 140)),
        ['field_key' => 'insignia', 'size' => 0.0, 'below_legibility' => 0,
            'set_data' => '{"art":{"hash":"abc","x":572,"y":310,"w":136,"h":140}}'],
    ],
    'a rank with no art' => [
        new SetField('insignia', 0.0, false, null),
        ['field_key' => 'insignia', 'size' => 0.0, 'below_legibility' => 0, 'set_data' => '{"art":null}'],
    ],
    'unicode and slashes, unescaped' => [
        new SetField('u', 10.0, false, [new Line('Zoë 005/02', 1.0, 2.0, 3.0, 0.0)]),
        ['field_key' => 'u', 'size' => 10.0, 'below_legibility' => 0,
            'set_data' => '{"lines":[{"t":"Zoë 005/02","x":1,"y":2,"w":3,"iw":0}]}'],
    ],
] as $label => [$set, $row]) {
    check("$label maps to its row", $set->row() === $row, json_encode($set->row()));
    check("$label comes back from its row", SetField::fromRow($row) == $set);
}

check(
    'a row as MySQL returns it reads back',
    SetField::fromRow(['field_key' => 'f', 'size' => '24.75', 'below_legibility' => '1', 'set_data' => '{"lines":[]}'])
        == new SetField('f', 24.75, true, [])
);

exit($failures === 0 ? 0 : 1);
