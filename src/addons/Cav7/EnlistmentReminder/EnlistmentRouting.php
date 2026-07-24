<?php

namespace Cav7\EnlistmentReminder;

/**
 * Routes an un-actioned queue thread to the Processing Clerks who own its
 * enlistment type (issue #144). It is another pure seam of the add-on — plain
 * PHP with no XenForo dependency, a twin of PositionIdList and ReminderDecision —
 * so the split of the alert audience can be exercised for real in plain PHP
 * rather than pinned by shape.
 *
 * The clerk seats split by enlistment type into two overlapping responsibility
 * sets (see CONTEXT.md's Processing Clerk term): a thread's _primary_ prefix is
 * either Standard (57 by default) or Re-Enlistment (58). Built from the four
 * parsed config lists, this exposes two things:
 *
 *   - allClerkPositionIds(): the union of both clerk-position lists. The caller
 *     resolves it once to decide whether ANY seat is held at all, and aborts the
 *     run if none is, so an unstaffed board says so once rather than once per
 *     thread per hour.
 *   - route(): for one thread's prefix id, the clerk positions to ALERT. A prefix
 *     in one type set routes to that set; a prefix in BOTH (a config error)
 *     fail-safes to the union so no responsible clerk is silently dropped; a
 *     prefix in NEITHER is unrecognized — not a valid intake thread — and the
 *     caller skips it rather than mass-alerting.
 *
 * Ids are normalised on the way in through PositionIdList::normalize, so the
 * string prefix id XF hands back from the DB and the ints PositionIdList parses
 * compare as the same id (mirroring ReminderDecision's int/string robustness).
 */
final class EnlistmentRouting
{
    /** A thread whose primary prefix is in the standard set only. */
    public const TYPE_STANDARD = 'standard';

    /** A thread whose primary prefix is in the re-enlistment set only. */
    public const TYPE_REENLIST = 'reenlist';

    /**
     * A thread whose primary prefix is in BOTH sets — a misconfiguration. It
     * routes to the union of both clerk sets as a fail-safe (story 21).
     */
    public const TYPE_BOTH = 'both';

    /**
     * A thread whose primary prefix is in neither set (or absent) — not a valid
     * enlistment. The caller skips it rather than alerting anyone (story 13).
     */
    public const TYPE_UNRECOGNIZED = 'unrecognized';

    /** @var int[] */
    private array $standardPrefixIds;

    /** @var int[] */
    private array $standardPositionIds;

    /** @var int[] */
    private array $reenlistPrefixIds;

    /** @var int[] */
    private array $reenlistPositionIds;

    /**
     * @param int[]|string[] $standardPrefixIds   prefix ids marking a Standard enlistment
     * @param int[]|string[] $standardPositionIds clerk positions alerted for a Standard
     * @param int[]|string[] $reenlistPrefixIds   prefix ids marking a Re-Enlistment
     * @param int[]|string[] $reenlistPositionIds clerk positions alerted for a Re-Enlistment
     */
    public function __construct(
        array $standardPrefixIds,
        array $standardPositionIds,
        array $reenlistPrefixIds,
        array $reenlistPositionIds
    ) {
        $this->standardPrefixIds   = PositionIdList::normalize($standardPrefixIds);
        $this->standardPositionIds = PositionIdList::normalize($standardPositionIds);
        $this->reenlistPrefixIds   = PositionIdList::normalize($reenlistPrefixIds);
        $this->reenlistPositionIds = PositionIdList::normalize($reenlistPositionIds);
    }

    /**
     * The union of both clerk-position lists, de-duplicated with first-seen order
     * preserved (Senior and Lead sit in both sets, so they collapse to one entry).
     * Two uses: it is the fail-safe audience for a prefix listed under both types,
     * and its emptiness is what aborts a run that could reach nobody at all.
     *
     * @return int[]
     */
    public function allClerkPositionIds(): array
    {
        return PositionIdList::normalize(array_merge($this->standardPositionIds, $this->reenlistPositionIds));
    }

    /**
     * Given a thread's primary prefix id, the clerk positions to alert.
     *
     * @return array{type: 'standard'|'reenlist'|'both'|'unrecognized', position_ids: int[]}
     *   type is one of the TYPE_* constants; position_ids is the set to alert —
     *   the standard set, the re-enlistment set, the union of both for a prefix in
     *   both sets, or [] when the prefix is unrecognized.
     */
    public function route(int $prefixId): array
    {
        $inStandard = in_array($prefixId, $this->standardPrefixIds, true);
        $inReenlist = in_array($prefixId, $this->reenlistPrefixIds, true);

        if ($inStandard && $inReenlist)
        {
            return ['type' => self::TYPE_BOTH, 'position_ids' => $this->allClerkPositionIds()];
        }
        if ($inStandard)
        {
            return ['type' => self::TYPE_STANDARD, 'position_ids' => $this->standardPositionIds];
        }
        if ($inReenlist)
        {
            return ['type' => self::TYPE_REENLIST, 'position_ids' => $this->reenlistPositionIds];
        }

        return ['type' => self::TYPE_UNRECOGNIZED, 'position_ids' => []];
    }

    /**
     * The prefix ids listed under BOTH type sets — a config error the caller
     * surfaces once per run. Empty in a healthy config.
     *
     * @return int[]
     */
    public function overlappingPrefixIds(): array
    {
        return array_values(array_intersect($this->standardPrefixIds, $this->reenlistPrefixIds));
    }
}
