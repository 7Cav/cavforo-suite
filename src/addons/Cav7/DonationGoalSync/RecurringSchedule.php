<?php

namespace Cav7\DonationGoalSync;

/**
 * When a recurring donation goal configured to reset on the 1st of the month
 * next becomes due.
 *
 * The threshold is a property of the calendar alone — the first instant of the
 * target month — so any cron fire on the due day satisfies it. The vendor
 * derived it from the previous reset's clock time instead, seconds included,
 * which is the bug this replaces.
 *
 * The zone is fixed to UTC here, and the board's guestTimeZone is deliberately
 * not read. The vendor passes that option into a \DateTime built from a
 * '@timestamp', a form in which PHP silently ignores the timezone argument, so
 * it never reached the vendor's answer either. Honouring it would move the month
 * boundary from UTC midnight to board-local midnight and change which cycle a
 * donation near the boundary is counted in — a behaviour change on every board
 * not already on UTC, not a restoration of something that broke.
 */
class RecurringSchedule
{
    /**
     * The instant a cycle beginning at $startDate becomes due, for a goal that
     * resets on the 1st of the month every $months months.
     *
     * @param int $startDate unix timestamp the current cycle began at
     * @param int $months    length of a cycle in whole months
     *
     * @return int unix timestamp of midnight UTC on the 1st of the target month
     */
    public static function firstOfMonthThreshold(int $startDate, int $months): int
    {
        // The '@timestamp' form pins the object to +00:00 whatever the ambient
        // date_default_timezone is, and every read and write below happens in
        // the object's own zone — so no setTimezone() call is needed, and one
        // here would be a no-op. Build from a formatted string or read the date
        // parts with date() instead and the ambient zone is back in the answer;
        // that is what the two timezone rows in the tests are guarding.
        $start = new \DateTimeImmutable('@' . $startDate);

        return (int) $start
            ->setDate((int) $start->format('Y'), (int) $start->format('n') + $months, 1)
            ->setTime(0, 0, 0)
            ->format('U');
    }
}
