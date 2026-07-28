<?php

/**
 * Issue #75, reworked by issue #186 — the pure decision behind the
 * enlistment-queue reminder, isolated from XenForo so every branch of "is this
 * application un-actioned and not yet reminded?" is covered without booting the
 * framework. The queue query, the prefix-link read, the clerk-seat resolution,
 * the bot post and the marker write are XenForo-coupled: they are verified on the
 * dev stack, not in CI. This file exercises the rule itself.
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

require __DIR__ . '/../PositionIdList.php';
require __DIR__ . '/../ProcessingStatus.php';
require __DIR__ . '/../ReminderDecision.php';

use Cav7\EnlistmentReminder\ProcessingStatus;
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

/**
 * The processing status of the queue this file decides over, built the only way
 * it can be — see ProcessingStatus. Every thread here carries its Enlistment type
 * prefix (57), because every real queue thread does; thread 102 also carries In
 * Progress (55) and is the one a clerk has taken on.
 */
$processing = ProcessingStatus::fromPrefixLinks(
    [
        ['thread_id' => 101, 'prefix_id' => 57],
        ['thread_id' => 102, 'prefix_id' => 57],
        ['thread_id' => 102, 'prefix_id' => 55],
        ['thread_id' => 103, 'prefix_id' => 57],
        ['thread_id' => 104, 'prefix_id' => 57],
        ['thread_id' => 105, 'prefix_id' => 57],
    ],
    [53, 54, 55]
);

// --- the deadline boundary ------------------------------------------------
check(
    'a thread one second past the deadline with no processing status is reminded',
    ReminderDecision::shouldRemind($now, $deadline, $now - $deadline - 1, 101, $processing, false) === true
);
check(
    'a thread exactly at the deadline is not reminded (age must exceed, not equal)',
    ReminderDecision::shouldRemind($now, $deadline, $now - $deadline, 101, $processing, false) === false,
    'the OP is deadline seconds old; equal is not past'
);
check(
    'a thread younger than the deadline is not reminded',
    ReminderDecision::shouldRemind($now, $deadline, $now - 3600, 101, $processing, false) === false
);

// --- the status-prefix suppression ----------------------------------------
check(
    'a thread carrying an in-processing prefix is never reminded, however old',
    ReminderDecision::shouldRemind($now, $deadline, $tenDaysOld, 102, $processing, false) === false,
    'a processing status means the application is being worked'
);
check(
    'a thread with no processing status is reminded past the deadline',
    ReminderDecision::shouldRemind($now, $deadline, $tenDaysOld, 101, $processing, false) === true
);
// --- the already-reminded guard -------------------------------------------
check(
    'a thread already recorded in the marker table is not reminded again',
    ReminderDecision::shouldRemind($now, $deadline, $tenDaysOld, 101, $processing, true) === false
);
check(
    'the marker wins even when the thread also carries a processing status',
    ReminderDecision::shouldRemind($now, $deadline, $tenDaysOld, 102, $processing, true) === false
);

// --- the batch selector ----------------------------------------------------
// One thread of each kind; only the two genuinely un-actioned, un-reminded ones
// come back, in INPUT order, as their thread ids. The higher remindable id (105)
// is placed before the lower one (101) on purpose, so the expected [105, 101]
// only holds if input order is preserved — a stray sort or reverse would fail.
$threads = [
    // reminded: old, no processing status, not yet reminded — HIGHER id, first
    ['thread_id' => 105, 'op_timestamp' => $now - $deadline - 1, 'already_reminded' => false],
    // skipped: carries a processing status prefix
    ['thread_id' => 102, 'op_timestamp' => $tenDaysOld, 'already_reminded' => false],
    // skipped: too young
    ['thread_id' => 103, 'op_timestamp' => $now - 3600,  'already_reminded' => false],
    // skipped: already reminded
    ['thread_id' => 104, 'op_timestamp' => $tenDaysOld, 'already_reminded' => true],
    // reminded: old, no processing status, not yet reminded — LOWER id, after
    ['thread_id' => 101, 'op_timestamp' => $tenDaysOld, 'already_reminded' => false],
];
check(
    'selectThreadsToRemind returns exactly the un-actioned, un-reminded thread ids in input order',
    ReminderDecision::selectThreadsToRemind($now, $deadline, $threads, $processing) === [105, 101],
    'got: ' . implode(', ', ReminderDecision::selectThreadsToRemind($now, $deadline, $threads, $processing))
);
check(
    'an empty queue yields nothing to remind',
    ReminderDecision::selectThreadsToRemind($now, $deadline, [], $processing) === []
);
// A thread the status set never heard of is un-actioned, so it is reminded. This
// is the mis-prefixed thread and the thread whose only prefix is its type: both
// must stay remindable, and neither can be confused with "no fact was supplied",
// because the fact is no longer something a caller supplies per thread.
check(
    'a thread absent from the status set reads as no processing status, not as suppressed',
    ReminderDecision::selectThreadsToRemind($now, $deadline, [
        ['thread_id' => 999, 'op_timestamp' => $tenDaysOld, 'already_reminded' => false],
    ], $processing) === [999]
);

// --- a non-positive deadline is refused ------------------------------------
// Zero or negative puts every open application past the deadline at once, i.e.
// remind the whole queue. The cron entry clamps the option to a one-hour floor,
// but that clamp is two classes away and this is the seam that decides, so it
// refuses the value itself rather than trusting the caller.
foreach ([0, -1, -86400] as $badDeadline) {
    $refused = false;
    try {
        ReminderDecision::shouldRemind($now, $badDeadline, $tenDaysOld, 101, $processing, false);
    } catch (\InvalidArgumentException $e) {
        $refused = true;
    }
    check("shouldRemind refuses a deadline of $badDeadline", $refused);
}
// The batch entry checks it too, so an empty queue cannot carry a bad deadline
// past unremarked just because no thread ever reaches shouldRemind.
$refusedBatch = false;
try {
    ReminderDecision::selectThreadsToRemind($now, 0, [], $processing);
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
    ReminderDecision::shouldRemind($now, 1, $now - 2, 101, $processing, false) === true
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
