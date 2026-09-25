<?php
// PROTOTYPE, throwaway. Puts the Imagick render next to the Chromium prototype's variant B at
// the same 1275px width. The Chromium page is screenshotted by hand (see README) because it
// needs a browser; its signature block is a real officer's, so it is blurred here.
//
//   php compare.php --imagick=out/short.jpg --chromium=chromium-typeset-1275.png --fonts=fonts

$opt = getopt('', ['imagick:', 'chromium:', 'fonts:']);
$a = new Imagick($opt['imagick']);
$b = new Imagick($opt['chromium']);
$b->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
$font = realpath(glob(($opt['fonts'] ?? 'fonts') . '/*-Regular.ttf')[0]);
@mkdir(__DIR__ . '/compare');

function label(Imagick $im, string $text, string $font): Imagick
{
    $out = new Imagick();
    $out->newImage($im->getImageWidth(), $im->getImageHeight() + 44, '#20242a');
    $d = new ImagickDraw();
    $d->setFont($font);
    $d->setFontSize(22);
    $d->setFillColor('#f2f1e6');
    $d->annotation(12, 30, $text);
    $out->drawImage($d);
    $out->compositeImage($im, Imagick::COMPOSITE_OVER, 0, 44);
    return $out;
}

function pair(Imagick $l, Imagick $r, string $path): void
{
    $out = new Imagick();
    $out->newImage($l->getImageWidth() + $r->getImageWidth() + 16, max($l->getImageHeight(), $r->getImageHeight()), '#20242a');
    $out->compositeImage($l, Imagick::COMPOSITE_OVER, 0, 0);
    $out->compositeImage($r, Imagick::COMPOSITE_OVER, $l->getImageWidth() + 16, 0);
    $out->setImageFormat('png');
    $out->writeImage($path);
    echo basename($path), ' ', $out->getImageWidth(), 'x', $out->getImageHeight(), "\n";
}

// the signature slot, in 1275px units, blurred on the Chromium page only
// getImageRegion takes width, height, x, y
$sig = $b->getImageRegion(440, 190, 420, 1300);
$sig->blurImage(0, 14);
$b->compositeImage($sig, Imagick::COMPOSITE_OVER, 420, 1300);

// 1:1 crops of the same regions
$regions = [
    'citation-text' => [130, 600, 620, 330],
    'award-and-member' => [90, 380, 1100, 170],
];
foreach ($regions as $name => [$x, $y, $w, $h]) {
    pair(
        label($a->getImageRegion($w, $h, $x, $y), 'Imagick, Tinos-metric font, set at 1275px', $font),
        label($b->getImageRegion($w, $h, $x, $y), 'Chromium, Times New Roman, DPR 1.99', $font),
        __DIR__ . "/compare/$name-1to1.png"
    );
}

// whole pages at half size
$ha = clone $a;
$hb = clone $b;
$ha->resizeImage(638, 0, Imagick::FILTER_LANCZOS, 1);
$hb->resizeImage(638, 0, Imagick::FILTER_LANCZOS, 1);
pair(label($ha, 'Imagick', $font), label($hb, 'Chromium (signature blurred)', $font), __DIR__ . '/compare/pages-half.png');
