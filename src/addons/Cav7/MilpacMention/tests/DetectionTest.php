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

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
