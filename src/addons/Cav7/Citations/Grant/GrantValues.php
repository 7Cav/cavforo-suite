<?php

namespace Cav7\Citations\Grant;

use Cav7\Citations\Render\FieldDependencies;
use Cav7\Citations\Render\ImageFile;
use Cav7\Citations\Render\PinnedSignature;
use Cav7\Citations\Render\RankSnapshot;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\Values;
use Cav7\Citations\Render\Version;

/**
 * Everything a grant says, as plain values: what a form submits at issue, what the entities
 * read back, and what a correction turns one into another. A rank held here with no
 * snapshot is a rank just picked, waiting for `snapshotted()`.
 */
final class GrantValues
{
    /**
     * @param string $date `Y-m-d`, as entered at issue.
     * @param array<string, int> $signatures Signature id by slot key.
     * @param array<string, string> $inputs Grant-scoped inputs by key.
     * @param array<int, MemberValues> $members By grant member id once stored.
     */
    public function __construct(
        public readonly string $date,
        public readonly ?string $citationText = null,
        public readonly array $signatures = [],
        public readonly array $inputs = [],
        public readonly ?int $rankId = null,
        public readonly array $members = [],
        public readonly ?RankSnapshot $rank = null,
    ) {
    }

    public function withCitationText(?string $text): self
    {
        return $this->but(['citationText' => $text]);
    }

    /** @param MemberValues $member Under `$key`, for typesetting a member not stored yet. */
    public function withMember(int $key, MemberValues $member): self
    {
        return $this->but(['members' => [$key => $member] + $this->members]);
    }

    /** What the version keeps of a form's values. A generic citation stores no text and a rank follows `rank_scope`. */
    public function fittedTo(Version $version): self
    {
        return $this->but([
            'citationText' => FieldDependencies::used($version, 'citation_text') ? $this->citationText : null,
            'signatures' => array_intersect_key($this->signatures, array_flip(self::slotKeys($version))),
            'inputs' => array_intersect_key($this->inputs, array_flip($version->inputKeys('grant'))),
            'rankId' => $version->rank_scope === 'grant' ? $this->rankId : null,
            'members' => array_map(fn (MemberValues $member): MemberValues => $member->fittedTo($version), $this->members),
        ]);
    }

    /** Stored values this version no longer uses are kept, so a later move can pick them back up. */
    public function changed(GrantChanges $changes, Version $version): self
    {
        $newRank = $version->rank_scope === 'grant' && $changes->rankId !== null && $changes->rankId !== $this->rankId;

        $members = $this->members;
        foreach ($changes->members as $id => $change) {
            if (isset($members[$id])) {
                $members[$id] = $members[$id]->changed($change, $version);
            }
        }

        return $this->but([
            'date' => $changes->date ?? $this->date,
            'citationText' => FieldDependencies::used($version, 'citation_text')
                ? $changes->citationText ?? $this->citationText
                : $this->citationText,
            'signatures' => array_intersect_key($changes->signatures, array_flip(self::slotKeys($version))) + $this->signatures,
            'inputs' => array_intersect_key($changes->inputs, array_flip($version->inputKeys('grant'))) + $this->inputs,
            'rankId' => $newRank ? $changes->rankId : $this->rankId,
            'rank' => $newRank ? null : $this->rank,
            'members' => $members,
        ]);
    }

    /**
     * Each of the target's slots fills from the pinned signature with its billet, then
     * `$extra` applies. A slot neither fills is left for the missing list.
     *
     * @param array<int, int> $billets Billet id by signature id.
     */
    public function movedTo(Version $target, GrantChanges $extra, array $billets): self
    {
        $signatures = [];
        foreach ($target->fields as $field) {
            if ($field instanceof SignatureSlot) {
                foreach ($this->signatures as $signatureId) {
                    if (($billets[$signatureId] ?? null) === $field->billet_id) {
                        $signatures[$field->key] = $signatureId;
                        break;
                    }
                }
            }
        }

        return $this->but(['signatures' => $signatures])->changed($extra, $target);
    }

    /** @param array<int, RankSnapshot> $ranks By rank id. */
    public function snapshotted(array $ranks): self
    {
        return $this->but([
            'rank' => $this->rank ?? ($this->rankId === null ? null : $ranks[$this->rankId] ?? null),
            'members' => array_map(fn (MemberValues $member): MemberValues => $member->snapshotted($ranks), $this->members),
        ]);
    }

    /** @return list<int> Rank ids still needing a snapshot. */
    public function pendingRanks(): array
    {
        $pending = [];
        foreach ([$this, ...array_values($this->members)] as $holder) {
            if ($holder->rankId !== null && $holder->rank === null) {
                $pending[] = $holder->rankId;
            }
        }
        return array_values(array_unique($pending));
    }

    /** The rank a typesetting prints for this member, or for the grant's shared fields when null. */
    public function rankIdFor(Version $version, ?MemberValues $member): ?int
    {
        return match ($version->rank_scope) {
            'member' => $member?->rankId,
            'grant' => $this->rankId,
            default => null,
        };
    }

    /**
     * @param array<string, PinnedSignature> $signatures By slot key.
     * @param array<string, ImageFile> $rankArt The printed rank's art, by field key.
     */
    public function printed(Version $version, ?MemberValues $member, array $signatures, array $rankArt): Values
    {
        return new Values(
            $member->name ?? '',
            match ($version->rank_scope) {
                'member' => $member?->rank,
                'grant' => $this->rank,
                default => null,
            },
            $this->date,
            $this->citationText ?? '',
            ($member->inputs ?? []) + $this->inputs,
            $signatures,
            $rankArt,
        );
    }

    /** @return array<string, mixed> What a correction compares, by the names field dependencies use. */
    public function facets(): array
    {
        $facets = [
            'date' => $this->date,
            'citation_text' => $this->citationText,
            'rank' => self::rankFacet($this->rankId, $this->rank),
        ];
        foreach ($this->inputs as $key => $value) {
            $facets["input:$key"] = $value;
        }
        foreach ($this->signatures as $slot => $signatureId) {
            $facets["signature:$slot"] = $signatureId;
        }
        return $facets;
    }

    /** @return array{rank_id: int, rank_title: string|null}|null */
    public static function rankFacet(?int $rankId, ?RankSnapshot $rank): ?array
    {
        return $rankId === null ? null : ['rank_id' => $rankId, 'rank_title' => $rank?->rank_title];
    }

    /** @return list<string> */
    private static function slotKeys(Version $version): array
    {
        $keys = [];
        foreach ($version->fields as $field) {
            if ($field instanceof SignatureSlot) {
                $keys[] = $field->key;
            }
        }
        return $keys;
    }

    /** @param array<string, mixed> $changes */
    private function but(array $changes): self
    {
        return new self(...$changes + get_object_vars($this));
    }
}
