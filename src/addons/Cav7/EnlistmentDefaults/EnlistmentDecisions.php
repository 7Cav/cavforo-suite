<?php

namespace Cav7\EnlistmentDefaults;

/**
 * The pure decision rules behind PUC automation, kept free of the XenForo
 * entities they ultimately drive so they are testable in plain PHP. The
 * XenForo-coupled execution (creating the award rows, attaching citations,
 * writing the record) lives in EnlistmentApplier, which consults these.
 */
class EnlistmentDecisions
{
    /**
     * The canonical first service record body. ~5,300 existing milpacs carry
     * this verbatim; it is a fixed line, not a template.
     */
    public const ENLISTMENT_RECORD_BODY =
        'Enlisted in the 7th Cavalry Regiment, Assigned Boot Camp';

    /**
     * Whether the automation fires for this save. It fires on a milpac INSERT
     * only: moving a member between rosters and editing a milpac are both
     * UPDATEs of an existing row and must not re-apply the set.
     */
    public static function shouldApply(bool $isInsert): bool
    {
        return $isInsert;
    }

    /**
     * The idempotency guard: of the bundled PUC dates, the ones this milpac
     * does not already carry, in earned order. Re-running with the full set
     * already present returns nothing, so a grant never duplicates.
     *
     * Matching is by calendar day (UTC), not exact timestamp: a PUC grant
     * added by hand at any time on a PUC day still suppresses the bundled one.
     *
     * @param int[] $existingAwardDates award_date timestamps already on the
     *                                  milpac for the PUC award
     * @return string[] pending dates as 'Y-m-d'
     */
    public static function pendingDates(array $existingAwardDates): array
    {
        $carriedDays = [];
        foreach ($existingAwardDates as $timestamp) {
            $carriedDays[gmdate('Y-m-d', (int) $timestamp)] = true;
        }

        $pending = [];
        foreach (PucSet::dates() as $date) {
            if (!isset($carriedDays[$date])) {
                $pending[] = $date;
            }
        }

        return $pending;
    }

    /**
     * Who a granted award is attributed to (RosterUserAward.from_user_id): the
     * acting visitor when there is a session, otherwise the configured system
     * fallback user for sessionless contexts (CLI, cron).
     */
    public static function attributionUserId(int $visitorUserId, int $systemFallbackUserId): int
    {
        return $visitorUserId > 0 ? $visitorUserId : $systemFallbackUserId;
    }
}
