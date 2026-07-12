<?php

/**
 * Issue #84 — the one-request hand-off, exercised for real. MilpacStash carries the
 * milpac recipients from the detection hook (PreparerService) to the firing
 * extension (NotifierService), keyed by the content entity's object id (spec §2.5).
 * It is load-bearing: the consuming take() is what makes the once-only / no-refire
 * guarantee hold, so its behaviour is pinned here rather than only substring-matched
 * in the wiring test.
 *
 * MilpacStash carries no XenForo dependency (it keys on spl_object_id and stores a
 * plain array), so a bare \stdClass stands in for the Post entity and every branch
 * runs in plain PHP. References are kept alive across each case so an object id is
 * never recycled onto a later object mid-test.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/StashTest.php
 */

namespace Cav7\MilpacMention\Tests;

require __DIR__ . '/../MilpacStash.php';

use Cav7\MilpacMention\MilpacStash;

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

// stash then take returns exactly the stashed ids, in order.
$a = new \stdClass();
MilpacStash::stash($a, [10, 20, 30]);
check(
    'stash then take returns the stashed ids',
    MilpacStash::take($a) === [10, 20, 30]
);

// take() is consuming: a SECOND take() on the same object returns []. This is the
// guard that stops a double notify() on one Post from double-firing (rule §2.5.2).
$b = new \stdClass();
MilpacStash::stash($b, [7]);
MilpacStash::take($b);
check(
    'a second take() on the same object returns [] (consuming, so no double-fire)',
    MilpacStash::take($b) === []
);

// An empty recipient set is never stashed, so take() finds nothing to fire.
$c = new \stdClass();
MilpacStash::stash($c, []);
check(
    'stashing an empty set leaves take() empty',
    MilpacStash::take($c) === []
);

// Two distinct objects keep separate stashes — the key is object identity, so one
// entity's recipients never leak onto another's.
$d = new \stdClass();
$e = new \stdClass();
MilpacStash::stash($d, [1, 2]);
MilpacStash::stash($e, [3, 4]);
check(
    'two distinct objects keep separate stashes',
    MilpacStash::take($d) === [1, 2] && MilpacStash::take($e) === [3, 4]
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
