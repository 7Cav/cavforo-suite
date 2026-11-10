<?php

namespace Cav7\Citations\Grant;

/** A correction, or the extra values a version move asks for. Null, or a key left out, means leave it alone. */
final class GrantChanges
{
    /**
     * @param string|null $date As entered, `Y-m-d`.
     * @param array<string, int> $signatures Signature id by slot key. 0 empties the slot.
     * @param array<string, string> $inputs Grant-scoped inputs by key.
     * @param array<int, MemberChanges> $members By grant member id.
     */
    public function __construct(
        public readonly ?string $date = null,
        public readonly ?string $citationText = null,
        public readonly array $signatures = [],
        public readonly array $inputs = [],
        public readonly ?int $rankId = null,
        public readonly array $members = [],
    ) {
    }

    public function withCitationText(?string $text): self
    {
        return new self($this->date, $text, $this->signatures, $this->inputs, $this->rankId, $this->members);
    }
}
