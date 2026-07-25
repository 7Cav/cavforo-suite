<?php

/**
 * Issue #147 — the suppressed-area rule as a pure function.
 * MilpacResolver::isSuppressedArea takes an admin deny-list of area ids and the
 * area one piece of content sits in, and answers whether a milpac mention is
 * withheld there. A suppressed area is a place, not a member and not a
 * relationship: the predicate never sees who opened the ticket, who can view it,
 * or who is a participant.
 *
 * Two deny-lists ride this one predicate — forum nodes for the post surface and
 * ticket categories for the ticket-message surface — because "is this area on the
 * list" is the same question either way; only the id space differs.
 *
 * Selected means suppressed, so an empty list fires everywhere and an unlisted
 * area behaves exactly as it did before the options existed. That is what makes
 * suppression an explicit act rather than a default.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/SuppressionRulesTest.php
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

// =========================================================================
// the predicate itself
// =========================================================================

// The shipped-empty forum-node list, and any list an admin has emptied again:
// nothing is suppressed, so every node behaves as it did before the option existed.
check(
    'an empty deny-list suppresses nothing',
    MilpacResolver::isSuppressedArea([], 42) === false
);

// The seeded award queues. Medal Recommendations (18) is on the list, so a milpac
// link in one of its tickets raises no alert.
check(
    'a listed area is suppressed',
    MilpacResolver::isSuppressedArea([17, 18, 20, 21], 18) === true
);

// S1 Personnel Administration (5) was deliberately left off the seed, so it still
// alerts. Anything not named on the list is untouched.
check(
    'an unlisted area is not suppressed',
    MilpacResolver::isSuppressedArea([17, 18, 20, 21], 5) === false
);

// Both ends of the seeded list, so an off-by-one in a range/slice reading of the
// deny-list cannot pass.
check(
    'the first and last entries of the deny-list both suppress',
    MilpacResolver::isSuppressedArea([17, 18, 20, 21], 17) === true
        && MilpacResolver::isSuppressedArea([17, 18, 20, 21], 21) === true
);

// A multi-select option round-trips through the request as STRINGS ("17"), and
// that is what XF stores and hands back in \XF::options(). The area id, read off an
// entity column, is an int. The predicate compares them numerically so the option's
// own storage shape cannot silently disable suppression.
check(
    'a deny-list stored as strings (the shape a multi-select option saves) still suppresses',
    MilpacResolver::isSuppressedArea(['17', '18', '20', '21'], 18) === true
);

// XF\Option\Forum::renderSelectMultiple offers a "(none)" choice whose value is 0,
// so an admin can save a 0 into the node deny-list. No post lives in node 0, and no
// ticket in category 0, so a 0 must never match — least of all an area the caller
// could not resolve (below).
check(
    'a 0 in the deny-list does not suppress a real area',
    MilpacResolver::isSuppressedArea([0, 18], 20) === false
);

// Fail OPEN on an unresolvable area. If a notifier cannot read its node or category
// (a missing relation), the area is not NAMED on the deny-list, and suppression is
// only ever an explicit act — so the alert fires, exactly as it does today. The
// containing surface decides what to do about the missing relation; this predicate
// does not invent a suppression the admin never configured.
check(
    'an unresolvable area (0) is never suppressed, even when 0 is on the list',
    MilpacResolver::isSuppressedArea([0, 17], 0) === false
);

// A non-empty list that simply does not name the area: the ordinary case on every
// forum node once the ticket categories are seeded.
check(
    'a deny-list of one does not suppress a different area',
    MilpacResolver::isSuppressedArea([325], 326) === false
);

// =========================================================================
// composition with the existing firing rules (§2.5)
// =========================================================================

/**
 * The recipients one surface actually alerts, composing the suppressed-area gate
 * with the rules that were already there. This is the decision each notifier
 * extension makes — gate on the place FIRST, and only then let the self-link,
 * dedup and cap rules pick from the milpac set — written once here so the
 * composition is exercised rather than described. That the notifiers really are
 * wired in this order is confirmed on the dev stack, not in CI.
 *
 * @param array<int|string> $denied the surface's deny-list
 */
function recipientsIn(array $denied, int $areaId, array $atUserIds, array $milpacUserIds, int $authorUserId, int $cap): array
{
    if (MilpacResolver::isSuppressedArea($denied, $areaId)) {
        return [];
    }

    return MilpacResolver::milpacRecipients($atUserIds, $milpacUserIds, $authorUserId, $cap);
}

// The leak this closes, end to end: a clerk opens a Medal Recommendations (18)
// ticket about member 6. Every existing rule says "alert 6" — 6 is not the author,
// is not @-mentioned, and sits well inside the cap — and the category deny-list
// overrides all of it.
check(
    'in a suppressed area nobody is alerted, though every other rule would fire',
    recipientsIn([17, 18, 20, 21], 18, [], [6], 5, 10) === []
);

// The same ticket in an unlisted category still alerts, so suppression costs
// nothing outside the areas the admin named.
check(
    'in an unlisted area the existing rules decide, unchanged',
    recipientsIn([17, 18, 20, 21], 5, [], [6], 5, 10) === [6]
);

// Suppression is a gate, not another filter competing with the rest: with the area
// suppressed the result is empty no matter how rich the milpac set is, and with it
// unlisted the self-link (author 5), the @-dedup (member 1) and the cap of 4 all
// still apply — the exact composition FiringRulesTest pins without an area.
check(
    'suppression overrides a set the rules would otherwise trim to three recipients',
    recipientsIn([21], 21, [1], [5, 1, 8, 9, 10, 11], 5, 4) === []
);
check(
    'unsuppressed, that same set still self-skips, @-dedups and caps',
    recipientsIn([21], 18, [1], [5, 1, 8, 9, 10, 11], 5, 4) === [8, 9, 10]
);

// An empty deny-list is the shipped forum-node state, so every node keeps the
// behaviour it had before this option existed.
check(
    'with an empty deny-list the rules run exactly as they did before the option existed',
    recipientsIn([], 325, [1], [5, 1, 8, 9, 10, 11], 5, 4)
        === MilpacResolver::milpacRecipients([1], [5, 1, 8, 9, 10, 11], 5, 4)
);

// Suppression is by place only: it consults neither the author nor the recipients,
// so swapping who wrote the message cannot change the gate's answer.
check(
    'the gate ignores who the author is — the place alone decides',
    recipientsIn([18], 18, [], [6, 7], 6, 10) === []
        && recipientsIn([18], 18, [], [6, 7], 99, 10) === []
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
