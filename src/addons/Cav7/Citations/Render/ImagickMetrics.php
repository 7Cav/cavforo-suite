<?php

namespace Cav7\Citations\Render;

/**
 * Holds the width table, so share one instance across a bulk run. Assumes
 * queryFontMetrics() rounds its numbers up to whole pixels and widths scale linearly
 * with size, hence vertical() at 1000px and the 1px tolerance. Re-measure after an
 * ImageMagick or FreeType upgrade.
 */
final class ImagickMetrics implements FontMetrics
{
    private const VERTICAL_SIZE = 1000.0;

    private \Imagick $probe;

    /** @var array<string, array<string, float>> */
    private array $advances = [];

    /** @var array<string, array{float, float}> */
    private array $verticals = [];

    public function __construct()
    {
        $this->probe = new \Imagick();
        $this->probe->newImage(1, 1, 'white');
    }

    public function advance(string $fontPath, string $char): float
    {
        return $this->advances[$fontPath][$char]
            ??= $this->width($fontPath, Typesetter::REFERENCE_SIZE, $char) / Typesetter::REFERENCE_SIZE;
    }

    public function width(string $fontPath, float $size, string $text, float $interword = 0): float
    {
        return (float) $this->query($fontPath, $size, $text, $interword)['textWidth'];
    }

    public function vertical(string $fontPath): array
    {
        if (!isset($this->verticals[$fontPath])) {
            $metrics = $this->query($fontPath, self::VERTICAL_SIZE, 'Hg');
            $this->verticals[$fontPath] = [
                $metrics['ascender'] / self::VERTICAL_SIZE,
                $metrics['descender'] / self::VERTICAL_SIZE,
            ];
        }
        return $this->verticals[$fontPath];
    }

    /** @return array<string, mixed> */
    private function query(string $fontPath, float $size, string $text, float $interword = 0): array
    {
        $draw = new \ImagickDraw();
        $draw->setFont($fontPath);
        $draw->setFontSize($size);
        $draw->setTextEncoding('UTF-8');
        if ($interword != 0) {
            $draw->setTextInterwordSpacing($interword);
        }
        return $this->probe->queryFontMetrics($draw, $text, false);
    }
}
