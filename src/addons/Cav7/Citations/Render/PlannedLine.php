<?php

namespace Cav7\Citations\Render;

final class PlannedLine
{
    public function __construct(
        public readonly string $fieldKey,
        public readonly string $fontPath,
        public readonly float $size,
        public readonly string $colour,
        public readonly Line $line,
        public readonly int $boxRight,
    ) {
    }
}
