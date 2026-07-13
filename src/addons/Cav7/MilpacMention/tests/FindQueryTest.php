<?php

/**
 * Issue #88 — the pure logic behind the $name completer's find endpoint
 * (spec §4.3, §4.2, §4.5). The live query (username LIKE + isValidUser(true) +
 * the inner join to NF\Rosters:RosterUser) lives in
 * MilpacResolver::findMilpacOwningUsers and is pinned structurally by
 * FindWiringTest; this holds the parts that run in plain PHP:
 *
 *   isFindQueryLongEnough()  the q-length >= 2 guard, mirroring
 *                            XF\Pub\Controller\MemberController::actionFind
 *   milpacDisplayText()      "Rank Name" (or just the name when unranked), the
 *                            dropdown's primary line and the anchor's text (§4.2/§4.5)
 *   milpacLinkHtml()         the value to insert: a NAMED anchor, never a bare URL,
 *                            which Froala serialises to [URL='…']Rank Name[/URL] (§4.2)
 *   dedupeMilpacOwners()     the shared reducer the completer's raw duplicate detection
 *                            routes RAW roster rows through: collapse to one per member,
 *                            keep the lowest relation_id, and log a member owning more
 *                            than one as a data error (#112, §4.4)
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/FindQueryTest.php
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
// isFindQueryLongEnough — the q-length >= 2 guard (spec §4.3)
// A shorter q returns an empty result set upstream; two or more characters
// runs the query. Mirrors MemberController::actionFind's
// `$q !== '' && Str::strlen($q) >= 2`.
// =========================================================================
check('empty q is too short', MilpacResolver::isFindQueryLongEnough('') === false);
check('a single character is too short', MilpacResolver::isFindQueryLongEnough('a') === false);
check('two characters are long enough', MilpacResolver::isFindQueryLongEnough('ab') === true);
check('three characters are long enough', MilpacResolver::isFindQueryLongEnough('abc') === true);
// Str::strlen is multibyte-aware; two multibyte characters count as two, not their byte length.
check('two multibyte characters are long enough', MilpacResolver::isFindQueryLongEnough('éé') === true);
check('one multibyte character is too short', MilpacResolver::isFindQueryLongEnough('é') === false);

// =========================================================================
// milpacDisplayText — the primary line and anchor text (spec §4.2/§4.5)
// =========================================================================
check(
    'rank and name join with a single space',
    MilpacResolver::milpacDisplayText('Corporal', 'Banfield.H') === 'Corporal Banfield.H'
);
check(
    'a member with no rank shows the name alone (no leading space)',
    MilpacResolver::milpacDisplayText('', 'Recruit.J') === 'Recruit.J'
);
check(
    'a blank/whitespace rank collapses to the name alone',
    MilpacResolver::milpacDisplayText('   ', 'Recruit.J') === 'Recruit.J'
);
// Surrounding whitespace on the rank is fully trimmed (both ends), so a
// left-only-trim regression would leave a double space before the name.
check(
    'a rank padded on both sides is trimmed on both sides',
    MilpacResolver::milpacDisplayText('  Sergeant Major  ', 'Doe.J') === 'Sergeant Major Doe.J'
);

// =========================================================================
// milpacLinkHtml — the value to insert (spec §4.2): a named anchor, NOT a bare
// URL. The rich editor inserts this HTML; Froala serialises it back to
// [URL='…/rosters/profile/N/']Rank Name[/URL] on save, the exact artifact the
// engine already detects (#84 / §2.2 regex #/rosters/profile/(\d+)#).
// =========================================================================
$url = 'https://7cav.us/rosters/profile/123-corporal-banfieldh/';
$html = MilpacResolver::milpacLinkHtml('Corporal Banfield.H', $url);

check(
    'the insert value is a named anchor with the rank+name as its text',
    $html === '<a href="' . $url . '">Corporal Banfield.H</a>'
);
check(
    'the anchor is NOT a bare URL — the display name sits between the tags',
    str_contains($html, '>Corporal Banfield.H</a>') && !str_ends_with($html, '</a>' . $url)
);
check(
    'the href retains the literal /rosters/profile/<relation_id> path the engine regex matches',
    (bool) preg_match('#/rosters/profile/123#', $html)
);
// The builder must html-escape so a name or URL carrying a quote/ampersand cannot
// break out of the href attribute or the anchor. Output is HTML inserted into an editor.
$escaped = MilpacResolver::milpacLinkHtml('A & "B"', 'https://7cav.us/rosters/profile/9/?x=1&y=2');
check(
    'ampersands and quotes in the text are escaped',
    str_contains($escaped, 'A &amp; &quot;B&quot;')
);
check(
    'ampersands in the href are escaped',
    str_contains($escaped, 'profile/9/?x=1&amp;y=2')
);
// The display text is a text node, so < is the character markup neutralization
// exists to stop: a raw <b> would inject markup into the editor. Pin that angle
// brackets are escaped and no literal tag survives.
$markup = MilpacResolver::milpacLinkHtml('<b>x</b>', 'https://7cav.us/rosters/profile/9/');
check(
    'angle brackets in the text are escaped to entities',
    str_contains($markup, '&lt;b&gt;x&lt;/b&gt;')
);
check(
    'no literal <b> markup survives in the output',
    !str_contains($markup, '<b>')
);
// The builder uses ENT_QUOTES, so a single quote (not just a double quote) is
// escaped too — a name with an apostrophe cannot break a single-quoted context.
$singleQuote = MilpacResolver::milpacLinkHtml("O'Brien", 'https://7cav.us/rosters/profile/9/');
check(
    'single quotes in the text are escaped (ENT_QUOTES)',
    str_contains($singleQuote, 'O&#039;Brien') && !str_contains($singleQuote, "O'Brien")
);

// =========================================================================
// dedupeMilpacOwners — the completer's RAW duplicate detection (§4.4, #112)
// One milpac per user is the intended rule, but xf_nf_rosters_user does NOT enforce
// it (non-unique user_id index; live data has user 7385 with two rows). The completer
// cannot see this in its own result set: findMilpacOwningUsers INNER-joins the roster
// and XF's identity map keys the fetched collection by user_id, so the two rows
// collapse to one hydrated User (lowest milpac) BEFORE the view iterates — the dropdown
// is already correct, but the duplicate is invisible to it. logMilpacOwnerDuplicates
// therefore re-reads the RAW roster rows for the shown members
// (fetchColumns('user_id','relation_id'), ordered) and routes them through this reducer,
// which is where the data error is finally logged. That live query needs XenForo, so it
// is pinned structurally in FindWiringTest; here the reducer is exercised for real with
// the raw rows the query returns. The logger is injected so the data-error message is
// observable without a XenForo runtime; production passes \XF::logError.
// =========================================================================

/** A capturing logger, so the data-error log is observable without a XenForo runtime. */
function makeLog(array &$sink): callable
{
    return function (string $message) use (&$sink): void {
        $sink[] = $message;
    };
}

// The live duplicate (#112): user 7385 owns relation_ids 3603 and 4771. The raw roster
// query returns both rows for the shown member; feeding them through the reducer is what
// logs the data error the hydrated collection could not surface. (Row order here is the
// higher relation_id first, to prove the "lowest kept" pick does not depend on it; the
// production query orders ascending.)
$dupLog = [];
check(
    'raw rows for a member owning two roster rows collapse to ONE user_id',
    MilpacResolver::dedupeMilpacOwners(
        [
            ['user_id' => 7385, 'relation_id' => 4771],
            ['user_id' => 7385, 'relation_id' => 3603],
        ],
        makeLog($dupLog)
    ) === [7385],
    'the reducer names one member, not two rows presented as separate milpacs'
);
check(
    'the raw duplicate logs exactly once, naming the member and BOTH relation_ids',
    count($dupLog) === 1
        && str_contains($dupLog[0], '7385')
        && str_contains($dupLog[0], '3603')
        && str_contains($dupLog[0], '4771'),
    ($dupLog[0] ?? '(nothing logged)') . ' [count ' . count($dupLog) . ']'
);
// Selection is deterministic even though the higher relation_id (4771) came first in
// the raw rows: the LOWEST (3603) is the milpac the completer keeps, matching #96.
check(
    'the kept milpac is the lowest relation_id, regardless of raw row order',
    isset($dupLog[0]) && str_contains($dupLog[0], 'lowest (relation_id 3603)'),
    $dupLog[0] ?? '(nothing logged)'
);

// The normal case: the shown members each own exactly one roster row, so the raw rows
// carry one per member — not a data error, and nothing is logged.
$quietLog = [];
check(
    'raw rows of distinct single-milpac members each keep their milpac, first-seen order',
    MilpacResolver::dedupeMilpacOwners(
        [
            ['user_id' => 100, 'relation_id' => 5],
            ['user_id' => 250, 'relation_id' => 9],
        ],
        makeLog($quietLog)
    ) === [100, 250]
);
check(
    'the normal single-milpac path logs nothing',
    $quietLog === [],
    count($quietLog) . ' unexpected log(s)'
);

// The production shape: a dropdown showing several single-milpac members alongside the
// one duplicate (user 7385, with both rows returned by the scoped raw query). Only the
// duplicate is a data error, so exactly one log fires among the shown members, and every
// member still resolves to one user_id in first-seen order.
$mixedLog = [];
check(
    'a duplicate among single-milpac members still collapses everyone to one user_id each',
    MilpacResolver::dedupeMilpacOwners(
        [
            ['user_id' => 100, 'relation_id' => 5],
            ['user_id' => 7385, 'relation_id' => 3603],
            ['user_id' => 7385, 'relation_id' => 4771],
            ['user_id' => 250, 'relation_id' => 9],
        ],
        makeLog($mixedLog)
    ) === [100, 7385, 250]
);
check(
    'only the duplicate member logs, exactly once, among the shown members',
    count($mixedLog) === 1
        && str_contains($mixedLog[0], '7385')
        && str_contains($mixedLog[0], 'lowest (relation_id 3603)')
        && !str_contains($mixedLog[0], 'user_id 100')
        && !str_contains($mixedLog[0], 'user_id 250'),
    ($mixedLog[0] ?? '(nothing logged)') . ' [count ' . count($mixedLog) . ']'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
