<?php

/**
 * Issue #24 — the pure decision rules behind PUC automation, isolated from the
 * XenForo entities they ultimately drive so every acceptance criterion that is
 * a decision (not a database write) is covered without booting XenForo.
 *
 * Rules under test (all on Cav7\EnlistmentDefaults\EnlistmentDecisions):
 *
 *  - shouldApply(): fire on a milpac INSERT only. A move or an edit is an
 *    UPDATE and must not fire.
 *  - pendingDates(): idempotency guard — given the PUC dates the milpac already
 *    carries (as award_date timestamps for the PUC award), return only the
 *    bundled dates still missing, so a re-run never duplicates.
 *  - attributionUserId(): the acting visitor when there is a session, else the
 *    configured system fallback user id (CLI, no visitor).
 *  - The canonical enlistment record: a fixed Transfer-typed body.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/EnlistmentDecisionsTest.php
 */

namespace Cav7\EnlistmentDefaults\Tests;

require __DIR__ . '/../PucSet.php';
require __DIR__ . '/../EnlistmentDecisions.php';

use Cav7\EnlistmentDefaults\EnlistmentDecisions;
use Cav7\EnlistmentDefaults\PucSet;

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

// --- shouldApply(): insert-only gating -----------------------------------
check(
    'shouldApply() is true on a new milpac (insert)',
    EnlistmentDecisions::shouldApply(true) === true
);
check(
    'shouldApply() is false on an update (a move or an edit)',
    EnlistmentDecisions::shouldApply(false) === false
);

// --- pendingDates(): idempotency guard -----------------------------------
// A brand-new milpac carries no PUC awards: the full set is pending.
$fresh = EnlistmentDecisions::pendingDates([]);
check(
    'a milpac with no PUC awards yet has the whole set pending, in order',
    $fresh === PucSet::dates(),
    'got: ' . implode(', ', $fresh)
);

// A milpac that already carries some PUC dates: only the missing ones remain.
$existing = [
    PucSet::awardDateTimestamp('2003-03-18'),
    PucSet::awardDateTimestamp('2010-09-18'),
    PucSet::awardDateTimestamp('2021-05-16'),
];
$pending = EnlistmentDecisions::pendingDates($existing);
check(
    'pendingDates() skips dates the milpac already carries (no duplicates)',
    $pending === ['2004-09-01', '2009-08-10', '2011-06-02'],
    'got: ' . implode(', ', $pending)
);

// A milpac that already carries the full set: nothing pending (re-run is a no-op).
$full = array_map([PucSet::class, 'awardDateTimestamp'], PucSet::dates());
check(
    're-running on a milpac that already has the full set yields nothing pending',
    EnlistmentDecisions::pendingDates($full) === [],
    'got: ' . implode(', ', EnlistmentDecisions::pendingDates($full))
);

// Same-day timestamps that are not exactly midnight still count as "carried":
// matching is by calendar day, not exact second, so a manually-added grant at
// any time on a PUC day suppresses the bundled one.
$noonOn2003 = PucSet::awardDateTimestamp('2003-03-18') + 12 * 3600;
$pendingNoon = EnlistmentDecisions::pendingDates([$noonOn2003]);
check(
    'an existing grant anywhere on a PUC day suppresses that date (day-level match)',
    !in_array('2003-03-18', $pendingNoon, true),
    'got: ' . implode(', ', $pendingNoon)
);

// --- attributionUserId(): visitor, else system fallback ------------------
check(
    'attribution is the acting visitor when there is a session',
    EnlistmentDecisions::attributionUserId(42, 1) === 42
);
check(
    'attribution falls back to the configured system user with no session (CLI)',
    EnlistmentDecisions::attributionUserId(0, 1) === 1
);
check(
    'a zero visitor and zero fallback resolves to 0 (the vendor default)',
    EnlistmentDecisions::attributionUserId(0, 0) === 0
);

// --- The canonical enlistment record -------------------------------------
check(
    'the enlistment record body is the fixed canonical line',
    EnlistmentDecisions::ENLISTMENT_RECORD_BODY
        === 'Enlisted in the 7th Cavalry Regiment, Assigned Boot Camp'
);

// --- enlistmentRecordDate(): the record follows the Join Date, at midnight UTC --
// Issue #45: the submitted Join Date is a bare calendar day, stamped at midnight
// UTC of that day independent of the board timezone — the same convention
// PucSet::awardDateTimestamp() follows — so RosterPatch's UTC floor and
// rendering leave the entered day unchanged on any board.
$creation = 1600000000; // a creation moment that must be ignored when a date is given
$midnightUtc = \DateTimeImmutable::createFromFormat('!Y-m-d', '2012-05-18', new \DateTimeZone('UTC'))
    ->getTimestamp();
check(
    'a valid Y-m-d Join Date resolves to midnight UTC of that day',
    EnlistmentDecisions::enlistmentRecordDate('2012-05-18', $creation) === $midnightUtc,
    'got: ' . EnlistmentDecisions::enlistmentRecordDate('2012-05-18', $creation)
);

// The stamp does not depend on the board timezone. Parsed at local midnight, a
// board ahead of UTC (Pacific/Auckland, UTC+12) would have landed on the
// PREVIOUS UTC day, and a board behind UTC (America/New_York, UTC-4) hours past
// midnight UTC — the day-early shift #45 fixes. Neither happens now: the Join
// Date yields the one midnight-UTC instant whatever the board timezone.
$aheadLocalMidnight = \DateTimeImmutable::createFromFormat('!Y-m-d', '2012-05-18', new \DateTimeZone('Pacific/Auckland'))
    ->getTimestamp();
$behindLocalMidnight = \DateTimeImmutable::createFromFormat('!Y-m-d', '2012-05-18', new \DateTimeZone('America/New_York'))
    ->getTimestamp();
check(
    'a board ahead of UTC stamps midnight UTC, not the previous day',
    EnlistmentDecisions::enlistmentRecordDate('2012-05-18', $creation) === $midnightUtc
        && $midnightUtc !== $aheadLocalMidnight,
    'got: ' . EnlistmentDecisions::enlistmentRecordDate('2012-05-18', $creation)
);
check(
    'a board behind UTC stamps midnight UTC, not hours past it',
    EnlistmentDecisions::enlistmentRecordDate('2012-05-18', $creation) === $midnightUtc
        && $midnightUtc !== $behindLocalMidnight,
    'got: ' . EnlistmentDecisions::enlistmentRecordDate('2012-05-18', $creation)
);

// The creation-date fallback resolves to midnight UTC of the creation DAY, not
// the raw wall-clock instant: $creation is partway through 2020-09-13, so the
// record is dated to that day's midnight UTC, an earlier and distinct value.
$creationFloored = \DateTimeImmutable::createFromFormat('!Y-m-d', gmdate('Y-m-d', $creation), new \DateTimeZone('UTC'))
    ->getTimestamp();
check(
    'a blank Join Date falls back to midnight UTC of the creation day (not the instant)',
    EnlistmentDecisions::enlistmentRecordDate('', $creation) === $creationFloored
        && $creationFloored !== $creation,
    'got: ' . EnlistmentDecisions::enlistmentRecordDate('', $creation)
);
check(
    'a whitespace-only Join Date falls back to midnight UTC of the creation day',
    EnlistmentDecisions::enlistmentRecordDate('   ', $creation) === $creationFloored
);

// An unparseable value falls back the same way (no exception, no junk date).
check(
    'an unparseable Join Date falls back to midnight UTC of the creation day',
    EnlistmentDecisions::enlistmentRecordDate('not-a-date', $creation) === $creationFloored
);
check(
    'a malformed date with the right shape but bad values falls back',
    EnlistmentDecisions::enlistmentRecordDate('2012-13-45', $creation) === $creationFloored,
    'got: ' . EnlistmentDecisions::enlistmentRecordDate('2012-13-45', $creation)
);

// --- Summary --------------------------------------------------------------
if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
