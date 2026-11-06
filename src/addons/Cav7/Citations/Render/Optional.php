<?php

namespace Cav7\Citations\Render;

final class Optional
{
    /** @param list<Literal|Placeholder> $nodes */
    public function __construct(public readonly array $nodes)
    {
    }
}
