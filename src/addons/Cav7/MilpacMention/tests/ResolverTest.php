<?php

/**
 * Issue #84 — the reverse-resolution mapping shape, exercised for real.
 * MilpacResolver::userIdsFromMap takes the ordered relation_ids and the
 * [relation_id => user_id] pairs the roster finder returns (spec §2.3) and
 * produces the ordered, de-duplicated user_ids to consider for alerting. The
 * live finder query lives in resolveUserIds() and is pinned by the wiring test;
 * this holds the pure shaping so the ordering, the skip of an unmapped
 * relation_id, the collapse of two rows onto one member, and the data-error log
 * that collapse raises (#112) all run in plain PHP.
 *
 * One milpac per user is the intended rule, but xf_nf_rosters_user does not enforce
 * it (non-unique user_id index; live data has user 7385 with two rows). So the
 * resolver does not assume the invariant — it collapses a duplicate to one recipient
 * AND logs the conflict, and both halves are asserted here through an injected
 * capturing logger (production defaults to \XF::logError).
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

/** A capturing logger: records each message so a standalone test can observe the
 *  duplicate-detection data-error log without a XenForo runtime (\XF::logError). */
function makeLog(array &$sink): callable
{
    return function (string $message) use (&$sink): void {
        $sink[] = $message;
    };
}

// The straight case: three relation_ids map to three members, order preserved.
check(
    'each relation_id maps to its user_id, in the input order',
    MilpacResolver::userIdsFromMap([42, 7, 99], [42 => 100, 7 => 250, 99 => 300]) === [100, 250, 300]
);

// Output follows RELATION-ID (link) order, not the finder map's insertion order.
// Here the input link order [99, 42, 7] differs from the map's insertion order
// [42, 7, 99], so a stray reliance on map order would yield [100, 250, 300]; the
// correct link-order result is [300, 100, 250]. This decides which milpac is kept
// vs dropped when the shared author cap overflows, so it must track the links.
check(
    'user_ids follow the input relation-id (link) order, not the map insertion order',
    MilpacResolver::userIdsFromMap([99, 42, 7], [42 => 100, 7 => 250, 99 => 300]) === [300, 100, 250]
);

// A relation_id the finder returned no row for (deleted roster row, bad link) is
// dropped rather than resolving to a phantom member.
check(
    'a relation_id with no row is skipped',
    MilpacResolver::userIdsFromMap([42, 8], [42 => 100]) === [100]
);

// One milpac per user is the intended rule, not a schema guarantee: xf_nf_rosters_user
// has a non-unique user_id index, so a member CAN own two roster rows. The resolver
// defends the rule two ways — it collapses the extra rows to a single alert target
// (deterministically, keeping the lowest relation_id, matching #96), and it logs the
// conflict as a data error so the bad data is visible instead of silently absorbed.
$dupLog = [];
check(
    'two relation_ids on one member collapse to one user_id (deterministic dedup)',
    MilpacResolver::userIdsFromMap([42, 43], [42 => 100, 43 => 100], makeLog($dupLog)) === [100]
);
check(
    'meeting the duplicate emits exactly one data-error log',
    count($dupLog) === 1,
    'the collapse must be visible, not silent (' . count($dupLog) . ' logged)'
);
check(
    'the data-error log names the member and BOTH conflicting relation_ids',
    isset($dupLog[0])
        && str_contains($dupLog[0], '100')
        && str_contains($dupLog[0], '42')
        && str_contains($dupLog[0], '43'),
    $dupLog[0] ?? '(nothing logged)'
);

// The real live case (#112): user 7385 owns relation_ids 3603 and 4771. Linking both
// milpacs in one message resolves to a single alert recipient and one logged data
// error naming the user and both relation_ids.
$live = [];
check(
    'the live duplicate (user 7385 / relation_ids 3603, 4771) resolves to one recipient',
    MilpacResolver::userIdsFromMap([3603, 4771], [3603 => 7385, 4771 => 7385], makeLog($live)) === [7385]
);
check(
    'that duplicate logs once, naming user 7385 and relation_ids 3603 and 4771',
    count($live) === 1
        && str_contains($live[0], '7385')
        && str_contains($live[0], '3603')
        && str_contains($live[0], '4771'),
    ($live[0] ?? '(nothing logged)') . ' [count ' . count($live) . ']'
);

// The normal single-milpac path stays silent: a member reached through exactly one
// relation_id is not a data error, so nothing is logged.
$quiet = [];
check(
    'a member with a single milpac logs nothing',
    MilpacResolver::userIdsFromMap([42, 7], [42 => 100, 7 => 250], makeLog($quiet)) === [100, 250]
        && $quiet === []
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
