<?php

namespace Cav7\Citations\Render;

/** The typesetter's only seam. */
interface FontMetrics
{
    /** Advance of one character in ems, measured at Typesetter::REFERENCE_SIZE. */
    public function advance(string $fontPath, string $char): float;

    /** Exact width of a line in pixels. */
    public function width(string $fontPath, float $size, string $text, float $interword = 0): float;

    /** @return array{float, float} Ascent and descent in ems, descent negative. */
    public function vertical(string $fontPath): array;
}
