<?php

namespace Cav7\Citations\Render;

final class PinnedSignature
{
    /** @param list<string> $printed_lines */
    public function __construct(
        public readonly array $printed_lines,
        public readonly ImageFile $ink,
    ) {
    }
}
