<?php

namespace Cav7\Citations\Grant;

use Cav7\Citations\Render\SetField;

/** A grant's set fields. Members are by grant member id once stored. */
final class Typesetting
{
    /**
     * @param array<string, SetField> $shared By field key.
     * @param array<int, array<string, SetField>> $members Each by field key.
     */
    public function __construct(
        public readonly array $shared,
        public readonly array $members,
    ) {
    }
}
