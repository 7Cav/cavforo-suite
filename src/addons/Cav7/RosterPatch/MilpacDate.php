<?php

namespace Cav7\RosterPatch;

/**
 * The calendar-day logic behind the milpac award and service-record dates.
 *
 * An award or service record carries a calendar day with no time of day. The
 * fix this class enforces is one rule applied end to end: the day is stored as
 * midnight UTC of that day and rendered in UTC, so the day a staffer enters is
 * the day stored and the same day shows to every viewer whatever their forum
 * timezone.
 *
 * Pure, XenForo-free, so the round trip is testable in plain PHP. The
 * vendor-coupled callers stay thin: the entity extensions floor on save through
 * floorToMidnightUtc(), the controller extension rejects a bad submission with
 * parseEnteredDay() and prefills a new entry with editorTodayTimestamp().
 */
class MilpacDate
{
    /**
     * Parse a submitted calendar day to the midnight-UTC epoch of that day.
     *
     * Strict '!Y-m-d' in UTC ('!' zeroes the time of day), with the same
     * round-trip guard Cav7/EnlistmentDefaults uses: createFromFormat is lenient
     * and rolls an out-of-range value over (2026-13-40 becomes 2027-02-09), so
     * any value that does not format back to the exact input is rejected rather
     * than silently accepted. Returns null for a blank, malformed, or
     * out-of-range value so the caller can reject it instead of storing junk.
     */
    public static function parseEnteredDay(string $raw): ?int
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone('UTC')
        );

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date->getTimestamp();
    }

    /**
     * The calendar day a stored timestamp falls on, in UTC, as 'Y-m-d'. This is
     * the single day every viewer sees, and it matches the vendor's own
     * getAwardDate()/getRecordDate() getters (PHP date() under XenForo's UTC
     * default timezone). gmdate keeps the result independent of the process
     * timezone, so the test reads the same on any machine.
     */
    public static function render(int $timestamp): string
    {
        return gmdate('Y-m-d', $timestamp);
    }

    /**
     * Normalise a stored timestamp to midnight UTC of the UTC day it falls on:
     * the canonical storage form for a calendar date. Display-invariant
     * (render() is unchanged, since the UTC day does not move) and idempotent,
     * so flooring an already-canonical value, or a value written by another
     * add-on, is safe and never shifts the day shown.
     */
    public static function floorToMidnightUtc(int $timestamp): int
    {
        // render() always yields a real 'Y-m-d', which parseEnteredDay always
        // accepts, so the result is never null.
        return (int) self::parseEnteredDay(self::render($timestamp));
    }

    /**
     * The editor's own today as 'Y-m-d', read in their configured timezone, so a
     * new entry opens pre-filled with the day it is for the staffer rather than
     * UTC's today. Falls back to UTC's today when the timezone is empty or
     * unknown; never throws.
     */
    public static function editorToday(int $now, string $timezone): string
    {
        try {
            $tz = new \DateTimeZone(trim($timezone));
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }

        return (new \DateTimeImmutable('@' . $now))
            ->setTimezone($tz)
            ->format('Y-m-d');
    }

    /**
     * The midnight-UTC epoch of the editor's today: the value a new award or
     * service record defaults its date to, so the add form's date field shows
     * the editor's day. Stored midnight UTC, it renders back to that same day.
     */
    public static function editorTodayTimestamp(int $now, string $timezone): int
    {
        // editorToday() always yields a real 'Y-m-d', so the parse is never null.
        return (int) self::parseEnteredDay(self::editorToday($now, $timezone));
    }
}
