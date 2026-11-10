<?php

namespace Cav7\Citations\Render;

/** Property names are the `field_definitions` JSON attributes. */
final class SignatureSlot
{
    public function __construct(
        public readonly string $key,
        public readonly int $x,
        public readonly int $y,
        public readonly int $w,
        public readonly int $h,
        public readonly int $billet_id,
        public readonly int $ink_height,
        public readonly string $font,
        public readonly float $size,
        public readonly float $legible_size,
        public readonly float $leading,
        public readonly string $colour,
    ) {
    }

    /** The printed lines' block: the box below the ink, set as one centred `line` field. */
    public function printedBlock(): TextField
    {
        return new TextField(
            $this->key,
            $this->x,
            $this->y + $this->ink_height,
            $this->w,
            $this->h - $this->ink_height,
            '',
            $this->font,
            $this->size,
            $this->legible_size,
            $this->leading,
            'line',
            'center',
            false,
            $this->colour,
        );
    }
}
