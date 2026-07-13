<?php

/**
 * Issue #83 — pins the vendor-coupled wiring of the in-post milpac hovercard so a
 * regression fails CI rather than shipping a dead feature. The pure logic (link
 * recognition + anchor stamping) is exercised for real in LinkStampTest; this holds the
 * parts that need a live XenForo + NF/Rosters to actually run: the two class extensions
 * the feature hangs on, their _output exports, and the shape of the two extension classes.
 *
 *   XF\BbCode\Renderer\Html::getRenderedLink   stamps the roster anchor at render (§ ADR 0001)
 *   NF\Rosters\Pub\Controller\Roster::actionProfile   answers tooltip=1 -> member_tooltip
 *
 * The relation_id -> user_id resolution (RosterLink::resolveUserId) runs a live finder and
 * is integration-verified against the dev stack, not unit-pinned — the same way
 * MilpacResolver::resolveUserIds is left out of the pure suite.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/WiringTest.php
 */

namespace Cav7\MilpacTooltip\Tests;

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

$root = dirname(__DIR__);

/** All _output item files of a type, excluding the _metadata.json index. */
function outputItems(string $root, string $type): array
{
    return array_values(array_filter(
        glob("$root/_output/$type/*") ?: [],
        fn ($f) => basename($f) !== '_metadata.json'
    ));
}

// =========================================================================
// addon.json — identity (the two extensions live under this add-on)
// =========================================================================
$addon = json_decode((string) @file_get_contents("$root/addon.json"), true);
check('addon.json is valid JSON', is_array($addon));
if (is_array($addon)) {
    check('title is "7Cav - Milpac Tooltip"', ($addon['title'] ?? '') === '7Cav - Milpac Tooltip');
    check(
        'hard-requires NF/Rosters 2.1+ (2010000)',
        (int) ($addon['require']['NF/Rosters'][0] ?? 0) === 2010000,
        'the hovercard resolves against NF\\Rosters:RosterUser and reuses the roster URL'
    );
}

// =========================================================================
// class_extensions — the two seams the hovercard hangs on (§ ADR 0001)
// =========================================================================
$classExtXml = @simplexml_load_file("$root/_data/class_extensions.xml");
check('_data/class_extensions.xml could be read', $classExtXml !== false);

$extByFrom = [];
if ($classExtXml !== false) {
    foreach ($classExtXml->extension as $ext) {
        $extByFrom[(string) $ext['from_class']] = [
            'to' => (string) $ext['to_class'],
            'active' => (string) $ext['active'],
        ];
    }
}

$expectedExtensions = [
    // Stamp the roster anchor at render — the choke point every [URL] anchor passes
    // through, so named, bare, $name-inserted and hand-typed links are covered uniformly.
    'XF\BbCode\Renderer\Html' => 'Cav7\MilpacTooltip\XF\BbCode\Renderer\Html',
    // Serve the member_tooltip from the roster URL: answer tooltip=1 by resolving
    // relation_id -> user_id and handing off to MemberController::actionTooltip.
    'NF\Rosters\Pub\Controller\Roster' => 'Cav7\MilpacTooltip\NF\Rosters\Pub\Controller\Roster',
];
foreach ($expectedExtensions as $from => $to) {
    check(
        "$from is extended to $to and active",
        isset($extByFrom[$from])
            && $extByFrom[$from]['to'] === $to
            && $extByFrom[$from]['active'] === '1',
        'a rename or a dropped extension ships a dead feature'
    );
}
check(
    'exactly the two hovercard extensions are registered (renderer stamp + roster handoff)',
    ($classExtXml !== false ? count($classExtXml->extension) : -1) === 2
);
check(
    '_output has one class_extensions file per _data extension',
    count(outputItems($root, 'class_extensions')) === ($classExtXml !== false ? count($classExtXml->extension) : -1),
    'check-data-consistency content-checks these; _data and _output must agree'
);

// The _output exports must describe the same two extensions (content, not just count),
// and the _metadata.json index must list each exported file — a missing entry means the
// export was hand-faked. check-data-consistency also content-checks these; pin them here
// so a hand-edit of one side fails this focused test too.
$extMeta = json_decode((string) @file_get_contents("$root/_output/class_extensions/_metadata.json"), true);
check('_output/class_extensions/_metadata.json is valid JSON', is_array($extMeta));
foreach ($expectedExtensions as $from => $to) {
    $file = str_replace('\\', '-', $from) . '_' . str_replace('\\', '-', $to) . '.json';
    $decoded = json_decode((string) @file_get_contents("$root/_output/class_extensions/$file"), true);
    check(
        "the _output export $file describes $from -> $to, active",
        is_array($decoded)
            && ($decoded['from_class'] ?? '') === $from
            && ($decoded['to_class'] ?? '') === $to
            && ($decoded['active'] ?? null) === true,
        '_data and _output must agree on the extension'
    );
    check(
        "the _output/class_extensions/_metadata.json indexes $file",
        is_array($extMeta) && isset($extMeta[$file])
    );
}

// =========================================================================
// the renderer extension — stamps the roster anchor at render (§ ADR 0001)
// =========================================================================
$htmlSrc = (string) @file_get_contents("$root/XF/BbCode/Renderer/Html.php");
check('XF/BbCode/Renderer/Html.php exists', $htmlSrc !== '');
check(
    'the renderer extension lives in the Cav7\MilpacTooltip\XF\BbCode\Renderer namespace',
    (bool) preg_match('/namespace\s+Cav7\\\\MilpacTooltip\\\\XF\\\\BbCode\\\\Renderer\s*;/', $htmlSrc)
);
check(
    'Html extends the XFCP proxy so it augments rather than shadows the core renderer',
    (bool) preg_match('/class\s+Html\s+extends\s+XFCP_Html/', $htmlSrc)
);
check(
    'it overrides getRenderedLink with the installed protected ($text, $url, array $options) signature',
    (bool) preg_match('/protected\s+function\s+getRenderedLink\s*\(\s*\$text\s*,\s*\$url\s*,\s*array\s+\$options\s*\)/', $htmlSrc),
    'a mismatched signature would fatal when XF renders any [URL] anchor'
);
check(
    'it renders through the parent first, then augments the returned anchor',
    (bool) preg_match('/parent::getRenderedLink\(\s*\$text\s*,\s*\$url\s*,\s*\$options\s*\)/', $htmlSrc),
    'the stock link markup is produced by the parent; the extension only stamps it'
);
check(
    'it recognises the roster-profile link, resolves the member, and stamps the anchor via RosterLink',
    str_contains($htmlSrc, 'RosterLink::relationIdFromUrl')
        && str_contains($htmlSrc, 'RosterLink::resolveUserId')
        && str_contains($htmlSrc, 'RosterLink::stampAnchor'),
    'recognition + resolution + stamping run through the shared, unit-tested helper'
);
check(
    'it gates stamping on the same-origin decision, reading the boardUrl option (issue #126)',
    str_contains($htmlSrc, 'RosterLink::isSameOriginLink')
        && (bool) preg_match('/options\(\)\s*->\s*boardUrl/', $htmlSrc),
    'a cross-board /rosters/profile/<n>/ link must be recognised but left unstamped, not stamped with the local member'
);
check(
    'stamping is contained: a \Throwable is caught and forwarded to logException, never rethrown',
    (bool) preg_match('/catch\s*\(\s*\\\\Throwable\b.*?logException\(/s', $htmlSrc)
        && !preg_match('/\bthrow\b/', preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $htmlSrc)),
    'getRenderedLink runs on every link of every render; a finder/DB fault must not break the post render'
);

// =========================================================================
// the controller extension — answers tooltip=1 with the member_tooltip (§ ADR 0001)
// =========================================================================
$rosterSrc = (string) @file_get_contents("$root/NF/Rosters/Pub/Controller/Roster.php");
check('NF/Rosters/Pub/Controller/Roster.php exists', $rosterSrc !== '');
check(
    'the controller extension lives in the Cav7\MilpacTooltip\NF\Rosters\Pub\Controller namespace',
    (bool) preg_match('/namespace\s+Cav7\\\\MilpacTooltip\\\\NF\\\\Rosters\\\\Pub\\\\Controller\s*;/', $rosterSrc)
);
check(
    'Roster extends the XFCP proxy so it augments rather than shadows the vendor controller',
    (bool) preg_match('/class\s+Roster\s+extends\s+XFCP_Roster/', $rosterSrc)
);
check(
    'it overrides actionProfile',
    (bool) preg_match('/function\s+actionProfile\s*\(/', $rosterSrc)
);
check(
    'it answers only the tooltip=1 request, deferring to the parent otherwise',
    (bool) preg_match("/filter\(\s*'tooltip'\s*,\s*'bool'\s*\)/", $rosterSrc)
        && (bool) preg_match('/parent::actionProfile\(\s*\$params\s*\)/', $rosterSrc),
    'a normal click still renders the full roster profile; only tooltip=1 is intercepted'
);
check(
    'it resolves relation_id -> user_id through the shared RosterLink finder',
    str_contains($rosterSrc, 'RosterLink::resolveUserId'),
    'the captured integer is the relation_id, not the user_id; a lookup is required'
);
check(
    'it hands off to MemberController::actionTooltip so the card is the stock member_tooltip',
    (bool) preg_match('/->actionTooltip\(/', $rosterSrc)
        && str_contains($rosterSrc, 'MemberController'),
    'the hovercard is XenForo\'s own member_tooltip, not a bespoke card (ADR 0001)'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
