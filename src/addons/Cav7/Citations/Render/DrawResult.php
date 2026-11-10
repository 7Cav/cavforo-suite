<?php

namespace Cav7\Citations\Render;

final class DrawResult
{
    public function __construct(
        public readonly string $jpeg,
        public readonly ?Overrun $overrun,
    ) {
    }
}
