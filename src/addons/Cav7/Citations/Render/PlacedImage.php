<?php

namespace Cav7\Citations\Render;

/** Ink or rank art placed on the plate, and the `ink` or `art` entry of `set_data` as it stands. */
final class PlacedImage
{
    public function __construct(
        public readonly string $hash,
        public readonly int $x,
        public readonly int $y,
        public readonly int $w,
        public readonly int $h,
    ) {
    }

    /** Scales down to fit the box, never up, and centres. `$onBottom` sits it on the box's bottom edge instead. */
    public static function inBox(ImageFile $file, int $x, int $y, int $w, int $h, bool $onBottom = false): self
    {
        $scale = min(1, $w / $file->width, $h / $file->height);
        $width = (int) round($file->width * $scale);
        $height = (int) round($file->height * $scale);

        return new self(
            $file->hash,
            $x + (int) round(($w - $width) / 2),
            $onBottom ? $y + $h - $height : $y + (int) round(($h - $height) / 2),
            $width,
            $height,
        );
    }
}
