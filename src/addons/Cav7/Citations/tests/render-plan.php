<?php

/**
 * The draw plan, the overrun check and the library version read-out, none of
 * which need Imagick.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/render-plan.php
 */

namespace Cav7\Citations\Tests;

spl_autoload_register(function (string $class): void {
    $prefix = 'Cav7\\Citations\\Render\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../Render/' . substr($class, strlen($prefix)) . '.php';
    }
});

use Cav7\Citations\Render\DrawPlan;
use Cav7\Citations\Render\FontMetrics;
use Cav7\Citations\Render\LibraryVersions;
use Cav7\Citations\Render\Line;
use Cav7\Citations\Render\OverrunCheck;
use Cav7\Citations\Render\PlacedImage;
use Cav7\Citations\Render\PlannedLine;
use Cav7\Citations\Render\Plate;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Render\SetField;
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

/** Reports each line at a width set per text, as a rebuilt library might. */
final class FixedWidths implements FontMetrics
{
    /** @param array<string, float> $widths */
    public function __construct(private array $widths)
    {
    }

    public function advance(string $fontPath, string $char): float
    {
        return 0.5;
    }

    public function width(string $fontPath, float $size, string $text, float $interword = 0): float
    {
        return $this->widths[$text];
    }

    public function vertical(string $fontPath): array
    {
        return [0.8, -0.2];
    }
}

$version = Version::fromJson(json_encode([
    ['kind' => 'text', 'key' => 'body', 'x' => 100, 'y' => 100, 'w' => 300, 'h' => 100, 'pattern' => '{citation_text}',
        'font' => 'serif', 'size' => 30, 'legible_size' => 0, 'leading' => 1.5, 'mode' => 'wrap', 'align' => 'left',
        'caps' => false, 'colour' => '#111111'],
    ['kind' => 'signature', 'key' => 'sig', 'x' => 500, 'y' => 300, 'w' => 200, 'h' => 150, 'billet_id' => 1,
        'ink_height' => 80, 'font' => 'bold', 'size' => 19, 'legible_size' => 0, 'leading' => 1.2, 'colour' => '#222222'],
    ['kind' => 'rank_art', 'key' => 'insignia', 'x' => 20, 'y' => 20, 'w' => 60, 'h' => 60],
    ['kind' => 'rank_art', 'key' => 'empty_art', 'x' => 90, 'y' => 20, 'w' => 60, 'h' => 60],
]), '[]', 'member');

$rows = [
    'empty_art' => new SetField('empty_art', 0.0, false, null),
    'insignia' => new SetField('insignia', 0.0, false, null, art: new PlacedImage('arthash', 25, 25, 50, 50)),
    'sig' => new SetField('sig', 18.0, false, [new Line('Silas', 560.0, 400.0, 80.0, 0.0)], ink: new PlacedImage('inkhash', 520, 310, 160, 70)),
    'body' => new SetField('body', 24.5, false, [
        new Line('one', 100.0, 130.0, 298.0, 0.0),
        new Line('two', 100.0, 170.0, 299.0, 3.5),
    ]),
];

$plan = fn (array $rows, array $fonts = ['serif' => '/f/serif.ttf', 'bold' => '/f/bold.ttf']): DrawPlan => DrawPlan::build(
    $version,
    $rows,
    new Plate('/plates/p.png', 'png'),
    $fonts,
    fn (string $hash): string => "/files/$hash.png"
);

$built = $plan($rows);
$lines = array_values(array_filter($built->steps, fn (object $step): bool => $step instanceof PlannedLine));
check(
    'lines take their font, size and colour from the field and row',
    array_map(fn ($p): array => [$p->fieldKey, $p->fontPath, $p->size, $p->colour], $lines) === [
        ['body', '/f/serif.ttf', 24.5, '#111111'],
        ['body', '/f/serif.ttf', 24.5, '#111111'],
        ['sig', '/f/bold.ttf', 18.0, '#222222'],
    ]
);
check('lines keep their stored position', $lines[1]->line == new Line('two', 100.0, 170.0, 299.0, 3.5));
// The prototype draws a slot's ink before its name lines. A rank with no art plans no image.
check(
    'text and images draw in field order, not row order',
    array_map(fn (object $step): string => $step instanceof PlannedLine ? $step->line->t : $step->path, $built->steps)
        === ['one', 'two', '/files/inkhash.png', 'Silas', '/files/arthash.png']
);
check('a version with no fields is a plate alone', (function (): bool {
    $plan = DrawPlan::build(new Version([], [], 'none'), [], new Plate('/p.jpg', 'jpeg'), [], fn (string $h): string => $h);
    return $plan->steps === [] && $plan->plate->format === 'jpeg';
})());

foreach ([
    'a field with no row' => [array_diff_key($rows, ['sig' => 1]), ['serif' => 'a', 'bold' => 'b']],
    'a line with no font path' => [$rows, ['serif' => 'a']],
] as $label => [$broken, $fonts]) {
    try {
        $plan($broken, $fonts);
        check("$label fails the render", false);
    } catch (RenderFailed) {
        check("$label fails the render", true);
    }
}

// The body box's right edge is 400, the slot's is 700.
foreach ([
    'lines inside their boxes' => [['one' => 298.0, 'two' => 299.0, 'Silas' => 80.0], null, 0.0],
    'exactly 1px past is within tolerance' => [['one' => 301.0, 'two' => 299.0, 'Silas' => 80.0], null, 0.0],
    'just over 1px past is an overrun' => [['one' => 301.5, 'two' => 299.0, 'Silas' => 80.0], 'body', 1.5],
    'the worst line is the one reported' => [['one' => 305.0, 'two' => 303.0, 'Silas' => 80.0], 'body', 5.0],
    'a signature line checks against the slot' => [['one' => 298.0, 'two' => 299.0, 'Silas' => 145.0], 'sig', 5.0],
    'the slot box, not the body box, bounds a signature line' => [['one' => 298.0, 'two' => 299.0, 'Silas' => 140.0], null, 0.0],
] as $label => [$widths, $field, $pixels]) {
    $overrun = OverrunCheck::worst($built, new FixedWidths($widths));
    check($label, $overrun?->fieldKey === $field && ($overrun?->pixels ?? 0.0) === $pixels, json_encode($overrun));
}

foreach ([
    'ImageMagick 7.1.2-34 Q16-HDRI x86_64 24891 https://imagemagick.org' => '7.1.2-34',
    'ImageMagick 6.9.11-60 Q16 x86_64 2021-01-25 https://imagemagick.org' => '6.9.11-60',
    'GraphicsMagick 1.3' => 'unknown',
] as $versionString => $expected) {
    check("ImageMagick read-out of `$versionString`", LibraryVersions::imageMagickIn($versionString) === $expected);
}

foreach ([
    'the CLI text form' => ["GD Support => enabled\nFreeType Support => enabled\nFreeType Linkage => with freetype\nFreeType Version => 2.14.3\n", '2.14.3'],
    'the HTML form under FPM' => ['<tr><td class="e">FreeType Version </td><td class="v">2.14.3 </td></tr>', '2.14.3'],
    'no FreeType line' => ["GD Support => enabled\nGD Version => 2.3.3\n", 'unknown'],
    'no GD' => ['', 'unknown'],
] as $label => [$info, $expected]) {
    check("FreeType read-out from $label", LibraryVersions::freeTypeIn($info) === $expected);
}

exit($failures === 0 ? 0 : 1);
