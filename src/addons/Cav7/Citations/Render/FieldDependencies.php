<?php

namespace Cav7\Citations\Render;

/**
 * What a field reads, and so which row it lives on and what remakes it. A value
 * is `name`, `rank`, `date`, `citation_text`, `input:<key>` or `signature:<slot key>`.
 */
final class FieldDependencies
{
    private const PLACEHOLDER_VALUES = [
        'name' => 'name',
        'rank' => 'rank',
        'pay_grade' => 'rank',
        'rank_abbr' => 'rank',
        'day' => 'date',
        'day_ordinal' => 'date',
        'month' => 'date',
        'year' => 'date',
        'citation_text' => 'citation_text',
    ];

    /**
     * @param list<string> $uses
     * @param bool $member True for a row per member, false for one shared row.
     */
    public function __construct(
        public readonly array $uses,
        public readonly bool $member,
    ) {
    }

    public static function of(TextField|SignatureSlot|RankArtField $field, Version $version): self
    {
        if ($field instanceof SignatureSlot) {
            return new self(["signature:{$field->key}"], false);
        }

        $rankIsMember = $version->rank_scope === 'member';
        if ($field instanceof RankArtField) {
            return new self(['rank'], $rankIsMember);
        }

        $uses = [];
        foreach (PatternParser::placeholders($field->pattern) as $name) {
            $uses[] = self::PLACEHOLDER_VALUES[$name] ?? $name;
        }
        $uses = array_values(array_unique($uses));

        $member = false;
        foreach ($uses as $value) {
            $member = $member
                || $value === 'name'
                || ($value === 'rank' && $rankIsMember)
                || (str_starts_with($value, 'input:') && $version->isMemberInput(substr($value, 6)));
        }

        return new self($uses, $member);
    }

    /**
     * The fields a correction remakes. Whether a member-row field is remade for
     * one member or all of them follows from whose value changed, so the caller
     * decides that: a name fix is one member, a shared value is every member.
     *
     * @param list<string> $changed
     * @return list<TextField|SignatureSlot|RankArtField>
     */
    public static function remadeBy(Version $version, array $changed): array
    {
        return array_values(array_filter(
            $version->fields,
            fn (object $field): bool => array_intersect(self::of($field, $version)->uses, $changed) !== []
        ));
    }

    /** Whether anything on the version prints this value. */
    public static function used(Version $version, string $value): bool
    {
        return self::remadeBy($version, [$value]) !== [];
    }

    /** @return list<TextField|SignatureSlot|RankArtField> */
    public static function memberFields(Version $version): array
    {
        return array_values(array_filter($version->fields, fn (object $field): bool => self::of($field, $version)->member));
    }

    /** @return list<TextField|SignatureSlot|RankArtField> */
    public static function sharedFields(Version $version): array
    {
        return array_values(array_filter($version->fields, fn (object $field): bool => !self::of($field, $version)->member));
    }
}
