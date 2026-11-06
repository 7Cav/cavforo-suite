<?php

namespace Cav7\Citations\Grant;

use Cav7\Citations\Render\RankSnapshot;
use Cav7\Citations\Render\Version;

/** What a grant holds for one milpac. Issuing and adding take this, with `rank` left for the snapshot to fill. */
final class MemberValues
{
    /** @param array<string, string> $inputs Member-scoped inputs by key. */
    public function __construct(
        public readonly int $relationId,
        public readonly string $name = '',
        public readonly ?int $rankId = null,
        public readonly array $inputs = [],
        public readonly ?RankSnapshot $rank = null,
    ) {
    }

    /** What the version keeps of a form's values: only its own member inputs, and a rank only if the rank is the member's. */
    public function fittedTo(Version $version): self
    {
        return new self(
            $this->relationId,
            $this->name,
            $version->rank_scope === 'member' ? $this->rankId : null,
            array_intersect_key($this->inputs, array_flip($version->inputKeys('member'))),
            $version->rank_scope === 'member' ? $this->rank : null,
        );
    }

    public function changed(MemberChanges $changes, Version $version): self
    {
        $newRank = $version->rank_scope === 'member' && $changes->rankId !== null && $changes->rankId !== $this->rankId;

        return new self(
            $this->relationId,
            $changes->name ?? $this->name,
            $newRank ? $changes->rankId : $this->rankId,
            array_intersect_key($changes->inputs, array_flip($version->inputKeys('member'))) + $this->inputs,
            $newRank ? null : $this->rank,
        );
    }

    /** @param array<int, RankSnapshot> $ranks By rank id. A held snapshot stays, so a rename never reaches it. */
    public function snapshotted(array $ranks): self
    {
        return new self(
            $this->relationId,
            $this->name,
            $this->rankId,
            $this->inputs,
            $this->rank ?? ($this->rankId === null ? null : $ranks[$this->rankId] ?? null),
        );
    }

    /** @return array<string, mixed> What a correction compares, by the names field dependencies use. */
    public function facets(): array
    {
        $facets = ['name' => $this->name, 'rank' => GrantValues::rankFacet($this->rankId, $this->rank)];
        foreach ($this->inputs as $key => $value) {
            $facets["input:$key"] = $value;
        }
        return $facets;
    }
}
