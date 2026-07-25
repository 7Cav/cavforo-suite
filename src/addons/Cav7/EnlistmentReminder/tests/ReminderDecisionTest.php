<?php

/**
 * Issue #75, reworked by issue #186 — the pure decision behind the
 * enlistment-queue reminder, isolated from XenForo so every branch of "is this
 * application un-actioned and not yet reminded?" is covered without booting the
 * framework. The queue query, the prefix-link read, the clerk-seat resolution,
 * the bot post and the marker write are XenForo-coupled and pinned by
 * ScanWiringTest instead; this file exercises the rule itself.
 *
 * Rules under test (Cav7\EnlistmentReminder\ReminderDecision):
 *
 *  - the deadline boundary: an application is reminded only once its age since
 *    the OP EXCEEDS the deadline, so a thread exactly at the deadline, or
 *    younger, is left alone.
 *  - the status-prefix suppression: a thread carrying one of the configured
 *    in-processing prefixes is being worked, so it is never reminded however old
 *    it is. Since #186 this is the ONLY "handled" signal — who replied, and
 *    whether that person still holds a clerk seat, no longer enters the rule, so
 *    a clerk rotating out of RRD cannot undo a pickup that already happened.
 *  - the already-reminded guard: a thread recorded in the marker table is never
 *    reminded a second time, however un-actioned it still is.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ReminderDecisionTest.php
 */

namespace Cav7\EnlistmentReminder\Tests;

require __DIR__ . '/../ReminderDecision.php';

use Cav7\EnlistmentReminder\ReminderDecision;

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

// A fixed clock and a 24-hour deadline expressed in seconds, so the boundary is
// an exact epoch difference rather than anything the code recomputes.
$now      = 1_000_000_000;
$deadline = 24 * 3600; // 86400

// Age is deliberately far past the deadline (ten days) wherever the boundary is
// not the thing under test, so nothing but the fact under test can suppress.
$tenDaysOld = $now - (10 * 86400);

// --- the deadline boundary ------------------------------------------------
check(
    'a thread one second past the deadline with no processing status is reminded',
    ReminderDecision::shouldRemind($now, $deadline, $now - $deadline - 1, false, false) === true
);
check(
    'a thread exactly at the deadline is not reminded (age must exceed, not equal)',
    ReminderDecision::shouldRemind($now, $deadline, $now - $deadline, false, false) === false,
    'the OP is deadline seconds old; equal is not past'
);
check(
    'a thread younger than the deadline is not reminded',
    ReminderDecision::shouldRemind($now, $deadline, $now - 3600, false, false) === false
);

// --- the status-prefix suppression ----------------------------------------
check(
    'a thread carrying an in-processing prefix is never reminded, however old',
    ReminderDecision::shouldRemind($now, $deadline, $tenDaysOld, true, false) === false,
    'a processing status means the application is being worked'
);
check(
    'a thread with no processing status is reminded past the deadline',
    ReminderDecision::shouldRemind($now, $deadline, $tenDaysOld, false, false) === true
);
// Acceptance criterion 4, and the #186 regression in one line: the reminder that
// landed on thread 100131 fired because the clerk who had marked it In Progress
// left the seat. Reply authorship cannot be recomputed away once it is not an
// input at all, so the pin is the signature itself. A behavioural check cannot
// express this: the parameter it would have to vary no longer exists, which is
// exactly the point. The scanner's matching fact array is pinned in
// ScanWiringTest.
$shouldRemindParams = array_map(
    fn (\ReflectionParameter $p) => $p->getName(),
    (new \ReflectionMethod(ReminderDecision::class, 'shouldRemind'))->getParameters()
);
check(
    'shouldRemind takes exactly the five current facts, with nothing about who replied',
    $shouldRemindParams === ['now', 'deadlineSeconds', 'opTimestamp', 'inProcessing', 'alreadyReminded'],
    'got: ' . implode(', ', $shouldRemindParams)
);

// --- the already-reminded guard -------------------------------------------
check(
    'a thread already recorded in the marker table is not reminded again',
    ReminderDecision::shouldRemind($now, $deadline, $tenDaysOld, false, true) === false
);
check(
    'the marker wins even when the thread also carries a processing status',
    ReminderDecision::shouldRemind($now, $deadline, $tenDaysOld, true, true) === false
);

// --- the batch selector ----------------------------------------------------
// One thread of each kind; only the two genuinely un-actioned, un-reminded ones
// come back, in INPUT order, as their thread ids. The higher remindable id (105)
// is placed before the lower one (101) on purpose, so the expected [105, 101]
// only holds if input order is preserved — a stray sort or reverse would fail.
$threads = [
    // reminded: old, no processing status, not yet reminded — HIGHER id, first
    ['thread_id' => 105, 'op_timestamp' => $now - $deadline - 1, 'in_processing' => false, 'already_reminded' => false],
    // skipped: carries a processing status prefix
    ['thread_id' => 102, 'op_timestamp' => $tenDaysOld, 'in_processing' => true,  'already_reminded' => false],
    // skipped: too young
    ['thread_id' => 103, 'op_timestamp' => $now - 3600,  'in_processing' => false, 'already_reminded' => false],
    // skipped: already reminded
    ['thread_id' => 104, 'op_timestamp' => $tenDaysOld, 'in_processing' => false, 'already_reminded' => true],
    // reminded: old, no processing status, not yet reminded — LOWER id, after
    ['thread_id' => 101, 'op_timestamp' => $tenDaysOld, 'in_processing' => false, 'already_reminded' => false],
];
check(
    'selectThreadsToRemind returns exactly the un-actioned, un-reminded thread ids in input order',
    ReminderDecision::selectThreadsToRemind($now, $deadline, $threads) === [105, 101],
    'got: ' . implode(', ', ReminderDecision::selectThreadsToRemind($now, $deadline, $threads))
);
check(
    'an empty queue yields nothing to remind',
    ReminderDecision::selectThreadsToRemind($now, $deadline, []) === []
);
// A thread whose in_processing fact is missing entirely must default to "no
// status", i.e. remindable. The opposite default would silently suppress the
// whole queue if the caller ever stopped supplying the fact — the same class of
// quiet failure as the type-prefix trap.
check(
    'a missing in_processing fact reads as no processing status, not as suppressed',
    ReminderDecision::selectThreadsToRemind($now, $deadline, [
        ['thread_id' => 201, 'op_timestamp' => $tenDaysOld, 'already_reminded' => false],
    ]) === [201]
);

// --- a non-positive deadline is refused ------------------------------------
// Zero or negative puts every open application past the deadline at once, i.e.
// remind the whole queue. The cron entry clamps the option to a one-hour floor,
// but that clamp is two classes away and this is the seam that decides, so it
// refuses the value itself rather than trusting the caller.
foreach ([0, -1, -86400] as $badDeadline) {
    $refused = false;
    try {
        ReminderDecision::shouldRemind($now, $badDeadline, $tenDaysOld, false, false);
    } catch (\InvalidArgumentException $e) {
        $refused = true;
    }
    check("shouldRemind refuses a deadline of $badDeadline", $refused);
}
// The batch entry checks it too, so an empty queue cannot carry a bad deadline
// past unremarked just because no thread ever reaches shouldRemind.
$refusedBatch = false;
try {
    ReminderDecision::selectThreadsToRemind($now, 0, []);
} catch (\InvalidArgumentException $e) {
    $refusedBatch = true;
}
check(
    'selectThreadsToRemind refuses a non-positive deadline even with nothing to scan',
    $refusedBatch,
    'an empty batch never calls shouldRemind, so the batch entry has to check for itself'
);
// One second is a legal, if silly, deadline: the refusal is about non-positive
// values, not about sanity, which is the cron entry's clamp.
check(
    'a one-second deadline is accepted',
    ReminderDecision::shouldRemind($now, 1, $now - 2, false, false) === true
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
