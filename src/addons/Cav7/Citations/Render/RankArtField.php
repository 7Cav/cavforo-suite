<?php

namespace Cav7\Citations\Render;

/** Property names are the `field_definitions` JSON attributes. */
final class RankArtField
{
    public function __construct(
        public readonly string $key,
        public readonly int $x,
        public readonly int $y,
        public readonly int $w,
        public readonly int $h,
    ) {
    }
}
