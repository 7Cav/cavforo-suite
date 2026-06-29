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

    /**
     * The epoch the enlistment record is dated to. The record follows the
     * milpac's submitted Join Date: a bare 'Y-m-d' calendar day, stamped at
     * midnight UTC of that day, so a backdated enlistment gets a record dated to
     * the real join date rather than the creation moment.
     *
     * The instant is midnight UTC independent of the board timezone — the same
     * convention PucSet::awardDateTimestamp() and Cav7\RosterPatch\MilpacDate
     * follow. A Join Date is a calendar day with no time of day, so no board
     * timezone is needed for the math; stamping it in UTC means RosterPatch's
     * UTC floor and rendering leave the entered day unchanged on any board
     * timezone, including one ahead of UTC (issue #45).
     *
     * The creation date is the fallback, used when the Join Date is blank or
     * cannot be parsed (an imported or API-created milpac with no usable field).
     * It too resolves to a calendar day, midnight UTC of the day the creation
     * instant falls on, not the raw wall-clock instant. A bad value never throws
     * and never produces a junk date — it just falls back — so the record write
     * stays as safe as the creation-dated one it replaces.
     *
     * @param string $rawJoinDate  the raw Join Date custom field ('Y-m-d', or blank)
     * @param int    $creationDate the milpac's creation timestamp, the fallback
     */
    public static function enlistmentRecordDate(string $rawJoinDate, int $creationDate): int
    {
        $joinTimestamp = self::midnightUtc(trim($rawJoinDate));
        if ($joinTimestamp !== null) {
            return $joinTimestamp;
        }

        // Fallback: floor the creation instant to midnight UTC of the day it
        // falls on. gmdate() always yields a real 'Y-m-d', so midnightUtc()
        // always resolves it; the coalesce guards that invariant by returning
        // the raw instant rather than ever producing a junk date.
        return self::midnightUtc(gmdate('Y-m-d', $creationDate)) ?? $creationDate;
    }

    /**
     * Parse a bare calendar day to the midnight-UTC epoch of that day, or null
     * if it is blank or not a clean 'Y-m-d'.
     *
     * Strict '!Y-m-d' in UTC ('!' zeroes the time of day): the same midnight-UTC
     * stamping convention PucSet::awardDateTimestamp() uses. On top of that, a
     * round-trip guard: createFromFormat is lenient (it rolls 2012-13-45 over
     * into the next year), so any value that does not format back to the exact
     * input is rejected rather than silently accepted. That guard mirrors
     * Cav7\RosterPatch\MilpacDate alone; PucSet has no round-trip guard and
     * throws on a bad date rather than falling back. Neither dependency is taken
     * at runtime.
     */
    private static function midnightUtc(string $day): ?int
    {
        if ($day === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $day) {
            return null;
        }

        return $date->getTimestamp();
    }
}
