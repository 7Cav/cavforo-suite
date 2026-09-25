<?php
// PROTOTYPE, throwaway. The three sample renders on one sheet, each captioned with its
// citation text length and fitted size, for showing the look without opening three files.
//
//   php sheet.php --fonts=fonts        reads out/sample-{short,long,tail}.jpg

$opt = getopt('', ['fonts:']);
$font = realpath(glob(($opt['fonts'] ?? 'fonts') . '/*-Regular.ttf')[0]);
$bold = realpath(glob(($opt['fonts'] ?? 'fonts') . '/*-Bold.ttf')[0]);

$samples = [
    'short' => ['880 characters, 1 paragraph', 'set at 14.3pt, the design cap'],
    'long' => ['1,429 characters, 3 paragraphs', 'shrunk to 11.9pt to fit'],
    'tail' => ['2,090 characters, 4 paragraphs', 'shrunk to 9.8pt to fit'],
];
$w = 620;
$gap = 24;
$head = 120;
$foot = 70;
$h = (int) round(1554 * $w / 1275);
$sheet = new Imagick();
$sheet->newImage(count($samples) * $w + (count($samples) + 1) * $gap, $head + $h + $foot, '#1f2328');

$d = new ImagickDraw();
$d->setTextAntialias(true);
$d->setFillColor('#f2f1e6');
$d->setFont($bold);
$d->setFontSize(34);
$d->annotation($gap, 52, 'Generated citation: one template, three citation texts');
$d->setFont($font);
$d->setFontSize(21);
$d->setFillColor('#b8bcc4');
$d->annotation($gap, 90, 'PROTOTYPE. Fabricated member, citation text and signature. The typed text is fitted to its box automatically.');

$x = $gap;
foreach ($samples as $name => [$line1, $line2]) {
    $im = new Imagick(__DIR__ . "/out/sample-$name.jpg");
    $im->resizeImage($w, $h, Imagick::FILTER_LANCZOS, 1);
    $sheet->compositeImage($im, Imagick::COMPOSITE_OVER, $x, $head);
    $d->setFillColor('#f2f1e6');
    $d->setFontSize(22);
    $d->annotation($x, $head + $h + 32, $line1);
    $d->setFillColor('#b8bcc4');
    $d->annotation($x, $head + $h + 60, $line2);
    $x += $w + $gap;
}
$sheet->drawImage($d);
$sheet->setImageFormat('jpeg');
$sheet->setImageCompressionQuality(90);
$sheet->setSamplingFactors(['1x1', '1x1', '1x1']);
$sheet->stripImage();
$sheet->writeImage(__DIR__ . '/compare/samples-sheet.jpg');
echo 'samples-sheet.jpg ', $sheet->getImageWidth(), 'x', $sheet->getImageHeight(), "\n";
