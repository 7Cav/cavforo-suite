<?php

/**
 * Issue #157 — exercises MemberCursor, the rule that advances the bulk member fetch
 * through a guild.
 *
 * Discord pages its guild member list with an `after` cursor: each request asks for
 * the members following a given id, and a page shorter than the limit is the last
 * one. This decides both halves — where the next request starts, and when to stop.
 *
 * The rule is separated from the fetch because getting it wrong is quiet. Too small
 * a cursor re-reads a page forever; too large steps over members who are then never
 * reconciled; a missed stop condition spends the guild's whole rate budget.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/MemberCursorTest.php
 */

namespace Cav7\DiscordSyncPatch\Tests;

require __DIR__ . '/../MemberCursor.php';

use Cav7\DiscordSyncPatch\MemberCursor;

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

// Real ids from this guild, which already mixes 18- and 19-digit snowflakes. Sorted
// as text the 18-digit "5..." beats the 19-digit "1...", so a cursor built with
// strcmp or SORT_STRING hands Discord an id it has already passed and the fetch
// re-reads the same page until the run is killed.
check(
    'the cursor is the greatest id, not the one that sorts last as text',
    MemberCursor::next(['581229370245644289', '1384909947564724397'], 2) === '1384909947564724397',
    'a text comparison would pick the 18-digit id and re-read the page forever'
);

// Adjacent snowflakes. These are equal once either side is put through a float, so a
// comparison that goes via one cannot tell them apart and may advance to the lower.
check(
    'two adjacent ids are told apart',
    MemberCursor::next(['1384909947564724398', '1384909947564724397'], 2) === '1384909947564724398',
    'ids this size lose their last digits as floats; the comparison must stay exact'
);

// Discord returns members in ascending id order, but the cursor rule must not rest
// on it: taking the last element of the page reads a property of the response rather
// than deciding anything, and it fails the moment the order is not what was assumed.
check(
    'an unordered page still yields its greatest id',
    MemberCursor::next(['300', '900', '100'], 3) === '900',
    'taking the last element assumes an ordering the rule should not depend on'
);

// The stop condition. Discord signals the end by returning fewer members than were
// asked for; without this the sweep asks for the page after the last one forever.
check(
    'a page shorter than the limit ends the walk',
    MemberCursor::next(['100', '200'], 3) === null,
    'a short page is the last page'
);
check(
    'an empty page ends the walk',
    MemberCursor::next([], 3) === null,
    'a guild whose members are exhausted must not be asked again'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
