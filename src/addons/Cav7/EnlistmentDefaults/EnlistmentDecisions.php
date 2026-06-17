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
     * milpac's submitted Join Date: a 'Y-m-d' field is read as midnight on that
     * calendar day in board time, so a backdated enlistment gets a record dated
     * to the real join date rather than the creation moment.
     *
     * The returned epoch is the local-midnight instant on the Join Date in the
     * board timezone.
     *
     * The creation date is the fallback, used when the Join Date is blank or
     * cannot be parsed (an imported or API-created milpac with no usable field),
     * and also when the board timezone is itself unusable (empty or an unknown
     * zone). A bad value never throws and never produces a junk date — it just
     * falls back — so the record write stays as safe as the creation-dated one
     * it replaces, and the date and timezone fallback paths are uniform.
     *
     * @param string $rawJoinDate the raw Join Date custom field ('Y-m-d', or blank)
     * @param int    $creationDate the milpac's creation timestamp, the fallback
     * @param string $timezone    the board timezone the Join Date is read in
     */
    public static function enlistmentRecordDate(string $rawJoinDate, int $creationDate, string $timezone): int
    {
        $value = trim($rawJoinDate);
        if ($value === '') {
            return $creationDate;
        }

        // An empty or unknown timezone throws in DateTimeZone; treat it the same
        // as a blank or unparseable date and fall back to the creation date, so
        // this method keeps its never-throws contract on every input.
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            return $creationDate;
        }

        // Strict 'Y-m-d' at midnight ('!' zeroes the time): createFromFormat is
        // lenient (it rolls 2012-13-45 over into the next year), so we reject any
        // value that did not round-trip back to the exact input. Anything that
        // is not a clean calendar date falls back to the creation date.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return $creationDate;
        }

        return $date->getTimestamp();
    }
}
