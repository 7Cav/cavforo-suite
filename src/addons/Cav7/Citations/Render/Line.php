<?php

namespace Cav7\Citations\Render;

/** One set line, and the `lines` entry of `set_data` as it stands. */
final class Line
{
    /**
     * @param string $t Final text, casing applied.
     * @param float $x Pen position in plate pixels.
     * @param float $y Baseline in plate pixels.
     * @param float $w Width Imagick measured.
     * @param float $iw Interword spacing, 0 for none.
     */
    public function __construct(
        public readonly string $t,
        public readonly float $x,
        public readonly float $y,
        public readonly float $w,
        public readonly float $iw,
    ) {
    }
}
