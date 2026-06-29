<?php

/**
 * Behavioural unit test for MilpacDate — the calendar-day logic behind the
 * award/service-record date fix (issue #43). It is pure PHP with no XenForo
 * dependency, so it can be exercised for real here: the entered day must survive
 * the round trip unchanged and read the same to every viewer regardless of
 * timezone.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/MilpacDateTest.php
 */

namespace Cav7\RosterPatch\Tests;

require __DIR__ . '/../MilpacDate.php';

use Cav7\RosterPatch\MilpacDate;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/** Midnight-UTC epoch for a 'Y-m-d', computed independently of MilpacDate. */
function midnightUtc(string $ymd): int
{
    return \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone('UTC'))
        ->getTimestamp();
}

// --- parseEnteredDay(): a submitted day becomes midnight UTC of that day -----
check(
    'a valid Y-m-d parses to midnight UTC of that calendar day',
    MilpacDate::parseEnteredDay('2026-06-29') === midnightUtc('2026-06-29'),
    'got: ' . var_export(MilpacDate::parseEnteredDay('2026-06-29'), true)
);
check(
    'the parsed value carries no time of day (it is exactly midnight UTC)',
    MilpacDate::parseEnteredDay('2026-06-29') % 86400 === 0
);

// --- parseEnteredDay(): bad values are rejected, never rolled over ----------
check(
    'a blank value is rejected (null)',
    MilpacDate::parseEnteredDay('') === null
);
check(
    'a whitespace-only value is rejected (null)',
    MilpacDate::parseEnteredDay('   ') === null
);
check(
    'a non-date string is rejected (null)',
    MilpacDate::parseEnteredDay('not-a-date') === null
);
check(
    'an out-of-range date is rejected, not silently rolled over (2026-13-40)',
    MilpacDate::parseEnteredDay('2026-13-40') === null,
    'got: ' . var_export(MilpacDate::parseEnteredDay('2026-13-40'), true)
);
check(
    'a real leap day is accepted (2024-02-29)',
    MilpacDate::parseEnteredDay('2024-02-29') === midnightUtc('2024-02-29')
);
check(
    'a non-existent leap day is rejected (2025-02-29)',
    MilpacDate::parseEnteredDay('2025-02-29') === null
);

// --- render(): the day reads the same for every viewer (UTC) ----------------
check(
    'render() returns the UTC calendar day of a timestamp',
    MilpacDate::render(midnightUtc('2026-06-29')) === '2026-06-29'
);
// The time of day cannot move the rendered day: any instant within a UTC day
// renders as that day, which is why a stored midnight is not required for the
// day to read correctly — but it is the canonical form.
check(
    'render() ignores the time of day within a UTC day',
    MilpacDate::render(midnightUtc('2026-06-29') + 23 * 3600 + 59 * 60) === '2026-06-29'
);

// --- The round trip: the entered day survives unchanged ----------------------
// This is the heart of the fix and is timezone-independent: parse stores
// midnight UTC, render reads it back as the same calendar day for everyone.
foreach (['2003-03-18', '2021-05-16', '2024-02-29', '2026-06-29'] as $day) {
    check(
        "round trip is stable for $day (enter -> store -> render)",
        MilpacDate::render(MilpacDate::parseEnteredDay($day)) === $day
    );
}

// A staffer behind UTC and a staffer ahead of UTC both add an entry for the day
// their form shows. Whatever their timezone, the day they pick is parsed to
// midnight UTC and renders back as that exact day to every viewer.
$behindUtcToday = MilpacDate::editorToday(midnightUtc('2026-06-29') + 2 * 3600, 'America/New_York');
check(
    'a staffer behind UTC: the day they pick renders back unchanged',
    MilpacDate::render(MilpacDate::parseEnteredDay($behindUtcToday)) === $behindUtcToday,
    "behind-UTC day was $behindUtcToday"
);
$aheadUtcToday = MilpacDate::editorToday(midnightUtc('2026-06-29') + 22 * 3600, 'Australia/Sydney');
check(
    'a staffer ahead of UTC: the day they pick renders back unchanged',
    MilpacDate::render(MilpacDate::parseEnteredDay($aheadUtcToday)) === $aheadUtcToday,
    "ahead-of-UTC day was $aheadUtcToday"
);

// --- floorToMidnightUtc(): canonical storage, display-invariant -------------
check(
    'a mid-day timestamp floors to midnight UTC of the same UTC day',
    MilpacDate::floorToMidnightUtc(midnightUtc('2026-06-29') + 14 * 3600) === midnightUtc('2026-06-29')
);
check(
    'flooring is idempotent',
    MilpacDate::floorToMidnightUtc(MilpacDate::floorToMidnightUtc(midnightUtc('2026-06-29') + 14 * 3600))
        === midnightUtc('2026-06-29')
);
check(
    'flooring an already-midnight value is a no-op',
    MilpacDate::floorToMidnightUtc(midnightUtc('2010-09-18')) === midnightUtc('2010-09-18')
);
// Display-invariance: flooring never changes the day a value renders as, for any
// instant in the day. This is why flooring values written by other add-ons
// (e.g. Cav7/EnlistmentDefaults' midnight-board-tz records) cannot shift the day
// shown — it only normalises the time of day within the same UTC day.
foreach ([0, 3600, 12 * 3600, 23 * 3600 + 1800] as $offset) {
    $ts = midnightUtc('2012-05-18') + $offset;
    check(
        "flooring preserves the rendered day at offset {$offset}s",
        MilpacDate::render(MilpacDate::floorToMidnightUtc($ts)) === MilpacDate::render($ts)
    );
}

// --- editorToday(): the editor's own today, in their timezone ---------------
// 02:00 UTC on 2026-06-29 is still 2026-06-28 in New York (UTC-4, behind UTC).
check(
    'an editor behind UTC sees their own (earlier) calendar day',
    MilpacDate::editorToday(midnightUtc('2026-06-29') + 2 * 3600, 'America/New_York') === '2026-06-28'
);
// 22:00 UTC on 2026-06-29 is already 2026-06-30 in Sydney (UTC+10, ahead of UTC).
check(
    'an editor ahead of UTC sees their own (later) calendar day',
    MilpacDate::editorToday(midnightUtc('2026-06-29') + 22 * 3600, 'Australia/Sydney') === '2026-06-30'
);
check(
    'an editor in UTC sees the UTC day',
    MilpacDate::editorToday(midnightUtc('2026-06-29') + 2 * 3600, 'UTC') === '2026-06-29'
);
check(
    'an empty timezone falls back to UTC today (never throws)',
    MilpacDate::editorToday(midnightUtc('2026-06-29') + 2 * 3600, '') === '2026-06-29'
);
check(
    'an unknown timezone falls back to UTC today (never throws)',
    MilpacDate::editorToday(midnightUtc('2026-06-29') + 2 * 3600, 'Not/AZone') === '2026-06-29'
);

// --- editorTodayTimestamp(): midnight UTC of the editor's today -------------
// The prefill default a new entry's date carries. It must render back as the
// editor's day, so the add form opens on the staffer's today, not UTC's.
$nyNow = midnightUtc('2026-06-29') + 2 * 3600;
check(
    "the new-entry default renders as the editor's own today (behind UTC)",
    MilpacDate::render(MilpacDate::editorTodayTimestamp($nyNow, 'America/New_York')) === '2026-06-28'
);
$sydNow = midnightUtc('2026-06-29') + 22 * 3600;
check(
    "the new-entry default renders as the editor's own today (ahead of UTC)",
    MilpacDate::render(MilpacDate::editorTodayTimestamp($sydNow, 'Australia/Sydney')) === '2026-06-30'
);
check(
    'the new-entry default is exactly midnight UTC',
    MilpacDate::editorTodayTimestamp($nyNow, 'America/New_York') === midnightUtc('2026-06-28')
);

// --- editorToday(): the local-midnight day-flip boundary --------------------
// America/New_York is EDT (UTC-4) in June, so its local day rolls over at
// 04:00 UTC. One second before, the editor is still on the prior day; at the
// instant itself they are on the new day.
check(
    'one second before the local-midnight rollover is still the prior day (EDT)',
    MilpacDate::editorToday(midnightUtc('2026-06-29') + 4 * 3600 - 1, 'America/New_York') === '2026-06-28'
);
check(
    'at the local-midnight rollover the editor is on the new day (EDT)',
    MilpacDate::editorToday(midnightUtc('2026-06-29') + 4 * 3600, 'America/New_York') === '2026-06-29'
);

// --- editorToday(): the far ends of the offset range ------------------------
// Kiritimati is UTC+14 (the furthest ahead), Pago Pago UTC-11 (the furthest
// behind), so the same instant lands on three different calendar days.
check(
    'an editor at UTC+14 sees the next day (Pacific/Kiritimati)',
    MilpacDate::editorToday(midnightUtc('2026-06-29') + 10 * 3600, 'Pacific/Kiritimati') === '2026-06-30'
);
check(
    'an editor at UTC-11 sees the previous day (Pacific/Pago_Pago)',
    MilpacDate::editorToday(midnightUtc('2026-06-29') + 10 * 3600, 'Pacific/Pago_Pago') === '2026-06-28'
);
// The prefill default round-trips at the extreme ahead-of-UTC offset too: the
// editor's day is stored midnight UTC and renders back as that same day.
check(
    "the new-entry default renders as the editor's own today at UTC+14",
    MilpacDate::render(
        MilpacDate::editorTodayTimestamp(midnightUtc('2026-06-29') + 10 * 3600, 'Pacific/Kiritimati')
    ) === '2026-06-30'
);

// --- editorToday(): the offset follows the season (DST) ---------------------
// Sydney is AEDT (UTC+11) in its January summer, a different offset from the
// June AEST (UTC+10) case above. 13:30 UTC sits in the one hour where the two
// offsets disagree on the local day: +11 rolls it to 2026-01-16, +10 keeps it
// on 2026-01-15. So this fails if the day-flip math reads a fixed offset
// instead of the seasonal one.
check(
    'an editor in summer DST sees the offset for that season (Australia/Sydney, AEDT)',
    MilpacDate::editorToday(midnightUtc('2026-01-15') + 13 * 3600 + 1800, 'Australia/Sydney') === '2026-01-16'
);

// --- parseEnteredDay(): trimming and strict formatting ----------------------
check(
    'surrounding whitespace is trimmed before parsing',
    MilpacDate::parseEnteredDay(' 2026-06-29 ') === midnightUtc('2026-06-29')
);
check(
    'a non-zero-padded day is rejected (2026-6-9 does not round-trip)',
    MilpacDate::parseEnteredDay('2026-6-9') === null,
    'got: ' . var_export(MilpacDate::parseEnteredDay('2026-6-9'), true)
);

// --- parseEnteredDay(): the century leap rule -------------------------------
// 1900 is divisible by 100 but not 400, so it is not a leap year; 2000 is
// divisible by 400, so it is.
check(
    'a century non-leap-year Feb 29 is rejected (1900-02-29)',
    MilpacDate::parseEnteredDay('1900-02-29') === null,
    'got: ' . var_export(MilpacDate::parseEnteredDay('1900-02-29'), true)
);
check(
    'a 400-year leap day is accepted (2000-02-29)',
    MilpacDate::parseEnteredDay('2000-02-29') === midnightUtc('2000-02-29')
);

// --- Summary --------------------------------------------------------------
if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
