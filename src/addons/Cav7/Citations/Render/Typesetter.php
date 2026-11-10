<?php

namespace Cav7\Citations\Render;

/**
 * Assumes ImageMagick's interword spacing replaces the advance of every space in
 * its IsUTFSpace list, U+00A0 included, so GAP counts them all. After an ImageMagick
 * upgrade, check on a dev stack that a justified line holding a U+00A0 still ends
 * at the box edge. The caller stamps the rows with the addon and library versions.
 */
final class Typesetter
{
    public const REFERENCE_SIZE = 100.0;
    public const SIZE_STEP = 0.25;
    public const GRID_FLOOR = 1.0;
    /** Below the grid the fit walks in the stored precision, decimal(6,2). */
    public const FINE_STEP = 0.01;
    public const MIN_SIZE = 0.01;
    public const OVERRUN_TOLERANCE = 1.0;

    private const GAP = '/[ \x{85}\x{A0}\x{1680}\x{180E}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u';

    public function __construct(private FontMetrics $metrics)
    {
    }

    /**
     * @param list<TextField|SignatureSlot|RankArtField> $fields Only the fields to set.
     * @param array<string, string> $fontPaths Font key => path, pinned or catalog.
     * @return array<string, SetField> By field key.
     */
    public function set(array $fields, array $fontPaths, Values $values): array
    {
        $set = [];
        foreach ($fields as $field) {
            $set[$field->key] = match (true) {
                $field instanceof TextField => $this->text(
                    $field,
                    self::fontPath($field->font, $fontPaths),
                    PatternResolver::resolve(PatternParser::parse($field->pattern), $values, $field->caps),
                ),
                $field instanceof SignatureSlot => $this->signature($field, self::fontPath($field->font, $fontPaths), $values),
                $field instanceof RankArtField => self::rankArt($field, $values),
            };
        }
        return $set;
    }

    private function text(TextField $field, string $font, string $text): SetField
    {
        if ($text === '') {
            return new SetField($field->key, 0.0, false, []);
        }

        [$size, $rows, $space] = $field->mode === 'wrap'
            ? $this->wrap($field, $font, $text)
            : $this->shrink($field, $font, $text);

        return new SetField(
            $field->key,
            $size,
            $field->legible_size > 0 && $size < $field->legible_size,
            $this->place($field, $font, $size, $rows, $space),
        );
    }

    private function signature(SignatureSlot $slot, string $font, Values $values): SetField
    {
        $signature = $values->signatures[$slot->key]
            ?? throw new RenderFailed("No signature pinned for slot {$slot->key}");

        $printed = $this->text(
            $slot->printedBlock(),
            $font,
            PatternResolver::normalise(implode("\n", $signature->printed_lines)),
        );

        return new SetField(
            $slot->key,
            $printed->size,
            $printed->belowLegibility,
            $printed->lines,
            ink: PlacedImage::inBox($signature->ink, $slot->x, $slot->y, $slot->w, $slot->ink_height, onBottom: true),
        );
    }

    private static function rankArt(RankArtField $field, Values $values): SetField
    {
        $file = $values->rankArt[$field->key] ?? null;

        return new SetField(
            $field->key,
            0.0,
            false,
            null,
            art: $file === null ? null : PlacedImage::inBox($file, $field->x, $field->y, $field->w, $field->h),
        );
    }

    /**
     * `line` mode. Measured widths are rounded up, so the ratio jump rounds up to
     * the grid and walks down from there: a floor could land a step short of the largest fit.
     *
     * @return array{float, list<array{string, float, bool}>, float}
     */
    private function shrink(TextField $field, string $font, string $text): array
    {
        $lines = explode("\n", $text);
        $size = $field->size;
        $jumped = false;

        while (true) {
            $widths = array_map(fn (string $line): float => $this->metrics->width($font, $size, $line), $lines);
            $widest = max($widths);
            $height = $this->blockHeight($field, $font, count($lines), $size);

            if (($widest <= $field->w && $height <= $field->h) || $size <= self::MIN_SIZE) {
                $rows = array_map(null, $lines, $widths, array_fill(0, count($lines), true));
                return [$size, $rows, 0.0];
            }

            $next = self::smaller($size);
            if (!$jumped) {
                $ratio = min($widest > 0 ? $field->w / $widest : INF, $field->h / $height);
                $next = min($next, ceil($size * $ratio / self::SIZE_STEP) * self::SIZE_STEP);
                $jumped = true;
            }
            $size = max($next, self::MIN_SIZE);
        }
    }

    /**
     * `wrap` mode. The table only searches, so the walk down the grid goes on past
     * a size whose lines the exact measure refuses.
     *
     * @return array{float, list<array{string, float, bool}>, float}
     */
    private function wrap(TextField $field, string $font, string $text): array
    {
        $whole = str_replace("\n", ' ', $text);
        $ems = $this->ems($font, $whole);
        $kerning = $ems > 0 ? $this->metrics->width($font, self::REFERENCE_SIZE, $whole) / self::REFERENCE_SIZE / $ems : 1.0;
        $spaceEm = $this->metrics->advance($font, ' ') * $kerning;

        $paragraphs = [];
        $widestEm = 0.0;
        foreach (explode("\n", $text) as $paragraph) {
            $words = [];
            foreach (explode(' ', $paragraph) as $word) {
                $em = $this->ems($font, $word) * $kerning;
                $widestEm = max($widestEm, $em);
                $words[] = [$word, $em];
            }
            $paragraphs[] = $words;
        }

        for ($size = $field->size; ; $size = self::smaller($size)) {
            $smallest = $size <= self::MIN_SIZE;
            $lines = self::breakLines($paragraphs, $spaceEm, $size, $field->w);

            if (!$smallest && (
                $widestEm * $size > $field->w
                || $this->blockHeight($field, $font, count($lines), $size) > $field->h
            )) {
                continue;
            }

            $rows = $this->measured($lines, $font, $size, $smallest ? INF : $field->w + self::OVERRUN_TOLERANCE);
            if ($rows !== null) {
                return [$size, $rows, $spaceEm * $size];
            }
        }
    }

    private static function smaller(float $size): float
    {
        return $size > self::GRID_FLOOR ? $size - self::SIZE_STEP : round($size - self::FINE_STEP, 2);
    }

    /**
     * @param list<list<array{string, float}>> $paragraphs Words with their widths in ems.
     * @return list<array{string, bool}> Line text, and whether it ends a paragraph.
     */
    private static function breakLines(array $paragraphs, float $spaceEm, float $size, float $width): array
    {
        $lines = [];
        foreach ($paragraphs as $words) {
            $current = '';
            $running = 0.0;
            foreach ($words as [$word, $em]) {
                $wordWidth = $em * $size;
                if ($current === '') {
                    $current = $word;
                    $running = $wordWidth;
                } elseif ($running + $spaceEm * $size + $wordWidth <= $width) {
                    $current .= ' ' . $word;
                    $running += $spaceEm * $size + $wordWidth;
                } else {
                    $lines[] = [$current, false];
                    $current = $word;
                    $running = $wordWidth;
                }
            }
            $lines[] = [$current, true];
        }
        return $lines;
    }

    /**
     * @param list<array{string, bool}> $lines
     * @return list<array{string, float, bool}>|null Null when a line runs past the limit.
     */
    private function measured(array $lines, string $font, float $size, float $limit): ?array
    {
        $rows = [];
        foreach ($lines as [$text, $endsParagraph]) {
            $width = $this->metrics->width($font, $size, $text);
            if ($width > $limit) {
                return null;
            }
            $rows[] = [$text, $width, $endsParagraph];
        }
        return $rows;
    }

    /**
     * @param list<array{string, float, bool}> $rows
     * @return list<Line>
     */
    private function place(TextField $field, string $font, float $size, array $rows, float $space): array
    {
        [$ascent] = $this->metrics->vertical($font);
        $top = $field->y + ($field->h - $this->blockHeight($field, $font, count($rows), $size)) / 2;

        $lines = [];
        foreach ($rows as $i => [$text, $width, $endsParagraph]) {
            $y = round($top + $i * $size * $field->leading + $size * $ascent, 2);
            $gaps = preg_match_all(self::GAP, $text);

            if ($field->align === 'justify' && !$endsParagraph && $gaps > 0) {
                $aim = $space + ($field->w - $width) / $gaps;
                $got = $this->metrics->width($font, $size, $text, $aim);
                $interword = round($aim + ($field->w - $got) / $gaps, 3);
                $final = $this->metrics->width($font, $size, $text, $interword);
                $lines[] = new Line($text, (float) $field->x, $y, $final, $interword);
                continue;
            }

            $x = match ($field->align) {
                'left' => $field->x,
                'right' => $field->x + $field->w - $width,
                default => $field->x + ($field->w - $width) / 2,
            };
            $lines[] = new Line($text, round($x, 2), $y, $width, 0.0);
        }
        return $lines;
    }

    private function blockHeight(TextField $field, string $font, int $lines, float $size): float
    {
        [$ascent, $descent] = $this->metrics->vertical($font);
        return ($lines - 1) * $size * $field->leading + $size * ($ascent - $descent);
    }

    private function ems(string $font, string $text): float
    {
        $sum = 0.0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $sum += $this->metrics->advance($font, $char);
        }
        return $sum;
    }

    /** @param array<string, string> $fontPaths */
    private static function fontPath(string $key, array $fontPaths): string
    {
        return $fontPaths[$key] ?? throw new RenderFailed("No path for font $key");
    }
}
