<?php

/**
 * Issue #84 — the firing rules as a pure function. MilpacResolver::milpacRecipients
 * takes the @-mention set, the resolved milpac set, the author, and the author's
 * maxMentionedUsers cap, and returns the milpac-only members to alert (spec §2.5).
 * Under the distinct milpac_mention alert the firing layer enforces these itself
 * (it no longer rides the core Mention notifier), so they run for real here:
 *
 *   rule 1  self-links suppressed (the author's own milpac never alerts them)
 *   rule 2  one alert per member (a member linked twice appears once)
 *   rule 5  dedup with @, preferring @ (milpac set minus the @-mention set)
 *   rule 6  shared author cap (@ kept, milpac dropped first on overflow to N)
 *
 * The cap mirrors XF\Entity\User::getAllowedUserMentions: 0 => none, < 0 =>
 * unlimited, else the first N of (@ ++ milpac) with @ first. Self-contained: no
 * XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/FiringRulesTest.php
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

// rule 1: the author linking their own milpac is not alerted.
check(
    'the author is dropped from the milpac set (self-link suppressed)',
    MilpacResolver::milpacRecipients([], [5, 6], 5, 10) === [6]
);

// rule 2: a member reached more than once yields a single entry.
check(
    'a repeated member collapses to one recipient',
    MilpacResolver::milpacRecipients([], [6, 6, 7], 0, 10) === [6, 7]
);

// rule 5: a member both @-mentioned and milpac-linked gets only the @ alert, so
// they are removed from the milpac set.
check(
    'a member already in the @ set is removed from the milpac set',
    MilpacResolver::milpacRecipients([6], [6, 7], 0, 10) === [7]
);

// rule 6: @-mentions hold their budget slots; milpac fills only what is left.
// cap 3, two @-mentions -> one milpac slot, the rest drop.
check(
    'on overflow @ is kept and milpac drops first (cap fills @ then milpac)',
    MilpacResolver::milpacRecipients([1, 2], [8, 9, 10], 0, 3) === [8]
);

// rule 6 edge: the @ set alone meets or exceeds the cap, so no milpac fires.
check(
    'when @ already fills the cap, no milpac fires',
    MilpacResolver::milpacRecipients([1, 2, 3], [8], 0, 3) === []
);

// cap of 0 means the author may mention nobody at all — milpac included.
check(
    'a cap of 0 fires no milpac',
    MilpacResolver::milpacRecipients([], [8, 9], 0, 0) === []
);

// a negative cap is XF's "unlimited": every milpac (minus author, minus @) fires.
check(
    'an unlimited (negative) cap fires every remaining milpac',
    MilpacResolver::milpacRecipients([1], [8, 9], 0, -1) === [8, 9]
);

// unlimited cap still applies the self-link and @-dedup filters BEFORE returning:
// author 5 and @-member 1 both drop, leaving just 9. A regression that returned the
// raw milpac set on the cap < 0 branch before the filter loop would wrongly keep
// 5 and 1 here.
check(
    'an unlimited cap still drops the author and the @-mention set',
    MilpacResolver::milpacRecipients([1], [5, 1, 9], 5, -1) === [9]
);

// the rules compose: author 5 and @-member 1 both drop, then the cap of 4 leaves
// three slots after the single @, so the first three of the remaining milpac fire
// and 11 overflows.
check(
    'rules compose: self-skip, @-dedup and cap applied together',
    MilpacResolver::milpacRecipients([1], [5, 1, 8, 9, 10, 11], 5, 4) === [8, 9, 10]
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
