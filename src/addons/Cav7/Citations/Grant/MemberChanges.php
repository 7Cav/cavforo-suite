<?php

namespace Cav7\Citations\Grant;

/** A correction to one member. Null, or a key left out, means leave it alone. */
final class MemberChanges
{
    /** @param array<string, string> $inputs */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?int $rankId = null,
        public readonly array $inputs = [],
    ) {
    }
}
