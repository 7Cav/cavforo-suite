<?php

/**
 * Issue #84 — the detection regex, exercised for real. MilpacResolver::extractRelationIds
 * pulls the relation_id out of every roster-profile link in a prepared message with
 * #/rosters/profile/(\d+)#, the one match that catches both a bare auto-linked URL and a
 * [URL=...] hyperlink, relative or canonical (spec §2.2). This is pure PHP, no XenForo, so
 * every branch runs here rather than being pinned by shape. If the regex drifts, the whole
 * engine silently stops detecting milpacs.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/DetectionTest.php
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

// A relative roster link (the shape most in-editor links take).
check(
    'a relative /rosters/profile/42/ yields [42]',
    MilpacResolver::extractRelationIds('check /rosters/profile/42/ out') === [42]
);

// A canonical absolute URL with a -slug suffix: the int is captured, the slug ignored.
check(
    'a canonical https://board/rosters/profile/42-slug/ yields [42]',
    MilpacResolver::extractRelationIds('https://board.example/rosters/profile/42-banfield-h/') === [42]
);

// Inside a [URL=...] hyperlink (the named-link shape 86% of existing links use).
check(
    "a [URL='.../rosters/profile/7/'] hyperlink yields [7]",
    MilpacResolver::extractRelationIds("[URL='https://board.example/rosters/profile/7/']Cpl Doe[/URL]") === [7]
);

// A bare auto-linked URL in running prose.
check(
    'a bare auto-linked .../rosters/profile/13/ yields [13]',
    MilpacResolver::extractRelationIds('see https://board.example/rosters/profile/13/ here') === [13]
);

// Several links in one message: all ids, in first-seen order.
check(
    'multiple links yield every id in first-seen order',
    MilpacResolver::extractRelationIds(
        'a /rosters/profile/42/ b /rosters/profile/7-x/ c /rosters/profile/99/'
    ) === [42, 7, 99]
);

// No roster link at all.
check(
    'a message with no roster link yields []',
    MilpacResolver::extractRelationIds('no roster links here, just /threads/123/ and text') === []
);

// The same id linked twice (bare then named) collapses to one entry, first-seen order kept.
check(
    'a repeated id is de-duplicated to a single entry',
    MilpacResolver::extractRelationIds(
        '/rosters/profile/42/ and again [URL=x/rosters/profile/42-foo/]F[/URL]'
    ) === [42]
);

// relation_id 0 does not exist; a /rosters/profile/0/ link resolves to nothing.
check(
    'a /rosters/profile/0/ link is dropped (no relation_id 0)',
    MilpacResolver::extractRelationIds('/rosters/profile/0/') === []
);

// Near-miss: the profile segment with no trailing id at all matches nothing —
// (\d+) needs at least one digit.
check(
    'a /rosters/profile/ with no id yields []',
    MilpacResolver::extractRelationIds('see /rosters/profile/ here') === []
);

// Near-miss: the plural "profiles" segment is a different route and must not match;
// the pattern requires the exact "/rosters/profile/" prefix before the digits.
check(
    'a /rosters/profiles/5/ (plural segment) yields []',
    MilpacResolver::extractRelationIds('/rosters/profiles/5/') === []
);

// The pattern is intentionally host-agnostic (#/rosters/profile/(\d+)#), so an
// off-host URL still matches. This documents the deliberate trade-off — matching
// on the path keeps the canonical "-slug" and relative in-editor shapes working —
// and guards against a "hardening" regression that anchored to the board host and
// broke those. In practice the roster path only exists on the board, so a foreign
// URL carrying it is not a real concern.
check(
    'an off-host https://evil.example/rosters/profile/42/ still matches (deliberate: path-only)',
    MilpacResolver::extractRelationIds('https://evil.example/rosters/profile/42/') === [42]
);

// Interleaved distinct + duplicate links in one message: every distinct id in
// first-seen order, the repeat collapsed. Tested here together (the earlier cases
// cover distinct and duplicate only separately).
check(
    'interleaved distinct and duplicate links yield each id once, first-seen order',
    MilpacResolver::extractRelationIds(
        '/rosters/profile/42/ /rosters/profile/7/ /rosters/profile/42/ /rosters/profile/99/'
    ) === [42, 7, 99]
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
