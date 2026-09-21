<?php

/**
 * Exercises Cadence, the calendar arithmetic behind a ticket schedule's due
 * dates, as a pure value class.
 *
 * Every row is an explicit input and a literal expected date from the spec on
 * issue #283. Nothing here reads the clock or recomputes the answer the way the
 * code does, so a row cannot pass by agreeing with a wrong implementation.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/CadenceTest.php
 */

namespace Cav7\TicketSchedule\Tests;

require __DIR__ . '/../Cadence.php';

use Cav7\TicketSchedule\Cadence;

// The answer is a function of the arguments alone, so the ambient zone must not
// reach it. Pin a non-UTC zone with a daylight-saving change for the whole file,
// as DonationGoalSync's RecurringScheduleTest does, so the run is reproducible
// and the seconds-arithmetic row below has a fall-back to cross.
date_default_timezone_set('America/Chicago');

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}

// ---------------------------------------------------------------------------
// Every N days.
// ---------------------------------------------------------------------------
$tenDays = new Cadence('2026-01-01', Cadence::UNIT_DAYS, 10);

check(
    'every 10 days: the due date after 2026-01-01 is 2026-01-11',
    $tenDays->dueAfter('2026-01-01') === '2026-01-11'
);

check(
    'every 10 days: the first due date on or after 2026-01-15 is 2026-01-21',
    $tenDays->firstDueOnOrAfter('2026-01-15') === '2026-01-21',
    'the cadence dates are the 1st, 11th and 21st; the 15th sits between two of them'
);

// ---------------------------------------------------------------------------
// Weekly keeps the start date's weekday.
// ---------------------------------------------------------------------------
$weekly = new Cadence('2026-09-15', Cadence::UNIT_WEEKLY);

check(
    'weekly from a Tuesday: the due date after 2026-09-15 is the next Tuesday, 2026-09-22',
    $weekly->dueAfter('2026-09-15') === '2026-09-22'
);

// ---------------------------------------------------------------------------
// Monthly keeps the start date's day of the month. A month too short for it
// uses its last day, and the month after returns to the start date's day.
// ---------------------------------------------------------------------------
$monthlyOn31st = new Cadence('2026-01-31', Cadence::UNIT_MONTHLY);

check(
    'monthly on the 31st: the due date after 2026-01-31 is 2026-02-28',
    $monthlyOn31st->dueAfter('2026-01-31') === '2026-02-28',
    'a plain "+1 month" from 31 January overflows into 3 March'
);

check(
    'monthly on the 31st: the due date after the clamped 2026-02-28 returns to 2026-03-31',
    $monthlyOn31st->dueAfter('2026-02-28') === '2026-03-31',
    'a day of month read from the previous due date instead of the start date gives 03-28'
);

// A pin against a clamp that special-cases February and keeps the start date's
// day for every other month. Under the shared clamp this row and the two above go
// red together; it is here for the rewrite that would split them.
check(
    'monthly on the 31st: a 30-day month clamps too, so the due date after 2026-03-31 is 2026-04-30',
    $monthlyOn31st->dueAfter('2026-03-31') === '2026-04-30',
    'a February-only special case rolls 31 April into 1 May'
);

// ---------------------------------------------------------------------------
// A pin against a rewrite of the days step as seconds arithmetic under the
// ambient zone: date('Y-m-d', strtotime($due) + N * 86400). The week from
// 2026-10-28 crosses the fall-back on 2026-11-01 in America/Chicago, so that
// rewrite lands 23:00 on 11-03 and reads back as the wrong day. This row is the
// reason the file pins a zone; under calendar stepping in UTC no single edit
// reddens it on its own.
// ---------------------------------------------------------------------------
$weekOfDays = new Cadence('2026-10-28', Cadence::UNIT_DAYS, 7);

check(
    'every 7 days across the fall-back: the due date after 2026-10-28 is 2026-11-04',
    $weekOfDays->dueAfter('2026-10-28') === '2026-11-04',
    '7 * 86400 seconds from local midnight on 10-28 is 23:00 on 11-03 in America/Chicago'
);

// ---------------------------------------------------------------------------
// Yearly keeps the start date's month and day. A start on 29 February lands on
// 28 February in a common year.
// ---------------------------------------------------------------------------
$yearlyOnLeapDay = new Cadence('2024-02-29', Cadence::UNIT_YEARLY);

check(
    'yearly from 29 February: the due date after 2024-02-29 is 2025-02-28',
    $yearlyOnLeapDay->dueAfter('2024-02-29') === '2025-02-28',
    'a plain "+1 year" from 29 February overflows into 1 March'
);

// ---------------------------------------------------------------------------
// The first due date on or after a day, counted from the start date.
// ---------------------------------------------------------------------------
$yearlySince2019 = new Cadence('2019-03-01', Cadence::UNIT_YEARLY);

check(
    'on-or-after is inclusive: from a past start, the first due date on or after 2027-03-01 is 2027-03-01 itself',
    $yearlySince2019->firstDueOnOrAfter('2027-03-01') === '2027-03-01',
    'a strict "after" steps past it to 2028-03-01'
);

$yearlyFrom2027 = new Cadence('2027-01-01', Cadence::UNIT_YEARLY);

check(
    'a future start date is itself the first due date on or after an earlier day',
    $yearlyFrom2027->firstDueOnOrAfter('2026-09-20') === '2027-01-01',
    'a count that begins one step past the start date gives 2028-01-01'
);

// ---------------------------------------------------------------------------
// Advancing a due date past a day. Several missed due dates move in one call,
// which is how an outage yields one late ticket rather than a pile.
// ---------------------------------------------------------------------------
$yearlySince2023 = new Cadence('2023-03-01', Cadence::UNIT_YEARLY);

check(
    'catch-up: a due date three years stale advances past 2026-09-20 to 2027-03-01 in one call',
    $yearlySince2023->advancePast('2023-03-01', '2026-09-20') === '2027-03-01',
    'a single step gives 2024-03-01'
);

$yearlyFromToday = new Cadence('2026-09-20', Cadence::UNIT_YEARLY);

check(
    'a due date already later than the day comes back unchanged',
    $yearlyFromToday->advancePast('2027-09-20', '2026-09-20') === '2027-09-20',
    'an unconditional first step gives 2028-09-20'
);

check(
    'a due date equal to the day is not later than it, so it advances',
    $yearlyFromToday->advancePast('2026-09-20', '2026-09-20') === '2027-09-20',
    'a strict "later than" comparison returns 2026-09-20 unchanged'
);

// ---------------------------------------------------------------------------
// The constructor refuses what it cannot count from. The class is asserted and
// never the message, so the wording is free to change.
// ---------------------------------------------------------------------------
function refuses(string $label, callable $build): void
{
    try {
        $build();
        check($label, false, 'no exception was thrown');
    } catch (\InvalidArgumentException $e) {
        check($label, true);
    }
}

refuses('an unknown unit is refused', fn () => new Cadence('2026-01-01', 'fortnightly'));
refuses('every 0 days is refused', fn () => new Cadence('2026-01-01', Cadence::UNIT_DAYS, 0));
refuses('every 367 days is refused', fn () => new Cadence('2026-01-01', Cadence::UNIT_DAYS, 367));
refuses(
    'a start date that is not a real calendar day is refused rather than rolled forward',
    fn () => new Cadence('2026-02-30', Cadence::UNIT_DAYS)
);

exit($failures === 0 ? 0 : 1);
