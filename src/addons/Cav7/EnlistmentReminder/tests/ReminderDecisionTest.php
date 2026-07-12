<?php

/**
 * Issue #75 — the pure decision behind the enlistment-queue reminder, isolated
 * from XenForo so every branch of "is this application un-actioned and not yet
 * reminded?" is covered without booting the framework. The queue query, the
 * clerk-seat resolution, the bot post and the marker write are XenForo-coupled
 * and pinned by ScanWiringTest instead; this file exercises the rule itself.
 *
 * Rules under test (Cav7\EnlistmentReminder\ReminderDecision):
 *
 *  - the deadline boundary: an application is reminded only once its age since
 *    the OP EXCEEDS the deadline, so a thread exactly at the deadline, or
 *    younger, is left alone.
 *  - the clerk-pickup exclusion: a reply from a current Processing Clerk means
 *    the application is being worked, so it is never reminded however old it is.
 *  - the applicant/recruiter-only case: replies from the enlistee, a recruiter,
 *    or the bot are not pickups, so a thread carrying only those is reminded
 *    once past the deadline.
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
$clerks   = [10, 20, 30];

// Reply-author sets. The clerk set above never overlaps the applicant/recruiter
// ids; the "picked up" set adds one clerk id (20) to prove a single clerk reply
// is enough.
$applicantAndRecruiterOnly = [500, 600];   // enlistee + recruiter, no clerk
$pickedUpByClerk           = [500, 20];    // recruiter + one seated clerk

// --- the deadline boundary ------------------------------------------------
check(
    'a thread one second past the deadline with no clerk reply is reminded',
    ReminderDecision::shouldRemind($now, $deadline, $clerks, $now - $deadline - 1, $applicantAndRecruiterOnly, false) === true
);
check(
    'a thread exactly at the deadline is not reminded (age must exceed, not equal)',
    ReminderDecision::shouldRemind($now, $deadline, $clerks, $now - $deadline, $applicantAndRecruiterOnly, false) === false,
    'the OP is deadline seconds old; equal is not past'
);
check(
    'a thread younger than the deadline is not reminded',
    ReminderDecision::shouldRemind($now, $deadline, $clerks, $now - 3600, $applicantAndRecruiterOnly, false) === false
);

// --- the clerk-pickup exclusion -------------------------------------------
// Age is deliberately far past the deadline (ten days) so the only thing that
// can suppress the reminder is the clerk reply.
$tenDaysOld = $now - (10 * 86400);
check(
    'a thread with a current-clerk reply is never reminded, however old',
    ReminderDecision::shouldRemind($now, $deadline, $clerks, $tenDaysOld, $pickedUpByClerk, false) === false,
    'a clerk pickup means the application is being worked'
);

// --- the applicant/recruiter-only case ------------------------------------
check(
    'a thread whose only replies are the applicant and a recruiter is reminded past the deadline',
    ReminderDecision::shouldRemind($now, $deadline, $clerks, $tenDaysOld, $applicantAndRecruiterOnly, false) === true
);
check(
    'a thread with no replies at all is reminded past the deadline',
    ReminderDecision::shouldRemind($now, $deadline, $clerks, $tenDaysOld, [], false) === true
);

// --- the already-reminded guard -------------------------------------------
check(
    'a thread already recorded in the marker table is not reminded again',
    ReminderDecision::shouldRemind($now, $deadline, $clerks, $tenDaysOld, $applicantAndRecruiterOnly, true) === false
);

// --- an empty clerk set does not mistake anyone for a clerk ---------------
// If no seats are configured/held, no reply can be a pickup, so an old
// un-actioned thread is still reminded.
check(
    'with no clerks seated, an old thread is reminded (no reply can be a pickup)',
    ReminderDecision::shouldRemind($now, $deadline, [], $tenDaysOld, [500, 600], false) === true
);

// --- id-type robustness: string ids from the DB still match ---------------
// XF's fetch helpers hand back string columns; a clerk id of 20 must still be
// recognised whether it arrives as int or string on either side.
check(
    'a clerk reply matches across int/string id representations',
    ReminderDecision::shouldRemind($now, $deadline, ['20'], $tenDaysOld, [20], false) === false,
    'a string clerk id and an int reply id are the same seat'
);

// --- the batch selector ----------------------------------------------------
// One thread of each kind; only the two genuinely un-actioned, un-reminded ones
// come back, in INPUT order, as their thread ids. The higher remindable id (105)
// is placed before the lower one (101) on purpose, so the expected [105, 101]
// only holds if input order is preserved — a stray sort or reverse would fail.
$threads = [
    // reminded: old, no replies, not yet reminded — HIGHER id, placed first
    ['thread_id' => 105, 'op_timestamp' => $now - $deadline - 1, 'reply_author_ids' => [], 'already_reminded' => false],
    // skipped: clerk pickup
    ['thread_id' => 102, 'op_timestamp' => $tenDaysOld, 'reply_author_ids' => [30],  'already_reminded' => false],
    // skipped: too young
    ['thread_id' => 103, 'op_timestamp' => $now - 3600, 'reply_author_ids' => [],    'already_reminded' => false],
    // skipped: already reminded
    ['thread_id' => 104, 'op_timestamp' => $tenDaysOld, 'reply_author_ids' => [],    'already_reminded' => true],
    // reminded: old, applicant-only, not yet reminded — LOWER id, placed after
    ['thread_id' => 101, 'op_timestamp' => $tenDaysOld, 'reply_author_ids' => [500], 'already_reminded' => false],
];
check(
    'selectThreadsToRemind returns exactly the un-actioned, un-reminded thread ids in input order',
    ReminderDecision::selectThreadsToRemind($now, $deadline, $clerks, $threads) === [105, 101],
    'got: ' . implode(', ', ReminderDecision::selectThreadsToRemind($now, $deadline, $clerks, $threads))
);
check(
    'an empty queue yields nothing to remind',
    ReminderDecision::selectThreadsToRemind($now, $deadline, $clerks, []) === []
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
