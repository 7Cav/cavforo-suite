<?php

/**
 * Issue #123 — the server-side typed-$name matcher, exercised for real.
 * MilpacResolver::resolveTypedMilpacs rewrites a bare typed $username sitting at a
 * word boundary into the member's named roster link, the $-sigil twin of the way
 * stock XenForo resolves a typed @username in XF\Str\MentionFormatter. The member
 * lookup is injected, so the whole $-boundary/consume/shape decision runs in plain
 * PHP with no XenForo, mirroring the confirmed @ parity table (spec §Solution).
 *
 * The lookbehind mirrors @'s (^ | whitespace | ] ( , / ' " | --), the $ is consumed
 * on a hit, and the emitted artifact is the SAME named "Rank Name" anchor the #89
 * dropdown inserts — [URL='…/rosters/profile/<relation_id>/']Rank Name[/URL], never a
 * bare URL — so a typed $name and a picked $name serialise identically and the
 * phase-1 engine (extractRelationIds) detects both the same way.
 *
 * The context-exclusion cases ([CODE], [PLAIN], [URL=…]) are NOT asserted here: that
 * exclusion is XenForo's placeholder machinery wrapped around this pure pass in the
 * MentionFormatter extension, not our regex, so it belongs at the integration seam.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/TypedMilpacTest.php
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

/**
 * A stand-in for the extension's live milpac lookup: maps a handful of exact
 * usernames (case-insensitively, as the DB collation does) to the already-shaped
 * ['url' => …, 'text' => "Rank Name"] a milpac holder resolves to. Anything else —
 * a non-member token, a member who owns no milpac — returns null and stays literal.
 */
function milpacLookup(): callable
{
    $holders = [
        'markel.z'   => ['url' => 'https://board.example/rosters/profile/42/', 'text' => 'Corporal Markel.Z'],
        'treck.m'    => ['url' => 'https://board.example/rosters/profile/7/',  'text' => 'Sergeant Treck.M'],
        'banfield.h' => ['url' => 'https://board.example/rosters/profile/13/', 'text' => 'Private Banfield.H'],
    ];

    return static function (string $username) use ($holders): ?array {
        return $holders[strtolower($username)] ?? null;
    };
}

$lookup = milpacLookup();

$markelLink = "[URL='https://board.example/rosters/profile/42/']Corporal Markel.Z[/URL]";
$treckLink = "[URL='https://board.example/rosters/profile/7/']Sergeant Treck.M[/URL]";

// ---------------------------------------------------------------------------
// The @ parity table, run over $ (spec §Solution). Same resolve/skip decisions;
// only what a token resolves TO differs (a roster link, not a user chip).
// ---------------------------------------------------------------------------

// hey $user (word boundary) -> resolves.
check(
    'a $username at a word boundary resolves to its named roster link',
    MilpacResolver::resolveTypedMilpacs('hey $markel.z', $lookup) === "hey $markelLink"
);

// me$user (mid-word, no boundary) -> stays literal, exactly as me@user does.
check(
    'a $username mid-word (no boundary) stays literal',
    MilpacResolver::resolveTypedMilpacs('me$markel.z', $lookup) === 'me$markel.z'
);

// A literal dollar amount glued to a word is mid-word too; it never triggers.
check(
    'a mid-word "cost$5" stays literal',
    MilpacResolver::resolveTypedMilpacs('it cost$5 today', $lookup) === 'it cost$5 today'
);

// ($user) (after an opening paren) -> resolves; the trailing ) is preserved.
check(
    'a $username after "(" resolves and keeps the closing ")"',
    MilpacResolver::resolveTypedMilpacs('(see $markel.z)', $lookup) === "(see $markelLink)"
);

// A $username right after an apostrophe (in the lookbehind set) resolves; the
// trailing apostrophe is preserved outside the link.
check(
    'a $username after an apostrophe resolves',
    MilpacResolver::resolveTypedMilpacs("'\$treck.m'", $lookup) === "'" . $treckLink . "'"
);

// A token matching no member ($var, $5) -> stays literal.
check(
    'a $token matching no member ($var) stays literal',
    MilpacResolver::resolveTypedMilpacs('use $var here', $lookup) === 'use $var here'
);
check(
    'a bare $5 stays literal',
    MilpacResolver::resolveTypedMilpacs('costs $5 total', $lookup) === 'costs $5 total'
);

// A username the lookup rejects (e.g. a real member who owns NO milpac -> null)
// stays literal: there is no roster profile to point at.
check(
    'a member with no milpac (lookup returns null) stays literal',
    MilpacResolver::resolveTypedMilpacs('hi $nomilpac ok', $lookup) === 'hi $nomilpac ok'
);

// Multiple tokens each resolve to their own link.
check(
    'multiple $usernames each resolve to their own link',
    MilpacResolver::resolveTypedMilpacs('$markel.z and $treck.m', $lookup) === "$markelLink and $treckLink"
);

// The $ is consumed on a hit — the resolved output carries no leading $ sigil.
check(
    'the $ sigil is consumed on resolution (no $ remains in the link)',
    !str_contains(MilpacResolver::resolveTypedMilpacs('$markel.z', $lookup), '$markel')
);

// The emitted artifact is the NAMED "Rank Name" anchor to the roster profile,
// never a bare URL, matching the #89 dropdown insert byte-for-byte.
$only = MilpacResolver::resolveTypedMilpacs('$markel.z', $lookup);
check(
    'the emitted artifact is the named "Rank Name" roster link, never a bare URL',
    $only === $markelLink
        && str_contains($only, "[URL='https://board.example/rosters/profile/42/']Corporal Markel.Z[/URL]"),
    $only
);

// Trailing sentence punctuation is not swallowed into the username.
check(
    'a trailing period after the $username is preserved outside the link',
    MilpacResolver::resolveTypedMilpacs('ping $treck.m.', $lookup) === 'ping ' . $treckLink . '.'
);
check(
    'a trailing comma after the $username is preserved outside the link',
    MilpacResolver::resolveTypedMilpacs('$markel.z, hello', $lookup) === "$markelLink, hello"
);

// Case-insensitive exact match, as the DB collation gives @ (the lookup lower-cases).
check(
    'a $Username resolves case-insensitively',
    MilpacResolver::resolveTypedMilpacs('$Markel.Z', $lookup) === $markelLink
);

// A $ at the very start of the string is a boundary (^ in the lookbehind).
check(
    'a $username at the very start of the message resolves',
    MilpacResolver::resolveTypedMilpacs('$markel.z leads', $lookup) === "$markelLink leads"
);

// A repeated member renders BOTH links (rendering is uncapped), and the phase-1
// engine collapses the two links to ONE alert target — the existing dedup, unchanged.
$repeated = MilpacResolver::resolveTypedMilpacs('$markel.z again $markel.z', $lookup);
check(
    'a repeated $username renders both links (rendering is uncapped)',
    $repeated === "$markelLink again $markelLink"
);
check(
    'the repeated links collapse to one relation_id through the existing engine',
    MilpacResolver::extractRelationIds($repeated) === [42],
    'typed-$name output must feed the existing extractRelationIds dedup so one member = one alert'
);

// A message with no $token is returned untouched.
check(
    'a message with no $token is unchanged',
    MilpacResolver::resolveTypedMilpacs('nothing to see here', $lookup) === 'nothing to see here'
);

// ---------------------------------------------------------------------------
// Boundary/branch pins (issue #123 review): the $-matcher edges the parity table
// above does not already exercise — the empty-core guard, the [ ] token-stop, the
// remaining lookbehind alternatives (] , / " --), and adjacent-$ handling. These
// behaviours already match stock @; the pins fail CI if a future edit drops a
// boundary alternative or the empty-core guard.
// ---------------------------------------------------------------------------

// An all-punctuation token rtrims to '' (the empty-core guard) and is returned
// literal — the token matched, but its core is empty so there is nothing to look up.
check(
    'an all-punctuation token "$!" trims to empty core and stays literal',
    MilpacResolver::resolveTypedMilpacs('hey $! there', $lookup) === 'hey $! there'
);
check(
    'a "$..." token trims to empty core and stays literal',
    MilpacResolver::resolveTypedMilpacs('$...', $lookup) === '$...'
);

// A lone "$" before whitespace never even matches: the token class ([^\s$\[\]]+)
// requires at least one non-space character right after the "$".
check(
    'a lone "$" before whitespace never matches (token class needs a non-space char)',
    MilpacResolver::resolveTypedMilpacs('pay $ 5', $lookup) === 'pay $ 5'
);

// The "]" lookbehind alternative in isolation: a $name immediately after a closing
// BBCode tag sits at a boundary and resolves.
check(
    'a $username right after a "]" resolves (the "]" lookbehind alternative)',
    MilpacResolver::resolveTypedMilpacs('[b]hi[/b]$markel.z', $lookup) === "[b]hi[/b]$markelLink"
);

// The token class excludes "[" and "]", so a $name glued to an opening tag resolves
// and the following BBCode is left intact (the token stops at the "[").
check(
    'a $username glued to "[b]…[/b]" resolves and leaves the tag intact',
    MilpacResolver::resolveTypedMilpacs('$markel.z[b]bold[/b]', $lookup) === $markelLink . '[b]bold[/b]'
);

// The remaining lookbehind alternatives ( , / " -- ) each open a boundary, exactly
// as @ rides them; one loop over the isolating inputs.
$boundaryPrefixes = [
    'note--$markel.z' => 'note--',
    'a/$markel.z'     => 'a/',
    'say "$markel.z'  => 'say "',
    'x,$markel.z'     => 'x,',
];
foreach ($boundaryPrefixes as $input => $prefix) {
    $out = MilpacResolver::resolveTypedMilpacs($input, $lookup);
    check(
        "a \$username after the boundary in \"$input\" resolves to its roster link",
        $out === $prefix . $markelLink,
        $out
    );
}

// Adjacent-$: the second "$" in "$markel.z$treck.m" is preceded by "z", not a
// boundary, so only the first resolves and "$treck.m" stays literal.
check(
    'only the first of two glued $usernames resolves (the second $ has no boundary)',
    MilpacResolver::resolveTypedMilpacs('$markel.z$treck.m', $lookup) === $markelLink . '$treck.m'
);
// "$$markel.z": the first "$" is followed by "$" (token class excludes it) and the
// second "$" is preceded by "$" (not a boundary), so the whole token stays literal.
check(
    'a "$$"-prefixed token stays fully literal (neither $ sits at a boundary)',
    MilpacResolver::resolveTypedMilpacs('$$markel.z', $lookup) === '$$markel.z'
);

// ---------------------------------------------------------------------------
// The shaping helper in isolation: the exact BBCode artifact, mirroring the JS
// completer's anchorToBbCode so a typed link and a picked link are identical.
// ---------------------------------------------------------------------------
check(
    'milpacLinkBbCode builds [URL=\'url\']text[/URL] verbatim (matches the completer artifact)',
    MilpacResolver::milpacLinkBbCode('Corporal Markel.Z', 'https://board.example/rosters/profile/42/')
        === "[URL='https://board.example/rosters/profile/42/']Corporal Markel.Z[/URL]"
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
