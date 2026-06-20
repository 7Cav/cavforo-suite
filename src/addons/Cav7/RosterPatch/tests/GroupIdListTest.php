<?php

/**
 * Behavioural unit test for GroupIdList::normalize — the equality primitive the
 * whole drift detection rests on. It is pure PHP with no XenForo dependency, so
 * unlike the rest of the addon it can be exercised for real here, not just
 * pinned by shape. If its equality semantics break, every "is this holder
 * drifted?" decision breaks: the hook syncs nobody, or the reconcile reports
 * false drift forever.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/GroupIdListTest.php
 */

namespace Cav7\RosterPatch\Tests;

require __DIR__ . '/../GroupIdList.php';

use Cav7\RosterPatch\GroupIdList;

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

// Order and format independence: a comma-string snapshot and an int array must
// compare equal when they describe the same set.
check("'3,1' normalises to [1,3]", GroupIdList::normalize('3,1') === [1, 3]);
check('[3,1,3] normalises to [1,3]', GroupIdList::normalize([3, 1, 3]) === [1, 3]);
check("'322,45' equals [45,322]", GroupIdList::normalize('322,45') === GroupIdList::normalize([45, 322]));

// Empty / missing snapshot must be [] (load-bearing: a holder with no row counts
// as drift whenever the position grants anything).
check("'' normalises to []", GroupIdList::normalize('') === []);
check('null normalises to []', GroupIdList::normalize(null) === []);
check('[] normalises to []', GroupIdList::normalize([]) === []);

// Dedup and junk-segment filtering.
check("'2,2' normalises to [2]", GroupIdList::normalize('2,2') === [2]);
check("trailing comma '1,' normalises to [1]", GroupIdList::normalize('1,') === [1]);
check("'0' normalises to [] (no group has id 0)", GroupIdList::normalize('0') === []);

// The exact comparisons drift detection performs.
$target = GroupIdList::normalize([45, 322]);
check('matching snapshot is not drift', GroupIdList::normalize('45,322') === $target);
check('stale snapshot (45) is drift', GroupIdList::normalize('45') !== $target);
check('missing snapshot is drift', GroupIdList::normalize('') !== $target);
// The revoke direction: a snapshot still listing a group the position no longer
// grants must read as drift, so the stale grant gets revoked, not kept.
check('stale superset snapshot is drift', GroupIdList::normalize('45,322') !== GroupIdList::normalize([45]));
// Vendor snapshots are comma strings; intval tolerates incidental whitespace.
check("whitespace segments normalise (' 2 , 1 ' -> [1,2])", GroupIdList::normalize(' 2 , 1 ') === [1, 2]);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
