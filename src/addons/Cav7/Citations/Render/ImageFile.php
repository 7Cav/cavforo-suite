<?php

namespace Cav7\Citations\Render;

/** A stored image, known by hash and the size read at upload, so typesetting never decodes it. */
final class ImageFile
{
    public function __construct(
        public readonly string $hash,
        public readonly int $width,
        public readonly int $height,
    ) {
    }
}
