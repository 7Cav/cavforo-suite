<?php

namespace Cav7\Citations\Grant;

final class GrantPreview
{
    public function __construct(
        public readonly string $jpeg,
        public readonly PreviewFit $fit,
    ) {
    }
}
