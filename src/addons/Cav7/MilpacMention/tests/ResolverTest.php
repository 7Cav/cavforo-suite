<?php

/**
 * Issue #84 — the reverse-resolution mapping shape, exercised for real.
 * MilpacResolver::userIdsFromMap takes the ordered relation_ids and the
 * [relation_id => user_id] pairs the roster finder returns (spec §2.3) and
 * produces the ordered, de-duplicated user_ids to consider for alerting. The
 * live finder query lives in resolveUserIds() and is pinned by the wiring test;
 * this holds the pure shaping so the ordering, the skip of an unmapped
 * relation_id, and the collapse of two rows onto one member all run in plain PHP.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ResolverTest.php
 */

namespace Cav7\MilpacMention\Tests;

require __DIR__ . '/../MilpacResolver.php';

use Cav7\MilpacMention\MilpacResolver;

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

// The straight case: three relation_ids map to three members, order preserved.
check(
    'each relation_id maps to its user_id, in the input order',
    MilpacResolver::userIdsFromMap([42, 7, 99], [42 => 100, 7 => 250, 99 => 300]) === [100, 250, 300]
);

// A relation_id the finder returned no row for (deleted roster row, bad link) is
// dropped rather than resolving to a phantom member.
check(
    'a relation_id with no row is skipped',
    MilpacResolver::userIdsFromMap([42, 8], [42 => 100]) === [100]
);

// The one-user-one-milpac invariant is defended: were two rows ever to point at
// the same member, they collapse to a single alert target.
check(
    'two relation_ids on one member collapse to one user_id',
    MilpacResolver::userIdsFromMap([42, 43], [42 => 100, 43 => 100]) === [100]
);

// No links resolved -> no members.
check(
    'no relation_ids yields no user_ids',
    MilpacResolver::userIdsFromMap([], []) === []
);

// A row carrying a 0 user_id (should never happen; user_id is required) is not a
// real recipient and is dropped.
check(
    'a 0 user_id is dropped',
    MilpacResolver::userIdsFromMap([42], [42 => 0]) === []
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
