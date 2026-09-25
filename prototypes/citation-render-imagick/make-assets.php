<?php
// PROTOTYPE, throwaway. Stands in for what S1 would upload through the template manager:
//
//   plate-1275.png  the BSM art from prototype/citation-render, upscaled from 640px, with the
//                   fixed text set on it. S1's own export would come straight from GIMP at 1275px.
//   ink.png         a synthetic, ink-only signature. Deliberately not a real officer's.
//
//   php make-assets.php --fonts=fonts

$opt = getopt('', ['fonts:']);
$fontDir = $opt['fonts'] ?? __DIR__ . '/fonts';
$regular = realpath(glob("$fontDir/*-Regular.ttf")[0]);
$bold = realpath(glob("$fontDir/*-Bold.ttf")[0]);
$S = 1275 / 640;

// ---- plate --------------------------------------------------------------------------------

$plate = new Imagick(__DIR__ . '/plate-tall-640.png');
$plate->setImageBackgroundColor('white');
$plate->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
$plate->resizeImage(1275, (int) round(780 * $S), Imagick::FILTER_LANCZOS, 1);

$probe = new Imagick();
$probe->newImage(1, 1, 'white');
$draw = new ImagickDraw();
$draw->setTextAntialias(true);
$draw->setFillColor('#16161a');

// fixed text, centred on the plate's midline, from variant B's CSS
$fixed = [
    [$bold, 20, 138, 23, 'THE UNITED STATES ARMY'],
    [$regular, 12, 168, 13.5, 'TO ALL WHO SHALL SEE THESE PRESENTS, GREETINGS;'],
    [$regular, 12, 181.5, 13.5, 'THIS IS TO CERTIFY THAT THE SECRETARY OF THE ARMY HAS AWARDED THE'],
    [$regular, 12, 227, 14, 'TO'],
];
foreach ($fixed as [$font, $px, $top, $lh, $text]) {
    $size = $px * $S;
    $draw->setFont($font);
    $draw->setFontSize($size);
    $m = $probe->queryFontMetrics($draw, $text);
    $baseline = $top * $S + ($lh * $S - ($m['ascender'] - $m['descender'])) / 2 + $m['ascender'];
    $draw->annotation(320 * $S - $m['textWidth'] / 2, $baseline, $text);
}
// the rule the signature sits on belongs to the plate
$draw->setStrokeColor('#16161a');
$draw->setStrokeWidth(1.2 * $S / 2);
$draw->line(252 * $S, 697 * $S, 390 * $S, 697 * $S);
$plate->drawImage($draw);

$plate->stripImage();
$plate->setImageFormat('png');
$plate->writeImage(__DIR__ . '/plate-1275.png');
printf("plate-1275.png  %dx%d  %d bytes\n", $plate->getImageWidth(), $plate->getImageHeight(), filesize(__DIR__ . '/plate-1275.png'));

// ---- ink ----------------------------------------------------------------------------------
// A looping trochoid reads as cursive at a glance and is plainly nobody's name.

$w = 350;
$h = 96;
$ink = new Imagick();
$ink->newImage($w, $h, 'transparent');
$d = new ImagickDraw();
$d->setStrokeAntialias(true);
$d->setStrokeColor('#1b2440');
$d->setFillOpacity(0);
$d->setStrokeWidth(2.4);
$d->setStrokeLineCap(Imagick::LINECAP_ROUND);
$d->setStrokeLineJoin(Imagick::LINEJOIN_ROUND);

$points = [];
// a tall opening capital
for ($t = 0.6 * M_PI; $t <= 2.6 * M_PI; $t += 0.05) {
    $points[] = ['x' => 40 + 16 * cos($t) + 2 * $t, 'y' => 48 - 30 * sin($t)];
}
// then a run of loops, shrinking and drifting like a hand signing fast
for ($t = 0; $t <= 11 * M_PI; $t += 0.06) {
    $b = 11 + 5 * sin($t / 3.1);
    $points[] = ['x' => 58 + 7.6 * $t - $b * sin($t), 'y' => 58 - $b * cos($t) - 0.35 * $t];
}
$d->polyline($points);
// and an underline flourish
$d->bezier([['x' => 30, 'y' => 84], ['x' => 140, 'y' => 70], ['x' => 250, 'y' => 92], ['x' => 330, 'y' => 72]]);
$ink->drawImage($d);
$ink->trimImage(0);
$ink->setImagePage(0, 0, 0, 0);
$ink->borderImage('transparent', 4, 4);
$ink->stripImage();
$ink->setImageFormat('png');
$ink->writeImage(__DIR__ . '/ink.png');
printf("ink.png         %dx%d  %d bytes\n", $ink->getImageWidth(), $ink->getImageHeight(), filesize(__DIR__ . '/ink.png'));
