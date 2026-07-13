<?php

/**
 * Issue #83 — the in-post milpac hovercard's primary seam, exercised for real.
 * RosterLink recognises a rendered roster-profile link, pulls its relation_id, and
 * stamps the anchor so XenForo's own XF.MemberTooltip drives the card on hover. All
 * three are pure PHP (no XenForo), so every branch runs here rather than being pinned
 * by shape. If the recognition regex or the stamping drifts, an in-post roster link
 * silently stops getting the hovercard.
 *
 * The recognition regex #/rosters/profile/(\d+)# is deliberately DUPLICATED here from
 * Cav7\MilpacMention\MilpacResolver::extractRelationIds (ADR 0002 keeps the two addons
 * independent bounded contexts; the shared Cav7/Core is a stub, not a home for one line
 * of regex). This test guards MilpacTooltip's own copy.
 *
 * The relation_id -> user_id resolution (RosterLink::resolveUserId) is NOT pinned here:
 * it runs a live NF\Rosters:RosterUser finder and is integration-verified against the
 * dev stack, exactly as MilpacResolver::resolveUserIds is left out of the pure suite.
 * Requiring this file and calling only the pure methods is XenForo-free.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/LinkStampTest.php
 */

namespace Cav7\MilpacTooltip\Tests;

require __DIR__ . '/../RosterLink.php';

use Cav7\MilpacTooltip\RosterLink;

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
// recognition — relationIdFromUrl / isRosterProfileLink (the case table, spec)
// =========================================================================

// A relative roster link (the shape most in-editor links take).
check(
    'a relative /rosters/profile/42/ is recognised as relation_id 42',
    RosterLink::relationIdFromUrl('/rosters/profile/42/') === 42
        && RosterLink::isRosterProfileLink('/rosters/profile/42/') === true
);

// A canonical absolute URL with a -slug suffix: the int is captured, the slug ignored.
check(
    'a canonical https://board/rosters/profile/42-grayson-j/ yields 42 (slug ignored)',
    RosterLink::relationIdFromUrl('https://board.example/rosters/profile/42-grayson-j/') === 42
);

// The href of a [URL=...] named hyperlink (86% of existing links use this shape); the
// renderer hands getRenderedLink the URL, so recognition sees the same string.
check(
    "a named [URL='.../rosters/profile/7/'] link's href yields 7",
    RosterLink::relationIdFromUrl('https://board.example/rosters/profile/7/') === 7
);

// A bare auto-linked URL in running prose.
check(
    'a bare auto-linked https://board/rosters/profile/13/ yields 13',
    RosterLink::relationIdFromUrl('https://board.example/rosters/profile/13/') === 13
);

// A non-profile roster URL (a whole roster listing) must NOT match — only actual
// milpac profile links get the treatment (spec: non-profile roster links out of scope).
check(
    'a non-profile /rosters/positions/5/ URL is not a roster-profile link',
    RosterLink::relationIdFromUrl('https://board.example/rosters/positions/5/') === 0
        && RosterLink::isRosterProfileLink('https://board.example/rosters/positions/5/') === false
);

// relation_id 0 does not exist; a /rosters/profile/0/ link resolves to nothing.
check(
    'a /rosters/profile/0/ link is not a milpac link (no relation_id 0)',
    RosterLink::relationIdFromUrl('/rosters/profile/0/') === 0
        && RosterLink::isRosterProfileLink('/rosters/profile/0/') === false
);

// Near-miss: the plural "profiles" segment is a different route and must not match.
check(
    'a /rosters/profiles/5/ (plural segment) is not a roster-profile link',
    RosterLink::relationIdFromUrl('https://board.example/rosters/profiles/5/') === 0
);

// Near-miss: the profile segment with no trailing id matches nothing — (\d+) needs a digit.
check(
    'a /rosters/profile/ with no id is not a roster-profile link',
    RosterLink::relationIdFromUrl('/rosters/profile/') === 0
);

// A link elsewhere on the board is not a milpac link.
check(
    'an unrelated /threads/123/ link is not a roster-profile link',
    RosterLink::relationIdFromUrl('https://board.example/threads/123/') === 0
);

// The pattern is intentionally host-agnostic (#/rosters/profile/(\d+)#), so an
// off-host URL still matches. This mirrors the sibling MilpacMention detection pin
// (tests/DetectionTest.php) and documents the deliberate trade-off — matching on the
// path keeps the canonical "-slug" and relative in-editor shapes working — guarding
// against a "hardening" regression that anchors to the board host and silently breaks
// relative/-slug recognition. (Recognition only; a foreign-host stamp guard is a
// separate followup, not added here.)
check(
    'an off-host https://evil.example/rosters/profile/42/ still yields 42 (deliberate: path-only)',
    RosterLink::relationIdFromUrl('https://evil.example/rosters/profile/42/') === 42
);

// A trailing-slash-less link still recognises the id — the (\d+) captures on the
// digits directly, not on a following "/".
check(
    'a /rosters/profile/42 with no trailing slash yields 42',
    RosterLink::relationIdFromUrl('/rosters/profile/42') === 42
);

// A query string or a fragment after the id does not interfere — the match ends at
// the captured digits, so anything trailing is ignored.
check(
    'a /rosters/profile/42/ with a ?query or #fragment suffix still yields 42',
    RosterLink::relationIdFromUrl('/rosters/profile/42/?tab=activity') === 42
        && RosterLink::relationIdFromUrl('/rosters/profile/42/#bio') === 42
);

// Characterization: the pattern is case-sensitive (no i flag), so an upper-cased path
// does NOT match. Locked so a stray i flag can't silently widen matching.
check(
    'an upper-cased /Rosters/Profile/42/ path is not recognised (regex is case-sensitive)',
    RosterLink::relationIdFromUrl('/Rosters/Profile/42/') === 0
);

// =========================================================================
// stamping — stampAnchor injects the member-tooltip init + resolved user_id
// =========================================================================

// The default client behaviour (spec): stamp data-xf-init="member-tooltip" and the
// resolved data-user-id, and NOTHING else — no username class, no styling — so the link
// looks the same at rest and XF.MemberTooltip drives the card on hover. The href is
// untouched, so a click still opens the roster profile.
check(
    'stamping a canonical roster anchor injects the member-tooltip init and the resolved user_id',
    RosterLink::stampAnchor('<a href="/rosters/profile/42/">First Lieutenant Grayson.J</a>', 100)
        === '<a data-xf-init="member-tooltip" data-user-id="100" href="/rosters/profile/42/">First Lieutenant Grayson.J</a>'
);

// Stamping leaves the href and every existing attribute (rel, class, target, proxy)
// intact — it only adds the two data attributes to the opening tag.
check(
    'stamping preserves the href and existing attributes, adding only the two data attributes',
    RosterLink::stampAnchor(
        '<a href="https://board.example/rosters/profile/7-doe/" rel="nofollow ugc">Cpl Doe</a>',
        250
    ) === '<a data-xf-init="member-tooltip" data-user-id="250" href="https://board.example/rosters/profile/7-doe/" rel="nofollow ugc">Cpl Doe</a>'
);

// The at-rest appearance is unchanged: no username class is added, and the href is
// unchanged, so a click still opens the roster profile (spec user stories 4 and 5).
check(
    'stamping adds no username class and does not change the href',
    (static function (): bool {
        $out = RosterLink::stampAnchor('<a href="/rosters/profile/42/">x</a>', 100);
        return !str_contains($out, 'class="username"')
            && str_contains($out, 'href="/rosters/profile/42/"');
    })()
);

// A member that could not be resolved (user_id 0) is not stamped — the link stays a
// plain anchor, which XenForo renders with no hovercard (spec user story 17: a deleted
// or invalid milpac shows no card, failing quietly).
check(
    'an unresolved user_id (0) leaves the anchor unstamped',
    RosterLink::stampAnchor('<a href="/rosters/profile/42/">x</a>', 0)
        === '<a href="/rosters/profile/42/">x</a>'
);

// A negative user_id (defensive) is likewise not stamped.
check(
    'a negative user_id leaves the anchor unstamped',
    RosterLink::stampAnchor('<a href="/rosters/profile/42/">x</a>', -5)
        === '<a href="/rosters/profile/42/">x</a>'
);

// Defensive: a string with no anchor tag is returned unchanged rather than corrupted.
check(
    'a string with no anchor is returned unchanged',
    RosterLink::stampAnchor('no anchor here', 100) === 'no anchor here'
);

// A rendered roster link whose label is itself a rendered link produces nested
// anchors; stampAnchor stamps only the FIRST opening tag (the , 1 replacement
// limit), so the outer anchor gets the member-tooltip init and the inner anchor is
// left untouched. This pins the single-replacement limit: dropping it would stamp
// every nested <a and double up the data attributes.
check(
    'stamping a nested-anchor string marks the outer anchor only, leaving the inner untouched',
    (static function (): bool {
        $in  = '<a href="/rosters/profile/42/">see <a href="/rosters/profile/9/">x</a></a>';
        $out = RosterLink::stampAnchor($in, 100);
        return $out === '<a data-xf-init="member-tooltip" data-user-id="100" href="/rosters/profile/42/">see <a href="/rosters/profile/9/">x</a></a>'
            && substr_count($out, 'data-xf-init="member-tooltip"') === 1;
    })()
);

// Fail-open (issue #83 review): preg_replace returns null on a PCRE-level failure
// (backtrack/recursion limit) instead of throwing, so the \Throwable guard in
// Html::getRenderedLink cannot catch it. stampAnchor must coalesce that null back to
// the unstamped input, failing open to the stock anchor, rather than returning null
// (which would make getRenderedLink return null for a @return string method and
// silently drop the whole link). We force a real PCRE failure by starving the
// backtrack limit for the one call, then restore it so no other case is affected.
check(
    'a PCRE engine failure during stamping fails open to the unstamped anchor (never null)',
    (static function (): bool {
        $anchor = '<a href="/rosters/profile/42/">First Lieutenant Grayson.J</a>';
        $saved  = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '0'); // force preg_replace() to return null
        try {
            $out = RosterLink::stampAnchor($anchor, 100);
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
        }
        return $out === $anchor;
    })()
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
