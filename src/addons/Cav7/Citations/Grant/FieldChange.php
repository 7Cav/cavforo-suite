<?php

namespace Cav7\Citations\Grant;

/** One field a retypeset would change. */
final class FieldChange
{
    public const BELOW = 'below';
    public const ABOVE = 'above';

    /**
     * @param int $grantMemberId 0 for a shared field.
     * @param self::BELOW|self::ABOVE|null $crossing The way it crosses its legibility threshold, if it does.
     */
    public function __construct(
        public readonly int $grantMemberId,
        public readonly string $fieldKey,
        public readonly float $oldSize,
        public readonly float $newSize,
        public readonly int $oldLines,
        public readonly int $newLines,
        public readonly bool $breaksMoved,
        public readonly ?string $crossing,
        public readonly float $threshold,
    ) {
    }
}
