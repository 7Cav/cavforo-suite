<?php

namespace Cav7\Citations\Render;

/** Property names are the `input_definitions` JSON attributes. */
final class InputDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $scope,
        public readonly bool $required,
    ) {
    }
}
