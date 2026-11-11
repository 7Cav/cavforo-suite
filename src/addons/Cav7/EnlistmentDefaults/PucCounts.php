<?php

namespace Cav7\EnlistmentDefaults;

/** What a PUC sync run wrote, per regiment grant, and the unlinked rows it removed. */
final class PucCounts
{
    /** @param array<int, array{added: int, reset: int, removed: int}> $grants By grant id, only grants with a change. */
    public function __construct(
        public array $grants = [],
        public int $unlinked = 0,
    ) {}

    /** @param 'added'|'reset'|'removed' $change */
    public function add(?int $grantId, string $change): void
    {
        if ($grantId === null) {
            $this->unlinked++;
            return;
        }
        $this->grants[$grantId] ??= ['added' => 0, 'reset' => 0, 'removed' => 0];
        $this->grants[$grantId][$change]++;
    }
}
