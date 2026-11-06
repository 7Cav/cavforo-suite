<?php

/**
 * The draw through real Imagick, asserted on the decoded JPEG.
 *
 * Needs the imagick extension. On a dev stack, also render a certificate on a
 * real plate and look at it: no assertion here sees what a viewer sees.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/render-draw.php
 */

namespace Cav7\Citations\Tests;

spl_autoload_register(function (string $class): void {
    $prefix = 'Cav7\\Citations\\Render\\';
    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/../Render/' . substr($class, strlen($prefix)) . '.php';
    }
});

use Cav7\Citations\Render\DrawPlan;
use Cav7\Citations\Render\FontCatalog;
use Cav7\Citations\Render\FontMetrics;
use Cav7\Citations\Render\ImagickDrawer;
use Cav7\Citations\Render\ImagickMetrics;
use Cav7\Citations\Render\LibraryVersions;
use Cav7\Citations\Render\Line;
use Cav7\Citations\Render\PlacedImage;
use Cav7\Citations\Render\PlannedImage;
use Cav7\Citations\Render\PlannedLine;
use Cav7\Citations\Render\Plate;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Render\SetField;
use Cav7\Citations\Render\TextField;
use Cav7\Citations\Render\Typesetter;
use Cav7\Citations\Render\Values;
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

/** Claims every line is 5000px wide, as a very bad rebuild would. */
final class WildMetrics implements FontMetrics
{
    public function advance(string $fontPath, string $char): float
    {
        return 9.0;
    }

    public function width(string $fontPath, float $size, string $text, float $interword = 0): float
    {
        return 5000.0;
    }

    public function vertical(string $fontPath): array
    {
        return [3.0, -3.0];
    }
}

$tmp = sys_get_temp_dir() . '/cav7-render-draw-' . getmypid();
mkdir($tmp);
register_shutdown_function(function () use ($tmp): void {
    array_map('unlink', glob("$tmp/*"));
    rmdir($tmp);
});

function plateFile(string $path, string $format, int $w = 800, int $h = 600, string $colour = 'white'): string
{
    $image = new \Imagick();
    $image->newImage($w, $h, $colour);
    $image->setImageFormat($format);
    $image->writeImage("$format:$path");
    return $path;
}

/** A solid block on a transparent ground, its opaque part inset by `$inset`. */
function inkFile(string $path, int $w, int $h, string $colour, int $inset = 0): string
{
    $image = new \Imagick();
    $image->newImage($w, $h, 'transparent');
    $draw = new \ImagickDraw();
    $draw->setFillColor($colour);
    $draw->rectangle($inset, $inset, $w - 1 - $inset, $h - 1 - $inset);
    $image->drawImage($draw);
    $image->setImageFormat('png');
    $image->writeImage("png:$path");
    return $path;
}

function decoded(string $jpeg): \Imagick
{
    $image = new \Imagick();
    $image->readImageBlob($jpeg);
    return $image;
}

/** @return array{int, int, int, int}|null Left, top, right, bottom of the pixels darker than mid-grey. */
function inkBounds(\Imagick $image, int $x, int $y, int $w, int $h): ?array
{
    $pixels = $image->exportImagePixels($x, $y, $w, $h, 'I', \Imagick::PIXEL_CHAR);
    $left = PHP_INT_MAX;
    $top = PHP_INT_MAX;
    $right = -1;
    $bottom = -1;
    foreach ($pixels as $i => $value) {
        if ($value < 128) {
            $px = $x + $i % $w;
            $py = $y + intdiv($i, $w);
            $left = min($left, $px);
            $right = max($right, $px);
            $top = min($top, $py);
            $bottom = max($bottom, $py);
        }
    }
    return $right < 0 ? null : [$left, $top, $right, $bottom];
}

function near(float $a, float $b, float $tolerance): bool
{
    return abs($a - $b) <= $tolerance;
}

function colourAt(\Imagick $image, int $x, int $y): array
{
    $c = $image->getImagePixelColor($x, $y)->getColor();
    return [$c['r'], $c['g'], $c['b']];
}

function similar(array $a, array $b): bool
{
    return max(array_map(fn (int $i, int $j): int => abs($i - $j), $a, $b)) <= 12;
}

/** @return array{markers: list<int>, sampling: list<int>} The JPEG's marker bytes up to the scan, and its SOF sampling bytes. */
function jpegStructure(string $bytes): array
{
    $markers = [];
    $sampling = [];
    for ($i = 2; $i < strlen($bytes);) {
        $marker = ord($bytes[$i + 1]);
        $markers[] = $marker;
        if ($marker === 0xDA) {
            break;
        }
        $length = unpack('n', substr($bytes, $i + 2, 2))[1];
        if ($marker >= 0xC0 && $marker <= 0xC2) {
            for ($c = 0, $n = ord($bytes[$i + 9]); $c < $n; $c++) {
                $sampling[] = ord($bytes[$i + 11 + $c * 3]);
            }
        }
        $i += 2 + $length;
    }
    return ['markers' => $markers, 'sampling' => $sampling];
}

$tinos = FontCatalog::paths()['tinos-regular'];
$metrics = new ImagickMetrics();
$drawer = new ImagickDrawer($metrics);
$plate = new Plate(plateFile("$tmp/plate.png", 'png', 800, 600, '#fffdf5'), 'png');
$greyPlate = new Plate(plateFile("$tmp/grey.png", 'png'), 'png');

$planned = fn (Line $line, float $size = 40.0, int $boxRight = 780, string $colour = '#000000', string $font = ''): PlannedLine
    => new PlannedLine('body', $font !== '' ? $font : $tinos, $size, $colour, $line, $boxRight);

// A render is the plate's own size, in the encoder's profile.
$drawn = $drawer->draw(new DrawPlan($plate, [$planned(new Line('HHHH', 100.0, 200.0, 0.0, 0.0))], []));
$image = decoded($drawn->jpeg);
$structure = jpegStructure($drawn->jpeg);

check('a render is the plate\'s pixel size', [$image->getImageWidth(), $image->getImageHeight()] === [800, 600]);
check('the output is JPEG', $image->getImageFormat() === 'JPEG');
check('quality is 85', $image->getImageCompressionQuality() === 85);
check('sampling is 4:4:4 in the frame header', $structure['sampling'] === [0x11, 0x11, 0x11], json_encode($structure['sampling']));
check('sampling reads 1x1 on decode', $image->getImageProperty('jpeg:sampling-factor') === '1x1,1x1,1x1');
check('it is baseline', in_array(0xC0, $structure['markers'], true) && !in_array(0xC2, $structure['markers'], true));
check('it carries no metadata markers', array_intersect($structure['markers'], [0xE1, 0xE2, 0xED, 0xFE]) === [], json_encode($structure['markers']));

// Text lands where the typesetting says.
$bounds = inkBounds($image, 0, 0, 800, 600);
$width = $metrics->width($tinos, 40.0, 'HHHH');
check('text starts at the stored x', near($bounds[0], 100, 3), json_encode($bounds));
check('text sits on the stored baseline', near($bounds[3], 199, 2), json_encode($bounds));
check('text ends where its measured width says', near($bounds[2], 100 + $width, 4), json_encode($bounds));
check('capitals rise from the baseline by the cap height', near($bounds[1], 200 - 0.66 * 40, 3), json_encode($bounds));

// The stored numbers decide, not the current metrics.
$stored = [$planned(new Line('HHHH', 300.5, 450.0, 0.0, 0.0))];
$real = $drawer->draw(new DrawPlan($plate, $stored, []));
$wild = (new ImagickDrawer(new WildMetrics()))->draw(new DrawPlan($plate, $stored, []));
check('other font metrics change no drawn pixel', $real->jpeg === $wild->jpeg);
check('other font metrics report the overrun', $wild->overrun?->fieldKey === 'body' && $real->overrun === null);

// Overrun.
$past = $metrics->width($tinos, 40.0, 'HHHH');
foreach ([
    'a line inside its box reports nothing' => [100 + $past, null],
    'a line 1px past its box reports nothing' => [100 + $past - 1, null],
    'a line 2px past its box reports the overrun' => [100 + $past - 2, 2.0],
] as $label => [$boxRight, $pixels]) {
    $result = $drawer->draw(new DrawPlan($plate, [$planned(new Line('HHHH', 100.0, 200.0, 0.0, 0.0), 40.0, (int) $boxRight)], []));
    check($label, ($result->overrun?->pixels) === $pixels, json_encode($result->overrun));
    check("$label and still renders", decoded($result->jpeg)->getImageWidth() === 800);
}

// Interword spacing from the row reaches the page, and justification ends at the box.
$justifyField = new TextField('body', 100, 50, 600, 300, '{citation_text}', 'tinos-regular', 40.0, 0.0, 1.5, 'wrap', 'justify', false, '#000000');
foreach ([
    'plain spaces' => 'Honour held the line and the troop stood firm beside him through the long night H',
    'a U+00A0 among them' => "Honour held the line and the\u{A0}troop stood firm beside him through the long night H",
] as $label => $text) {
    $set = (new Typesetter($metrics))->set([$justifyField], ['tinos-regular' => $tinos], new Values('', null, '2026-03-21', $text))['body'];
    $line = $set->lines[0];
    check("justified with $label: the measured width is within 1px of the box", near($line->w, 600, 1), json_encode($line));
    $page = decoded($drawer->draw(new DrawPlan($plate, [$planned($line, $set->size)], []))->jpeg);
    $ink = inkBounds($page, 0, 0, 800, 600);
    check("justified with $label: ink reaches the box edge", near($ink[2], 700, 4), json_encode($ink));
}

// Values reach the page as typed.
$literal = "100% of %w @/etc/passwd 'it' \"so\" \\n H";
$page = decoded($drawer->draw(new DrawPlan($plate, [$planned(new Line($literal, 20.0, 200.0, 0.0, 0.0), 30.0)], []))->jpeg);
$ink = inkBounds($page, 0, 0, 800, 600);
check(
    'percent escapes, @paths, quotes and backslashes print literally',
    near($ink[2], 20 + $metrics->width($tinos, 30.0, $literal), 4) && near($ink[3], 199, 8),
    json_encode($ink)
);

// Colour, and images over the text.
$colours = $drawer->draw(new DrawPlan($plate, [$planned(new Line('HHHH', 100.0, 200.0, 0.0, 0.0), 120.0, 780, '#c00000')], []));
$page = decoded($colours->jpeg);
$bar = inkBounds($page, 100, 80, 40, 130);
check('the fill colour is the field\'s', similar(colourAt($page, (int) (($bar[0] + $bar[2]) / 2), $bar[3] - 30), [192, 0, 0]));

$exact = new PlannedImage(inkFile("$tmp/ink.png", 100, 50, '#0000ff', 10), new PlacedImage('h', 200, 300, 100, 50));
$scaled = new PlannedImage(inkFile("$tmp/big.png", 400, 200, '#00c000'), new PlacedImage('h', 500, 300, 100, 50));
$page = decoded($drawer->draw(new DrawPlan($greyPlate, [], [$exact, $scaled]))->jpeg);
check('a colour image on a greyscale plate lands at its stored position in colour', similar(colourAt($page, 250, 325), [0, 0, 255]));
check('an image\'s transparency shows the plate', similar(colourAt($page, 203, 303), [255, 255, 255]));
check('an image larger than its stored size is resized to it', similar(colourAt($page, 595, 345), [0, 192, 0]) && similar(colourAt($page, 605, 345), [255, 255, 255]));

$overText = new PlannedImage(inkFile("$tmp/cover.png", 300, 100, '#00c000'), new PlacedImage('h', 80, 150, 300, 100));
$page = decoded($drawer->draw(new DrawPlan($plate, [$planned(new Line('HHHH', 100.0, 200.0, 0.0, 0.0))], [$overText]))->jpeg);
check('images composite over the text', similar(colourAt($page, 120, 190), [0, 192, 0]));

// Plates.
$jpegPlate = new Plate(plateFile("$tmp/plate.jpg", 'jpeg', 511, 400, '#336699'), 'jpeg');
$page = decoded($drawer->draw(new DrawPlan($jpegPlate, [], []))->jpeg);
check('a JPEG plate reads through its own coder at its own size', [$page->getImageWidth(), $page->getImageHeight()] === [511, 400]);
check('a plate alone draws with no fields', similar(colourAt($page, 100, 100), [0x33, 0x66, 0x99]));

$clear = new \Imagick();
$clear->newImage(200, 100, 'transparent');
$clear->setImageFormat('png');
$clear->writeImage("png:$tmp/clear.png");
$page = decoded($drawer->draw(new DrawPlan(new Plate("$tmp/clear.png", 'png'), [], []))->jpeg);
check('a transparent plate flattens onto white', similar(colourAt($page, 100, 50), [255, 255, 255]));

$marked = new \Imagick();
$marked->newImage(100, 100, 'white');
$marked->setImageResolution(300, 300);
$marked->setImageUnits(\Imagick::RESOLUTION_PIXELSPERINCH);
$marked->commentImage('a comment');
$marked->setImageFormat('png');
$marked->writeImage("png:$tmp/marked.png");
check(
    'a plate\'s density and metadata do not reach the JPEG, so equal pixels encode equally',
    $drawer->draw(new DrawPlan(new Plate("$tmp/marked.png", 'png'), [], []))->jpeg
        === $drawer->draw(new DrawPlan(new Plate(plateFile("$tmp/plain.png", 'png', 100, 100), 'png'), [], []))->jpeg
);

// Failed renders.
file_put_contents("$tmp/fake.png", '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>');
foreach ([
    'a missing plate' => new DrawPlan(new Plate("$tmp/nope.png", 'png'), [], []),
    'SVG bytes named .png' => new DrawPlan(new Plate("$tmp/fake.png", 'png'), [], []),
    'a plate whose bytes are not its format' => new DrawPlan(new Plate(plateFile("$tmp/jpeg.png.jpg", 'jpeg'), 'png'), [], []),
    'a missing font' => new DrawPlan($plate, [$planned(new Line('H', 10.0, 10.0, 0.0, 0.0), font: "$tmp/nope.ttf")], []),
    'a missing image' => new DrawPlan($plate, [], [new PlannedImage("$tmp/nope.png", new PlacedImage('h', 0, 0, 10, 10))]),
] as $label => $plan) {
    try {
        $drawer->draw($plan);
        check("$label is a failed render", false, 'drew');
    } catch (RenderFailed) {
        check("$label is a failed render", true);
    }
}

// The whole round trip: typeset, store, read back, draw.
$version = Version::fromJson(json_encode([[
    'kind' => 'text', 'key' => 'recipient', 'x' => 40, 'y' => 100, 'w' => 720, 'h' => 60, 'pattern' => '{rank} {name}',
    'font' => 'tinos-bold', 'size' => 40, 'legible_size' => 26, 'leading' => 1.2, 'mode' => 'line', 'align' => 'center',
    'caps' => true, 'colour' => '#000000',
]]), '[]', 'member');
$fonts = FontCatalog::paths();
$typeset = (new Typesetter($metrics))->set($version->fields, $fonts, new Values(
    'Zoë Castellan',
    new \Cav7\Citations\Render\RankSnapshot('Staff Sergeant', 'E-6', 'SSG'),
    '2026-03-21',
    ''
));
$rows = array_map(fn (SetField $set): SetField => SetField::fromRow($set->row()), $typeset);
$line = $rows['recipient']->lines[0];
check('a stored line carries the cased text', $line->t === 'STAFF SERGEANT ZOË CASTELLAN');
$result = $drawer->draw(DrawPlan::build($version, $rows, $plate, $fonts, fn (string $hash): string => $hash));
$ink = inkBounds(decoded($result->jpeg), 0, 0, 800, 600);
check('a stored line draws centred in its box', near(($ink[0] + $ink[2]) / 2, 400, 3), json_encode($ink));
check('a stored line draws inside its box', $result->overrun === null);

// The width table.
$before = $metrics->advance($tinos, 'H');
check('a character\'s advance is in ems', $before > 0.5 && $before < 1.0, (string) $before);
foreach (FontCatalog::FONTS as $key => $font) {
    [$ascent, $descent] = $metrics->vertical($fonts[$key]);
    $name = substr($font['file'], 0, strcspn($font['file'], '-'));
    check("$key loads with its licence beside it", $ascent > 0 && $descent < 0 && is_file(dirname($fonts[$key]) . "/OFL-$name.txt"));
}

$versions = LibraryVersions::read();
check('the ImageMagick version reads', preg_match('/^\d+\.\d+\.\d+-\d+$/', $versions->imagemagick_version) === 1, $versions->imagemagick_version);
check('the FreeType version reads or says unknown', preg_match('/^(\d+\.\d+\.\d+|unknown)$/', $versions->freetype_version) === 1, $versions->freetype_version);

exit($failures === 0 ? 0 : 1);
