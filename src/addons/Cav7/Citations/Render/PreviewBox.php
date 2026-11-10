<?php

namespace Cav7\Citations\Render;

/** A labelled outline a grant form's preview draws where a field is still empty. A real render has none. */
final class PreviewBox
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $w,
        public readonly int $h,
        public readonly string $label,
    ) {
    }
}
