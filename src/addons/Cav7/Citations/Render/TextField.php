<?php

namespace Cav7\Citations\Render;

/** Property names are the `field_definitions` JSON attributes. */
final class TextField
{
    public function __construct(
        public readonly string $key,
        public readonly int $x,
        public readonly int $y,
        public readonly int $w,
        public readonly int $h,
        public readonly string $pattern,
        public readonly string $font,
        public readonly float $size,
        public readonly float $legible_size,
        public readonly float $leading,
        public readonly string $mode,
        public readonly string $align,
        public readonly bool $caps,
        public readonly string $colour,
    ) {
    }
}
