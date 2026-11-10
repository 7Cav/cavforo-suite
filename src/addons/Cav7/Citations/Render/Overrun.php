<?php

namespace Cav7\Citations\Render;

final class Overrun
{
    /** @param float $pixels How far the line ends past its box. */
    public function __construct(
        public readonly string $fieldKey,
        public readonly float $pixels,
    ) {
    }
}
