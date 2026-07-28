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
 * carry the "!!!" modifier (110).
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

/**
 * What the given status says about every thread in the fixture, as
 * thread_id => bool. Asking about all of them, rather than only the ones a case
 * changes, is what makes a rule that stopped consulting the configured set fail
 * here instead of passing on the half nobody looked at.
 *
 * @return array<int,bool>
 */
function answersFor(ProcessingStatus $status, array $threadIds): array
{
    $answers = [];
    foreach ($threadIds as $threadId) {
        $answers[$threadId] = $status->has($threadId);
    }
    return $answers;
}

/** The threads the $rows fixture below describes, in ascending order. */
const FIXTURE_THREADS = [901, 902, 903, 904, 905, 907];

// Thread 906 is absent on purpose: it stands for the mis-prefixed thread that has
// no link rows at all, and a thread with no rows can only be represented by not
// being in the fixture.
$rows = linkRows([
    901 => [57],              // standard enlistment, no status — un-actioned
    902 => [58],              // re-enlistment, no status — un-actioned
    903 => [55, 57],          // In Progress
    904 => [53, 57],          // Hold
    905 => [54, 57, 66, 68],  // Approved, with the S1 and RTC modifiers
    907 => [55, 57, 110],     // In Progress with the "!!!" modifier
]);

$statuses = ProcessingStatus::fromPrefixLinks($rows, $inProcessing);

// --- the trap: a type prefix alone is NOT a processing status --------------
check(
    'a thread carrying only the Enlistment type prefix is not in processing',
    !$statuses->has(901),
    'a "has any linked prefix" test would read the whole queue as handled and suppress every reminder'
);
check(
    'a thread carrying only the Re-Enlistment type prefix is not in processing',
    !$statuses->has(902)
);
// Documentation, not coverage: a thread with no rows cannot be in the fixture, so
// there is no mutation of this seam that makes 906 answer true and this check
// cannot fail. It is here to state the shape of the answer. The real behaviour for
// a mis-prefixed thread is on the scanner side, where the absent thread still gets
// a fact built for it and the type routing skips it — exercised against a live
// board rather than in CI.
check(
    'a thread with no link rows at all reads as not in processing',
    !$statuses->has(906),
    'the mis-prefixed thread must stay remindable, and be skipped later by the type routing'
);

// --- each configured status suppresses -------------------------------------
check('an In Progress thread is in processing', $statuses->has(903));
check('a Hold thread is in processing', $statuses->has(904));
check('an Approved thread is in processing', $statuses->has(905));

// --- modifiers alongside a status do not change the answer -----------------
check(
    'the S1 and RTC modifiers alongside Approved keep the thread in processing',
    $statuses->has(905),
    'they are out of scope as separate suppressors; only the configured set decides'
);
check(
    'the "!!!" modifier alongside In Progress keeps the thread in processing',
    $statuses->has(907)
);

// --- a modifier on its own is not a status ---------------------------------
// S1 (66), RTC (68) and "!!!" (110) are deliberately NOT in the configured set.
// A thread carrying one with no Hold/Approved/In Progress is still un-actioned.
$modifierOnly = ProcessingStatus::fromPrefixLinks(
    linkRows([910 => [57, 66], 911 => [57, 110]]),
    $inProcessing
);
check(
    'an unconfigured modifier alone does not suppress a reminder',
    !$modifierOnly->has(910) && !$modifierOnly->has(911)
);

// --- the configured set is what decides, not a hard-coded list -------------
// Reconfiguring to In Progress only must leave Hold and Approved remindable.
$narrowed = answersFor(ProcessingStatus::fromPrefixLinks($rows, [55]), FIXTURE_THREADS);
check(
    'narrowing the configured set to In Progress leaves Hold and Approved remindable',
    $narrowed === [901 => false, 902 => false, 903 => true, 904 => false, 905 => false, 907 => true],
    'in processing: ' . implode(', ', array_keys(array_filter($narrowed)))
);

// --- an empty configured set is REFUSED, not answered -----------------------
// Returning [] would read to every caller as "no thread is being worked", i.e.
// remind every past-deadline application, which is the regression #186 exists to
// fix. QueueReminder's own guard is the friendly path and aborts first; this is
// the backstop for the next caller, and a throw out of cron aborts the run and
// lands in the error log, which is what that guard chooses anyway.
$refusedEmptySet = false;
try {
    ProcessingStatus::fromPrefixLinks($rows, []);
} catch (\InvalidArgumentException $e) {
    $refusedEmptySet = true;
}
check(
    'an empty configured set is refused rather than answered with "nothing is handled"',
    $refusedEmptySet,
    'answering [] here would remind the whole queue if any caller ever skipped its own guard'
);
// A set of nothing but junk normalises to empty, so it is the same refusal — the
// blank-option case an admin actually produces.
$refusedJunkSet = false;
try {
    ProcessingStatus::fromPrefixLinks($rows, ['', 'abc', '0']);
} catch (\InvalidArgumentException $e) {
    $refusedJunkSet = true;
}
check(
    'a configured set of nothing but junk is refused the same way',
    $refusedJunkSet,
    'the emptiness that matters is post-normalize, not the raw array'
);
// 903 carries In Progress in the fixture above, so it is the thread that would
// answer true if anything but the rows decided the answer.
check(
    'no link rows at all marks no thread as in processing',
    !ProcessingStatus::fromPrefixLinks([], $inProcessing)->has(903)
);

// --- id hygiene -------------------------------------------------------------
// Ids arrive from the DB as strings and from the option parser as ints; both
// sides normalise to int, so a 0 or junk prefix id can never become a phantom
// match against a junk entry in the configured set.
check(
    'string link ids match int configured ids',
    ProcessingStatus::fromPrefixLinks(
        [['thread_id' => '920', 'prefix_id' => '54']],
        [54]
    )->has(920)
);
// The other direction of the same declaration, which nothing above exercised: the
// seam takes `int[]|string[]` for the CONFIGURED set, and every fixture so far has
// handed it ints (string row ids against an int configured set). The live caller only
// ever passes PositionIdList::parse's ints, so this is the declared contract's unused
// half rather than a reachable fault — but it is declared, and normalize on the
// configured side is the whole reason it holds.
$stringAnswers = answersFor(
    ProcessingStatus::fromPrefixLinks($rows, ['53', '54', '55']),
    FIXTURE_THREADS
);
check(
    'a string-typed configured set marks exactly the threads its int twin does',
    $stringAnswers === [901 => false, 902 => false, 903 => true, 904 => true, 905 => true, 907 => true],
    'in processing: ' . implode(', ', array_keys(array_filter($stringAnswers)))
);
check(
    'a zero prefix id never matches, even against a zero in the configured set',
    !ProcessingStatus::fromPrefixLinks(
        [['thread_id' => 921, 'prefix_id' => 0]],
        [0, 54]
    )->has(921),
    'prefix_id 0 is "no prefix"; treating it as a status would suppress every unprefixed thread'
);
// The thread id gets the same treatment as the prefix id. A row with a real status
// but a missing or zero thread_id must not make thread 0 answer true: the scanner
// asks about the thread ids it read off the queue, and a phantom 0 is a thread
// that does not exist.
check(
    'a row with a matching status but no usable thread id does not make thread 0 in processing',
    !ProcessingStatus::fromPrefixLinks(
        [['thread_id' => 0, 'prefix_id' => 54], ['prefix_id' => 55]],
        $inProcessing
    )->has(0)
);
$fromMixedRows = ProcessingStatus::fromPrefixLinks(
    [['thread_id' => 0, 'prefix_id' => 54], ['thread_id' => 930, 'prefix_id' => 54]],
    $inProcessing
);
check(
    'a usable row alongside an unusable one still lands',
    $fromMixedRows->has(930) && !$fromMixedRows->has(0)
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
