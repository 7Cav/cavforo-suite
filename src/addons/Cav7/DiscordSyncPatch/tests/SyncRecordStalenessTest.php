<?php

/**
 * Issue #157 — exercises SyncRecordStaleness, the forum-side half of divergence
 * detection as a pure function.
 *
 * A sync log row is stale when it no longer describes a correct, successful sync
 * of that member on that guild. The scan hands this the row's recorded group set,
 * its active flag and its error phrase, together with the member's current groups.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/SyncRecordStalenessTest.php
 */

namespace Cav7\DiscordSyncPatch\Tests;

require __DIR__ . '/../SyncRecordStaleness.php';

use Cav7\DiscordSyncPatch\SyncRecordStaleness;

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

// The group set is a set, not a sequence. XenForo hands current groups back in
// whatever order it assembled them and the recorded set is whatever order was
// stored, so a sequence comparison reports every synced member stale on every
// run — roughly three thousand corrections a quarter-hour, forever, against a
// Discord budget near sixty role writes a minute.
check(
    'the same groups in a different order are not stale',
    !SyncRecordStaleness::isStale([1, 2, 3], [3, 1, 2], true, null),
    'a sequence comparison would report the whole board stale every run'
);

// Same failure by the other route. The recorded set arrives from a column the
// vendor stores as a serialized array and the current groups arrive from the user
// entity, so the two sides can disagree on type while naming the same groups.
check(
    'the same groups as strings and as ints are not stale',
    !SyncRecordStaleness::isStale(['1', '2'], [1, 2], true, null),
    'a strict comparison would report the whole board stale every run'
);

// The condition the scan exists to find: the member's groups moved after the last
// sync recorded them, so Discord is holding roles decided from the old set. Without
// these two the assertions above are satisfied by a rule that answers "never
// stale", which is the same as not running the scan at all.
check(
    'a member who gained a group is stale',
    SyncRecordStaleness::isStale([1, 2], [1, 2, 3], true, null),
    'a promotion the sync never applied must be found'
);
check(
    'a member who lost a group is stale',
    SyncRecordStaleness::isStale([1, 2, 3], [1, 2], true, null),
    'a discharge the sync never applied must be found'
);

// The group set agreeing is not enough on its own. A row can name the right groups
// and still describe a sync that did not finish, and those members are exactly the
// ones a reconciler exists to retry.
//
// The vendor sets the error phrase and clears the active flag together
// (SyncLog::setInvalid), so an errored row is always an inactive row. A scan that
// filters on active = 1 — which is what NF/Discord's own dormant cron does —
// therefore excludes precisely the members most in need of correction.
check(
    'a row carrying an error phrase is stale even when the groups agree',
    SyncRecordStaleness::isStale([1, 2], [1, 2], false, 'nf_discord_sync_err.join_failed_unknown'),
    'a failed sync must be retried rather than read as settled'
);
check(
    'an inactive row is stale even when the groups agree and no error is recorded',
    SyncRecordStaleness::isStale([1, 2], [1, 2], false, null),
    'the vendor leaves rows inactive without a phrase; they still need correcting'
);

// The phrase read on its own, with the active flag held at its non-triggering
// value. No vendor path writes this state today — setInvalid() stores the phrase
// and clears the flag in one call — so this is a guard against that invariant
// moving, not a state seen in production. Without it the assertion above is
// satisfied by the flag check alone and the phrase is never read at all, which is
// how a vendor that stopped clearing the flag would go unnoticed.
check(
    'a phrase on an otherwise active row is stale',
    SyncRecordStaleness::isStale([1, 2], [1, 2], true, 'nf_discord_sync_err.rejected_join'),
    'reading only the active flag would settle a member whose sync recorded a failure'
);

// The ordinary case, and the one that keeps the sweep cheap: a member whose last
// sync succeeded and whose groups have not moved since is left alone entirely.
check(
    'an active row with no error and agreeing groups is not stale',
    !SyncRecordStaleness::isStale([1, 2], [2, 1], true, null),
    'correcting settled members would spend the whole Discord budget on no-ops'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
