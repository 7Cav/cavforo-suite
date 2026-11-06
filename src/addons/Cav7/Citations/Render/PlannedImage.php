<?php

namespace Cav7\Citations\Render;

final class PlannedImage
{
    public function __construct(
        public readonly string $path,
        public readonly PlacedImage $image,
    ) {
    }
}
