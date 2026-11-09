<?php

namespace Cav7\Citations\Template;

/**
 * What a template version's state allows. A published or retired version is frozen, and
 * everything an issued citation reads from it stays as published.
 */
final class VersionLifecycle
{
    public const DRAFT = 'draft';
    public const PUBLISHED = 'published';
    public const RETIRED = 'retired';

    public const NOT_A_DRAFT = 'not_a_draft';
    public const BAD_TRANSITION = 'bad_transition';
    public const VERSION_FROZEN = 'version_frozen';

    private const TRANSITIONS = [
        self::DRAFT => [self::PUBLISHED],
        self::PUBLISHED => [self::RETIRED],
        self::RETIRED => [],
    ];

    private const UNFROZEN_COLUMNS = ['state', 'retire_date'];

    private const COPIED_TO_A_DRAFT = [
        'plate_hash', 'plate_format', 'plate_width', 'plate_height',
        'rank_scope', 'field_definitions', 'input_definitions',
    ];

    /**
     * Why this save can't happen, or null when it can.
     *
     * @param string|null $prior The stored state, null for a new row.
     * @param list<string> $changed Columns the save changes.
     */
    public static function refusal(?string $prior, string $state, array $changed): ?string
    {
        if ($prior === null) {
            return $state === self::DRAFT ? null : self::NOT_A_DRAFT;
        }
        if ($state !== $prior && !in_array($state, self::TRANSITIONS[$prior], true)) {
            return self::BAD_TRANSITION;
        }
        if ($prior !== self::DRAFT && array_diff($changed, self::UNFROZEN_COLUMNS) !== []) {
            return self::VERSION_FROZEN;
        }
        return null;
    }

    public static function isDraft(string $state): bool
    {
        return $state === self::DRAFT;
    }

    /** @param list<int> $numbers The template's version numbers. */
    public static function nextNumber(array $numbers): int
    {
        return max([0, ...$numbers]) + 1;
    }

    /**
     * What a new draft takes from the version it starts from.
     *
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function draftFrom(array $source): array
    {
        return array_intersect_key($source, array_flip(self::COPIED_TO_A_DRAFT));
    }
}
