<?php

/**
 * Issue #186 — the rule that turns SV/MultiPrefix's thread-prefix link rows into
 * the one fact ReminderDecision now reads: does this thread carry a processing
 * status? Pure PHP, so the trap that would silently disable the whole add-on is
 * covered for real rather than pinned by shape.
 *
 * The trap: the link table holds EVERY prefix a thread carries, including its
 * Enlistment (57) / Re-Enlistment (58) type prefix, and every valid queue thread
 * carries one. A "does this thread have any linked prefix" test therefore reads
 * the entire queue as in-processing and suppresses every reminder forever — and
 * quietly, because nothing errors. Membership in the CONFIGURED in-processing
 * set is the only correct test, and the fixture below is taken from the live
 * board so the type prefix is always present alongside whatever else is.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ProcessingStatusTest.php
 */

namespace Cav7\EnlistmentReminder\Tests;

require __DIR__ . '/../PositionIdList.php';
require __DIR__ . '/../ProcessingStatus.php';

use Cav7\EnlistmentReminder\ProcessingStatus;

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

/** The shipped default: Hold, Approved, In Progress. */
$inProcessing = [53, 54, 55];

/**
 * A link-row fixture in the vendor's own shape, one row per (thread, prefix).
 * The prefix sets are real ones read off the live queue node: an un-actioned
 * thread carries only its type prefix; a worked one adds a status; an approved
 * one also carries the S1 (66) and RTC (68) modifiers; an In Progress one may
 * carry the "!!!" modifier (110). Thread 906 is the mis-prefixed thread with no
 * links at all.
 *
 * @param array<int,int[]> $prefixesByThread
 * @return array<int,array<string,mixed>>
 */
function linkRows(array $prefixesByThread): array
{
    $rows = [];
    foreach ($prefixesByThread as $threadId => $prefixIds) {
        foreach ($prefixIds as $prefixId) {
            // The DB hands these back as strings; keep the fixture honest.
            $rows[] = ['thread_id' => (string) $threadId, 'prefix_id' => (string) $prefixId];
        }
    }
    return $rows;
}

$rows = linkRows([
    901 => [57],              // standard enlistment, no status — un-actioned
    902 => [58],              // re-enlistment, no status — un-actioned
    903 => [55, 57],          // In Progress
    904 => [53, 57],          // Hold
    905 => [54, 57, 66, 68],  // Approved, with the S1 and RTC modifiers
    907 => [55, 57, 110],     // In Progress with the "!!!" modifier
]);

$statuses = ProcessingStatus::inProcessingThreadIds($rows, $inProcessing);

// --- the trap: a type prefix alone is NOT a processing status --------------
check(
    'a thread carrying only the Enlistment type prefix is not in processing',
    !isset($statuses[901]),
    'a "has any linked prefix" test would read the whole queue as handled and suppress every reminder'
);
check(
    'a thread carrying only the Re-Enlistment type prefix is not in processing',
    !isset($statuses[902])
);
check(
    'a thread with no link rows at all is not in processing',
    !isset($statuses[906]),
    'the mis-prefixed thread must stay remindable, and be skipped later by the type routing'
);

// --- each configured status suppresses -------------------------------------
check('an In Progress thread is in processing', isset($statuses[903]));
check('a Hold thread is in processing', isset($statuses[904]));
check('an Approved thread is in processing', isset($statuses[905]));

// --- modifiers alongside a status do not change the answer -----------------
check(
    'the S1 and RTC modifiers alongside Approved keep the thread in processing',
    isset($statuses[905]),
    'they are out of scope as separate suppressors; only the configured set decides'
);
check(
    'the "!!!" modifier alongside In Progress keeps the thread in processing',
    isset($statuses[907])
);

// --- a modifier on its own is not a status ---------------------------------
// S1 (66), RTC (68) and "!!!" (110) are deliberately NOT in the configured set.
// A thread carrying one with no Hold/Approved/In Progress is still un-actioned.
$modifierOnly = ProcessingStatus::inProcessingThreadIds(
    linkRows([910 => [57, 66], 911 => [57, 110]]),
    $inProcessing
);
check(
    'an unconfigured modifier alone does not suppress a reminder',
    $modifierOnly === [],
    'got: ' . implode(', ', array_keys($modifierOnly))
);

// --- the configured set is what decides, not a hard-coded list -------------
// Reconfiguring to In Progress only must leave Hold and Approved remindable.
$inProgressOnly = ProcessingStatus::inProcessingThreadIds($rows, [55]);
check(
    'narrowing the configured set narrows the suppression',
    array_keys($inProgressOnly) === [903, 907],
    'got: ' . implode(', ', array_keys($inProgressOnly))
);

// --- an empty configured set suppresses nothing ----------------------------
// The caller aborts the run before this point on a blank option, so nothing is
// mass-reminded; the seam itself stays honest and claims no thread is handled.
check(
    'an empty configured set marks no thread as in processing',
    ProcessingStatus::inProcessingThreadIds($rows, []) === []
);
check(
    'no link rows at all marks no thread as in processing',
    ProcessingStatus::inProcessingThreadIds([], $inProcessing) === []
);

// --- id hygiene -------------------------------------------------------------
// Ids arrive from the DB as strings and from the option parser as ints; both
// sides normalise to int, so a 0 or junk prefix id can never become a phantom
// match against a junk entry in the configured set.
check(
    'string link ids match int configured ids',
    isset(ProcessingStatus::inProcessingThreadIds(
        [['thread_id' => '920', 'prefix_id' => '54']],
        [54]
    )[920])
);
check(
    'a zero prefix id never matches, even against a zero in the configured set',
    ProcessingStatus::inProcessingThreadIds(
        [['thread_id' => 921, 'prefix_id' => 0]],
        [0, 54]
    ) === [],
    'prefix_id 0 is "no prefix"; treating it as a status would suppress every unprefixed thread'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
