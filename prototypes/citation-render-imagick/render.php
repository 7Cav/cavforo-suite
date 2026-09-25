<?php
// PROTOTYPE, throwaway. Do not merge to develop. No tests, no error handling.
//
// Question (7Cav/cavforo-suite#303): can Imagick, as prod runs it, set a citation's typed
// fields well enough and fast enough to render on view?
//
//   php render.php --fonts=fonts --text=short --out=out/short.jpg      one render, prints state
//   php render.php --fonts=fonts --text=long --bench=30                 30 renders in one process
//   php render.php --env                                                what this PHP's Imagick is
//
// Layout is variant B ("typeset") of the Chromium prototype on prototype/citation-render,
// the taller portrait layout leadership preferred, scaled from its 640px plate to 1275px.
// Every box below is in those 640px plate units and multiplied by S at draw time, so the
// numbers can be checked against template.html on that branch.
//
// What shaped the code: every Imagick text call (measure or draw) costs ~0.6 ms however warm
// the process is, because ImageMagick opens the font afresh each time. Measuring word by
// word and bisecting the size took 1.4 s on a long citation. So the fit runs on a table of
// character advances measured once, and Imagick measures and draws each final line once.

final class CitationRender
{
    public const WIDTH = 1275;
    public const S = self::WIDTH / 640;

    private const INK = '#16161a';
    private const INK_SOFT = '#3a3a3a';

    // field boxes in 640px plate units: [left, top, width, line height]
    private const AWARD = [16, 193, 607, 29];
    private const MEMBER = [17, 245, 608, 23];
    private const GIVEN = [15, 640, 610, 14];
    private const SIG_INK = [233, 652, 176, 44];
    private const SIG_LINES = [233, 699, 176, 11];
    // the citation text box: [left, top, width, height]
    private const BODY = [70, 285, 500, 320];

    // citation text: design cap and floor in 640px units, and leading, from variant B
    private const BODY_MAX = 15.0;
    private const BODY_MIN = 6.0;
    private const LEADING = 1.62;

    // the size the advance table is measured at
    private const REF = 100.0;

    public array $state = [];
    // re-measure each justified line after correcting it; diagnostic, so benches turn it off
    public bool $verify = true;

    private string $regular;
    private string $bold;
    private Imagick $probe;
    private array $advances = [];
    private float $kern = 1.0;
    private int $calls = 0;

    public function __construct(private string $dir, string $fontDir)
    {
        $this->regular = self::font($fontDir, 'Regular');
        $this->bold = self::font($fontDir, 'Bold');
        $this->probe = new Imagick();
        $this->probe->newImage(1, 1, 'white');
    }

    private static function font(string $dir, string $style): string
    {
        $hits = glob(rtrim($dir, '/') . "/*-$style.ttf");
        if (!$hits) {
            fwrite(STDERR, "no *-$style.ttf in $dir\n");
            exit(2);
        }
        return realpath($hits[0]);
    }

    /** @return string JPEG bytes */
    public function render(array $grant, string $text): string
    {
        $this->calls = 0;
        $t = ['start' => hrtime(true)];

        $im = new Imagick($this->dir . '/plate-1275.png');
        $t['load plate'] = hrtime(true);

        $draw = new ImagickDraw();
        $draw->setTextAntialias(true);
        $draw->setTextEncoding('UTF-8');

        $award = $this->oneLine($draw, $this->regular, 26, self::INK, self::AWARD, $grant['award']);
        $member = $this->oneLine($draw, $this->bold, 20, self::INK_SOFT, self::MEMBER, $grant['rank'] . ' ' . $grant['name']);
        $given = $this->oneLine($draw, $this->regular, 12, self::INK, self::GIVEN, $grant['given']);
        foreach ($grant['signature']['lines'] as $i => $line) {
            [$l, $top, $w, $h] = self::SIG_LINES;
            $this->oneLine($draw, $this->regular, 9.5, self::INK, [$l, $top + $i * $h, $w, $h], $line);
        }
        $t['one-line fields'] = hrtime(true);

        $fit = $this->fitBody($text);
        $t['fit citation text'] = hrtime(true);

        $this->drawBody($draw, $fit);
        $t['measure final lines'] = hrtime(true);

        $im->drawImage($draw);
        $t['draw text'] = hrtime(true);

        $ink = new Imagick($this->dir . '/' . $grant['signature']['ink']);
        [$l, $top, $w, $h] = self::SIG_INK;
        $x = (int) round(($l + $w / 2) * self::S - $ink->getImageWidth() / 2);
        $y = (int) round(($top + $h) * self::S - $ink->getImageHeight());
        $im->compositeImage($ink, Imagick::COMPOSITE_OVER, $x, $y);
        $t['composite signature'] = hrtime(true);

        // flatten onto white, as the re-encode does
        $im->setImageBackgroundColor('white');
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $t['flatten'] = hrtime(true);

        // the encoder profile the re-encode pins (#268)
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(85);
        $im->setSamplingFactors(['1x1', '1x1', '1x1']);
        $im->setOption('jpeg:dct-method', 'islow');
        $im->setOption('jpeg:optimize-coding', 'true');
        $im->setInterlaceScheme(Imagick::INTERLACE_NO);
        $im->stripImage();
        $bytes = $im->getImageBlob();
        $t['encode'] = hrtime(true);

        $steps = [];
        $prev = $t['start'];
        foreach ($t as $k => $v) {
            if ($k !== 'start') {
                $steps[$k] = round(($v - $prev) / 1e6, 1);
                $prev = $v;
            }
        }

        $this->state = [
            'canvas' => $im->getImageWidth() . 'x' . $im->getImageHeight(),
            'award' => $award,
            'member' => $member,
            'given' => $given,
            'citation text' => $fit['report'],
            'imagick text calls' => $this->calls,
            'steps ms' => $steps,
            'total ms' => round(($prev - $t['start']) / 1e6, 1),
            'bytes' => strlen($bytes),
        ];
        return $bytes;
    }

    // ---- measuring ---------------------------------------------------------------------

    private function metrics(string $font, float $size, string $s, float $interword = 0): array
    {
        $d = new ImagickDraw();
        $d->setFont($font);
        $d->setFontSize($size);
        $d->setTextEncoding('UTF-8');
        if ($interword) {
            $d->setTextInterwordSpacing($interword);
        }
        $this->calls++;
        return $this->probe->queryFontMetrics($d, $s);
    }

    private function width(string $font, float $size, string $s, float $interword = 0): float
    {
        return $this->metrics($font, $size, $s, $interword)['textWidth'];
    }

    // Estimated width from per-character advances at REF, scaled to size and by the kerning
    // ratio fitBody measures. Final lines are measured exactly before drawing.
    private function estimate(float $size, string $s): float
    {
        $sum = 0.0;
        foreach (mb_str_split($s) as $c) {
            $sum += $this->advances[$c] ??= $this->width($this->regular, self::REF, $c);
        }
        return $sum * $size / self::REF * $this->kern;
    }

    // ---- one-line fields: design size, shrunk only if the line overflows its box --------

    private function oneLine(ImagickDraw $draw, string $font, float $max, string $colour, array $box, string $text): string
    {
        [$l, $top, $w, $h] = $box;
        $boxW = $w * self::S;
        $size = $max * self::S;
        $m = $this->metrics($font, $size, $text);
        while ($m['textWidth'] > $boxW && $size > 4) {
            $size = floor(($size * $boxW / $m['textWidth']) * 4) / 4;
            $m = $this->metrics($font, $size, $text);
        }
        $lineH = $h * self::S;
        $baseline = $top * self::S + ($lineH - ($m['ascender'] - $m['descender'])) / 2 + $m['ascender'];

        $draw->setFont($font);
        $draw->setFontSize($size);
        $draw->setFillColor($colour);
        $draw->setTextInterwordSpacing(0);
        $draw->annotation(($l + $w / 2) * self::S - $m['textWidth'] / 2, $baseline, $text);

        return sprintf('%.2fpx%s', $size, $size < $max * self::S ? ' (shrunk from ' . round($max * self::S, 2) . 'px)' : '');
    }

    // ---- citation text: the largest size at which the text fits its box ----------------
    // This is the job an S1 Citations clerk does by hand in GIMP today.

    private function wrap(array $paras, float $size, float $boxW): array
    {
        $space = $this->estimate($size, ' ');
        $lines = [];
        foreach ($paras as $p) {
            $cur = [];
            $w = 0.0;
            foreach (preg_split('/\s+/u', trim($p)) as $word) {
                $ww = $this->estimate($size, $word);
                if ($cur && $w + $space + $ww > $boxW) {
                    $lines[] = ['text' => implode(' ', $cur), 'last' => false];
                    $cur = [];
                    $w = 0.0;
                }
                $w = $cur ? $w + $space + $ww : $ww;
                $cur[] = $word;
            }
            if ($cur) {
                $lines[] = ['text' => implode(' ', $cur), 'last' => true];
            }
        }
        return $lines;
    }

    private function fitBody(string $text): array
    {
        [, , $w, $h] = self::BODY;
        $boxW = $w * self::S;
        $boxH = $h * self::S;
        $paras = preg_split('/\n+/', trim($text));
        // The advance table knows nothing of kerning, so it runs long and breaks lines a word
        // early. One exact measurement of the whole text scales it back.
        $joined = implode(' ', $paras);
        $this->kern = 1.0;
        $this->kern = $this->width($this->regular, self::REF, $joined) / $this->estimate(self::REF, $joined);
        $fits = fn (float $size) => count($this->wrap($paras, $size, $boxW)) * $size * self::LEADING <= $boxH;

        $hi = self::BODY_MAX * self::S;
        $lo = self::BODY_MIN * self::S;
        $capped = $fits($hi);
        $tried = 1;
        if ($capped) {
            $size = $hi;
        } else {
            // bisect to a quarter pixel
            while ($hi - $lo > 0.25) {
                $mid = ($lo + $hi) / 2;
                $tried++;
                $fits($mid) ? $lo = $mid : $hi = $mid;
            }
            $size = floor($lo * 4) / 4;
        }
        $lines = $this->wrap($paras, $size, $boxW);
        $overflow = count($lines) * $size * self::LEADING > $boxH;

        return [
            'size' => $size,
            'lines' => $lines,
            'report' => sprintf(
                '%.2fpx = %.2fpx on the 640 plate = %.1fpt printed on Letter, %d lines, %d chars / %d paras, %s, %d sizes tried%s',
                $size, $size / self::S, $size * 72 / 150, count($lines), mb_strlen($text), count($paras),
                $capped ? 'fits at the design cap' : 'shrunk to fit', $tried,
                $overflow ? ', OVERFLOWS at the floor' : ''
            ),
        ];
    }

    private function drawBody(ImagickDraw $draw, array &$fit): void
    {
        [$l, $top, $w, $h] = self::BODY;
        $size = $fit['size'];
        $lineH = $size * self::LEADING;
        $left = $l * self::S;
        $boxW = $w * self::S;
        $m = $this->metrics($this->regular, $size, 'Hg');
        [$asc, $desc] = [$m['ascender'], $m['descender']];
        // centre the block vertically, like the flex box in variant B
        $y0 = $top * self::S + ($h * self::S - count($fit['lines']) * $lineH) / 2;

        $draw->setFont($this->regular);
        $draw->setFontSize($size);
        $draw->setFillColor(self::INK);

        $space = $this->estimate($size, ' ');
        $worst = 0.0;
        $tightest = INF;
        foreach ($fit['lines'] as $i => $line) {
            $baseline = $y0 + $i * $lineH + ($lineH - ($asc - $desc)) / 2 + $asc;
            $gaps = substr_count($line['text'], ' ');
            if ($line['last'] || $gaps === 0) {
                // text-align-last: center
                $natural = $this->width($this->regular, $size, $line['text']);
                $draw->setTextInterwordSpacing(0);
                $draw->annotation($left + ($boxW - $natural) / 2, $baseline, $line['text']);
                continue;
            }
            // text-align: justify. ImageMagick's interword-spacing replaces the space's own
            // advance rather than adding to it, and width is linear in it, so aim from the
            // estimate, measure once, and correct once.
            $aim = $space + ($boxW - $this->estimate($size, $line['text'])) / $gaps;
            // a line the estimate ran short on comes out a little long, and closes its spaces up
            $tightest = min($tightest, $aim / $space);
            $got = $this->width($this->regular, $size, $line['text'], $aim);
            $interword = $aim + ($boxW - $got) / $gaps;
            if ($this->verify) {
                $worst = max($worst, abs($boxW - $this->width($this->regular, $size, $line['text'], $interword)));
            }
            $draw->setTextInterwordSpacing($interword);
            $draw->annotation($left, $baseline, $line['text']);
        }
        $draw->setTextInterwordSpacing(0);
        if ($this->verify) {
            $fit['report'] .= sprintf(', justified lines within %.1fpx of the box edge', $worst);
        }
        if ($tightest < INF) {
            $fit['report'] .= sprintf(', tightest word space %d%% of normal', $tightest * 100);
        }
    }
}

function env(): void
{
    $o = Imagick::getConfigureOptions();
    $v = Imagick::getVersion();
    $limits = [];
    foreach (['AREA', 'DISK', 'FILE', 'MAP', 'MEMORY', 'THREAD', 'TIME', 'WIDTH', 'HEIGHT', 'LISTLENGTH'] as $r) {
        $limits[strtolower($r)] = Imagick::getResourceLimit(constant("Imagick::RESOURCETYPE_$r"));
    }
    $report = [
        'php' => PHP_VERSION . ' ' . php_uname('m'),
        'imagick ext' => phpversion('imagick'),
        'imagemagick' => $v['versionString'],
        'delegates' => $o['DELEGATES'] ?? '?',
        'freetype delegate' => str_contains($o['DELEGATES'] ?? '', 'freetype') ? 'yes' : 'NO',
        'jpeg delegate' => str_contains($o['DELEGATES'] ?? '', 'jpeg') ? 'yes' : 'NO',
        'features' => $o['FEATURES'] ?? '?',
        'imagick.set_single_thread' => ini_get('imagick.set_single_thread'),
        'resource limits' => $limits,
    ];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $opt = getopt('', ['fonts:', 'text:', 'out:', 'bench:', 'env', 'json']);
    if (isset($opt['env'])) {
        env();
        exit;
    }
    $grant = require __DIR__ . '/grant.php';
    $text = $grant['texts'][$opt['text'] ?? 'short'];

    $runs = (int) ($opt['bench'] ?? 1);
    $totals = [];
    for ($i = 0; $i < $runs; $i++) {
        // a new renderer per run, as each FPM request would have: PHP-side state starts
        // empty, while ImageMagick's coder modules stay loaded in the worker
        $r = new CitationRender(__DIR__, $opt['fonts'] ?? __DIR__ . '/fonts');
        $r->verify = $runs === 1;
        $bytes = $r->render($grant, $text);
        $totals[] = $r->state['total ms'];
        if ($i === 0 && !isset($opt['json'])) {
            echo json_encode($r->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
        }
    }
    if (isset($opt['out'])) {
        @mkdir(dirname($opt['out']), 0777, true);
        file_put_contents($opt['out'], $bytes);
    }
    $warm = array_slice($totals, 1);
    sort($warm);
    $pick = fn (array $a, float $q) => $a[(int) floor($q * (count($a) - 1))];
    echo json_encode([
        'text' => $opt['text'] ?? 'short',
        'first render ms' => $totals[0],
        'warm renders' => count($warm),
        'warm p50 ms' => $warm ? $pick($warm, 0.5) : null,
        'warm p95 ms' => $warm ? $pick($warm, 0.95) : null,
        'bytes' => $r->state['bytes'],
        'peak rss MiB' => round(getrusage()['ru_maxrss'] / (PHP_OS_FAMILY === 'Darwin' ? 1048576 : 1024), 1),
    ], JSON_UNESCAPED_SLASHES), "\n";
}
