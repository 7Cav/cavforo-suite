<?php

/**
 * Exercises RecurringSchedule, the reset-threshold arithmetic this addon
 * substitutes for the vendor's, as a pure function.
 *
 * What it pins, and what it does not: the vendor's own seconds-preserving body
 * is never executed by this suite — it lives in a file we do not ship and
 * cannot load without a XenForo install. These rows are a specification pin on
 * the arithmetic that replaces it, not a red-to-green defect slice. The defect
 * itself was confirmed by mutation control against a live dev stack.
 *
 * Expected values are literals parsed by PHP's own date parser from an explicit
 * UTC string, which is a different route to the answer than the year/month
 * arithmetic under test — so a row cannot pass by recomputing the code.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/RecurringScheduleTest.php
 */

namespace Cav7\DonationGoalSync\Tests;

require __DIR__ . '/../RecurringSchedule.php';

use Cav7\DonationGoalSync\RecurringSchedule;

// The threshold is a function of its arguments alone, so the ambient zone must
// not reach the answer. Pin it to a non-UTC zone for the whole file rather than
// inherit whatever `date.timezone` this machine has: that keeps the run
// reproducible, and an implementation that resolved the zone ambiently would
// already be wrong in the rows below rather than only in the two that probe it.
date_default_timezone_set('America/Chicago');

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

/** Parse an explicit-UTC wall-clock string to a timestamp. */
function at(string $utc): int
{
    return (int) strtotime($utc . ' UTC');
}

// ---------------------------------------------------------------------------
// The bug. A cycle that began at 00:30:43 must become due at midnight on the
// 1st, not at 00:30:43 on the 1st. Any inherited time of day leaves a window
// in which the cron has fired but the goal is not yet due, and whether the
// reset happens at all comes down to the second the cron happened to run.
// ---------------------------------------------------------------------------
check(
    'a cycle starting at 00:30:43 becomes due at midnight, not at 00:30:43',
    RecurringSchedule::firstOfMonthThreshold(at('2026-07-02 00:30:43'), 1)
        === at('2026-08-01 00:00:00'),
    'this is the production state of goal 5; an inherited time of day is the defect'
);

// ---------------------------------------------------------------------------
// The overflow the vendor's "+N months" walks into. PHP resolves 31 January
// plus one month as 3 March, because February has no 31st and the surplus days
// carry into the next month. Snapping that to the 1st lands in March, so
// February never becomes due and the goal skips a whole cycle. Asking for the
// 1st of the month N months on has no day to overflow.
// ---------------------------------------------------------------------------
foreach ([
    '2026-01-29' => 'a 29th, past the end of February',
    '2026-01-30' => 'a 30th',
    '2026-01-31' => 'a 31st, the furthest overflow',
] as $day => $why) {
    check(
        "a cycle starting $day becomes due on 1 February ($why)",
        RecurringSchedule::firstOfMonthThreshold(at($day . ' 00:30:43'), 1)
            === at('2026-02-01 00:00:00'),
        '"+1 months" from here lands in March, skipping February entirely'
    );
}

// A cycle length that carries past December has to roll the year, not produce
// a thirteenth month.
check(
    'a cycle length crossing the year boundary rolls into the next year',
    RecurringSchedule::firstOfMonthThreshold(at('2026-11-15 09:00:00'), 3)
        === at('2027-02-01 00:00:00'),
    'November plus three months is February of the following year'
);

// ---------------------------------------------------------------------------
// The ambient zone must not reach the answer.
//
// Two rows, not one, and the zone alone is not what discriminates them. A cycle
// start in the middle of a month survives any ambient shift, because ±14 hours
// does not change which month it falls in — so a mid-month input would go green
// under both zones even with the zone bug present. Each start below sits within
// a few hours of a month boundary instead, in opposite directions, so an
// implementation that read the month ambiently while still emitting UTC
// midnight lands on the wrong month under exactly one of the two.
// ---------------------------------------------------------------------------
function underZone(string $zone, callable $fn): void
{
    $was = date_default_timezone_get();
    date_default_timezone_set($zone);
    try {
        $fn();
    } finally {
        date_default_timezone_set($was);
    }
}

// UTC-5. Ambient reads this start as 30 June, a month early.
underZone('America/New_York', function () {
    check(
        'a start just after a month boundary is unmoved by a behind-UTC ambient zone',
        RecurringSchedule::firstOfMonthThreshold(at('2026-07-01 02:00:00'), 1)
            === at('2026-08-01 00:00:00'),
        'America/New_York places this start in June, which would make it due a month early'
    );
});

// UTC+14. Ambient reads this start as 1 August, a month late.
underZone('Pacific/Kiritimati', function () {
    check(
        'a start just before a month boundary is unmoved by an ahead-of-UTC ambient zone',
        RecurringSchedule::firstOfMonthThreshold(at('2026-07-31 22:00:00'), 1)
            === at('2026-08-01 00:00:00'),
        'Pacific/Kiritimati places this start in August, which would make it due a month late'
    );
});

exit($failures === 0 ? 0 : 1);
