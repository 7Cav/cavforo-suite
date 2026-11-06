<?php

namespace Cav7\Citations\Render;

final class PatternError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $charOffset)
    {
        parent::__construct($message);
    }
}
