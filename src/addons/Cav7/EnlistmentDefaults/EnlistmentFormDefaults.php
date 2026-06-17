<?php

namespace Cav7\EnlistmentDefaults;

/**
 * The pure rule behind the add-milpac form prefill: what the form starts filled
 * with for a new enlistment. Kept free of the XenForo controller and entity
 * world (the controller extension feeds it the configured ids, the roster's
 * position ids, the current time and the board timezone) so it is testable in
 * plain PHP.
 *
 * These are the form's initial state only — presentational defaults the
 * recruiter edits before saving, never written on save and never re-injected
 * after submit. The post-save application (the PUC set, the enlistment record)
 * is a separate moment and lives in EnlistmentApplier.
 */
class EnlistmentFormDefaults
{
    /**
     * Compute the add-form's initial values for a new enlistment.
     *
     * Rank passes through as configured. The position passes through only when
     * the roster actually lists it; on a roster that does not carry the
     * configured position (a memorial or past-members roster), it comes back
     * null so the form shows no invalid preselection. Join Date and Promotion
     * Date are both today in board time, since a new recruit's service time and
     * grade time both start now.
     *
     * @param int   $rankId               the configured default rank id
     * @param int   $positionId           the configured default position id
     * @param int[] $availablePositionIds the roster's available position ids
     * @param int   $now                  the current time as an epoch timestamp
     * @param string $timezone            the board timezone (e.g. 'America/New_York')
     * @return array{rank_id: int, position_id: int|null, joinDate: string, promoDate: string}
     */
    public static function compute(
        int $rankId,
        int $positionId,
        array $availablePositionIds,
        int $now,
        string $timezone
    ): array {
        $rosterHasPosition = in_array($positionId, array_map('intval', $availablePositionIds), true);

        $today = (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone($timezone))
            ->format('Y-m-d');

        return [
            'rank_id'     => $rankId,
            'position_id' => $rosterHasPosition ? $positionId : null,
            'joinDate'    => $today,
            'promoDate'   => $today,
        ];
    }
}
