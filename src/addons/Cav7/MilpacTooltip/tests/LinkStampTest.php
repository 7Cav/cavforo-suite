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
// relative/-slug recognition. Recognition stays host-agnostic on purpose; the
// foreign-host decision is a SEPARATE gate (isSameOriginLink, issue #126), exercised
// in its own section below, so both stay independently testable.
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
// origin gate — isSameOriginLink (issue #126): stamp only links at THIS board
// =========================================================================
// relationIdFromUrl (above) stays host-agnostic; it recognises the roster path
// wherever it renders. The same-origin decision lives in this separate, pure gate,
// so a cross-board /rosters/profile/<n>/ link is still recognised as a roster path
// but left unstamped — no local data-user-id, so no wrong (local) hovercard on hover.
// getRenderedLink hands the same href string to the gate for every link shape —
// relative, canonical absolute, -slug, named [URL=...], bare auto-linked — so
// exercising the url string here covers all of those forms uniformly.

$board = 'https://board.example';

// A relative link carries no host and always points at this board — stampable.
check(
    'a relative /rosters/profile/42/ is same-origin (no host = always local)',
    RosterLink::isSameOriginLink('/rosters/profile/42/', $board) === true
);

// The -slug relative variant is likewise local.
check(
    'a relative -slug /rosters/profile/42-grayson-j/ is same-origin',
    RosterLink::isSameOriginLink('/rosters/profile/42-grayson-j/', $board) === true
);

// A canonical absolute link to this board (host matches boardUrl) — stampable.
check(
    'a canonical https://board.example/rosters/profile/42/ matches the board host',
    RosterLink::isSameOriginLink('https://board.example/rosters/profile/42/', $board) === true
);

// The canonical -slug absolute variant on this board is also same-origin.
check(
    'a canonical -slug absolute link on the board host is same-origin',
    RosterLink::isSameOriginLink('https://board.example/rosters/profile/42-grayson-j/', $board) === true
);

// THE BUG (issue #126): a cross-board absolute link whose relation_id happens to
// match a local milpac must NOT be treated as local — different host, no stamp.
check(
    'a foreign https://other-board/rosters/profile/42/ is NOT same-origin',
    RosterLink::isSameOriginLink('https://other-board/rosters/profile/42/', $board) === false
);

// A protocol-relative //host/ link carries a host, so a foreign one is off-board.
check(
    'a protocol-relative //other-board/rosters/profile/42/ is NOT same-origin',
    RosterLink::isSameOriginLink('//other-board/rosters/profile/42/', $board) === false
);

// ...and a protocol-relative link to the board host is same-origin.
check(
    'a protocol-relative //board.example/rosters/profile/42/ is same-origin',
    RosterLink::isSameOriginLink('//board.example/rosters/profile/42/', $board) === true
);

// Host comparison is case-insensitive (hostnames are), so a differently-cased host
// still matches — a link is not left unstamped over letter case alone.
check(
    'host match is case-insensitive (Board.Example matches board.example)',
    RosterLink::isSameOriginLink('https://Board.Example/rosters/profile/42/', $board) === true
);

// The decision is on the host, not the scheme: http vs https on the same host is
// still this board, so a mixed-scheme canonical link is not left unstamped.
check(
    'a same-host link on a different scheme (http vs https) is same-origin',
    RosterLink::isSameOriginLink('http://board.example/rosters/profile/42/', $board) === true
);

// The dev-stack shape: a board URL carrying a port, and a canonical link on it. The
// host ("localhost") is what matches; the port rides along in both and is ignored.
check(
    'a link on a board URL with a port (http://localhost:8081) is same-origin',
    RosterLink::isSameOriginLink('http://localhost:8081/rosters/profile/42/', 'http://localhost:8081') === true
);

// A different host is off-board even when the board URL carries a port.
check(
    'a foreign host is off-board even when the board URL has a port',
    RosterLink::isSameOriginLink('https://other-board/rosters/profile/42/', 'http://localhost:8081') === false
);

// Asymmetric port on the SAME host: the port rides along on only one side. The
// decision is on the host alone, so a legitimately-local link is still same-origin
// whether the link carries the port and the board does not, or vice-versa. Pins that
// a future "tighten to host:port" change cannot silently drop a ported local link.
check(
    'a link carrying a port on the board host is same-origin even when boardUrl has none',
    RosterLink::isSameOriginLink('http://board.example:8081/rosters/profile/42/', 'https://board.example') === true
);
check(
    'a portless link on the board host is same-origin even when boardUrl carries a port',
    RosterLink::isSameOriginLink('https://board.example/rosters/profile/42/', 'http://board.example:8081') === true
);

// Userinfo-spoof negative pin: a "user@host" authority resolves to the REAL host
// after the @ (evil.example here), not the board host before it, so the link is
// foreign and left unstamped. parse_url already does this correctly; this pins it so
// a future hand-rolled host parser cannot silently re-introduce the spoof (issue #126).
check(
    'a userinfo-spoofed https://board.example@evil.example/... link is NOT same-origin',
    RosterLink::isSameOriginLink('https://board.example@evil.example/rosters/profile/42/', 'https://board.example/') === false
);

// Defensive: an empty/misconfigured boardUrl has no host, so an ABSOLUTE link cannot
// be confirmed local and is left unstamped (safe: never stamp what we cannot verify).
check(
    'an absolute link with an empty boardUrl is not same-origin (cannot confirm)',
    RosterLink::isSameOriginLink('https://board.example/rosters/profile/42/', '') === false
);

// ...but a RELATIVE link is local regardless of the boardUrl, since it has no host.
check(
    'a relative link with an empty boardUrl is still same-origin',
    RosterLink::isSameOriginLink('/rosters/profile/42/', '') === true
);

// Fail CLOSED on a MALFORMED absolute link (issue #126). A foreign absolute URL
// that parse_url() cannot parse at all (whole-URL parse_url === false) must NOT be
// judged same-origin: doing so would resolve the local relation_id and stamp the
// WRONG (local) member's card on a link that clicks through off-board. This is
// distinct from a genuinely relative link (parses fine, no host) which stays local.
// These are user-triggerable via post content, so each garbage shape is pinned by
// VALUE, not merely asserted to be a bool.
check(
    'a malformed foreign link with an out-of-range port is NOT same-origin (fail closed)',
    RosterLink::isSameOriginLink('https://evil.example:99999/rosters/profile/42/', $board) === false
);
check(
    'a malformed foreign link with a non-numeric port is NOT same-origin (fail closed)',
    RosterLink::isSameOriginLink('https://evil.example:notaport/rosters/profile/42/', $board) === false
);
check(
    'a malformed http:///... link (empty authority) is NOT same-origin (fail closed)',
    RosterLink::isSameOriginLink('http:///rosters/profile/42/', $board) === false
);

// Total / fail-open: the gate still returns a bool and never throws on any garbage
// href — a post render must not break on a malformed URL (mirrors the \Throwable
// containment the renderer keeps around resolution and stamping). This stays a guard
// alongside the value pins above; it is no longer the ONLY assertion for malformed input.
check(
    'the gate returns a bool and never throws on a malformed url',
    (static function (): bool {
        return is_bool(RosterLink::isSameOriginLink('http://', 'https://board.example'))
            && is_bool(RosterLink::isSameOriginLink('ht!tp://%%%', ''));
    })()
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
