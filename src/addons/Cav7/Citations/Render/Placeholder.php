<?php

namespace Cav7\Citations\Render;

final class Placeholder
{
    /** @param string $name One of PatternParser::NAMES, or `input:<key>`. */
    public function __construct(public readonly string $name)
    {
    }
}
