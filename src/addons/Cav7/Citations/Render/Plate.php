<?php

namespace Cav7\Citations\Render;

final class Plate
{
    /** @param string $format `png` or `jpeg`, the version's `plate_format`. */
    public function __construct(
        public readonly string $path,
        public readonly string $format,
    ) {
    }
}
