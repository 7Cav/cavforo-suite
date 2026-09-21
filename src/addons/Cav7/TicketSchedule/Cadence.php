<?php

namespace Cav7\TicketSchedule;

/**
 * How a ticket schedule repeats: a start date plus a unit. See CONTEXT.md.
 *
 * Pure PHP with no XenForo import, so tests/CadenceTest.php runs it with bare
 * php. Every date in and out is a calendar day as a Y-m-d string, with no time
 * and no zone. The cron resolves "today" in the board's timezone before it
 * calls in, so no zone handling lives here.
 */
final class Cadence
{
    public const UNIT_YEARLY = 'yearly';
    public const UNIT_MONTHLY = 'monthly';
    public const UNIT_WEEKLY = 'weekly';
    public const UNIT_DAYS = 'days';

    public const UNITS = [self::UNIT_YEARLY, self::UNIT_MONTHLY, self::UNIT_WEEKLY, self::UNIT_DAYS];

    public const MIN_EVERY_DAYS = 1;
    public const MAX_EVERY_DAYS = 366;

    private string $startDate;

    private string $unit;

    private int $everyDays;

    /**
     * @param string $startDate calendar day, Y-m-d
     * @param string $unit      one of UNITS
     * @param int    $everyDays read only for UNIT_DAYS; MIN_EVERY_DAYS to MAX_EVERY_DAYS
     *
     * @throws \InvalidArgumentException on an unknown unit, an N outside that
     *         range, or a start date that is not a real calendar day. PHP's date
     *         parser would roll 2026-02-30 to 2026-03-02 without a word, and a
     *         schedule anchored a few days off is worse than one refused.
     */
    public function __construct(string $startDate, string $unit, int $everyDays = 1)
    {
        if (!in_array($unit, self::UNITS, true)) {
            throw new \InvalidArgumentException('Unknown cadence unit: ' . $unit);
        }
        if ($everyDays < self::MIN_EVERY_DAYS || $everyDays > self::MAX_EVERY_DAYS) {
            throw new \InvalidArgumentException('Every N days must be between 1 and 366, got ' . $everyDays);
        }
        if (!self::isCalendarDay($startDate)) {
            throw new \InvalidArgumentException('Start date is not a calendar day: ' . $startDate);
        }

        $this->startDate = $startDate;
        $this->unit = $unit;
        $this->everyDays = $everyDays;
    }

    /**
     * Whether $day is a real calendar day written as Y-m-d.
     */
    public static function isCalendarDay(string $day): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * The first cadence date on or after $day, counted from the start date.
     */
    public function firstDueOnOrAfter(string $day): string
    {
        $due = $this->startDate;
        while ($due < $day) {
            $due = $this->dueAfter($due);
        }

        return $due;
    }

    /**
     * $due moved on by whole cadence steps until it is later than $day. A due
     * date already later than $day comes back unchanged. Several missed due
     * dates move in one call, so an outage yields one late ticket, not a pile.
     */
    public function advancePast(string $due, string $day): string
    {
        while ($due <= $day) {
            $due = $this->dueAfter($due);
        }

        return $due;
    }

    /**
     * The cadence date after $due.
     */
    public function dueAfter(string $due): string
    {
        $from = $this->day($due);

        switch ($this->unit) {
            case self::UNIT_YEARLY:
                return $this->onAnchorDay(
                    (int) $from->format('Y') + 1,
                    (int) $this->day($this->startDate)->format('n')
                );
            case self::UNIT_MONTHLY:
                return $this->onAnchorDay((int) $from->format('Y'), (int) $from->format('n') + 1);
            case self::UNIT_WEEKLY:
                return $from->modify('+7 days')->format('Y-m-d');
            default:
                return $from->modify('+' . $this->everyDays . ' days')->format('Y-m-d');
        }
    }

    /**
     * The start date's day of the month inside the given month, or that month's
     * last day when it has no such day. $month may run past 12; the year rolls.
     */
    private function onAnchorDay(int $year, int $month): string
    {
        $first = $this->day('2000-01-01')->setDate($year, $month, 1);
        $anchorDay = (int) $this->day($this->startDate)->format('j');
        $lastDay = (int) $first->format('t');

        return $first->setDate(
            (int) $first->format('Y'),
            (int) $first->format('n'),
            min($anchorDay, $lastDay)
        )->format('Y-m-d');
    }

    /**
     * A calendar day as a DateTimeImmutable at midnight UTC. UTC has no
     * daylight-saving change, so the day's arithmetic is the calendar's.
     */
    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('UTC'));
    }
}
