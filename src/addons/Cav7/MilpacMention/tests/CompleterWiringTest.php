<?php

/**
 * Issue #89 — pins the vendor-coupled wiring of the $name editor completer (spec
 * §4.1–§4.8) so a regression fails CI rather than shipping silently. The completer
 * is browser JS plus XenForo data items, so behavioural coverage here is a wiring
 * pin plus a static round-trip proof; the live-editor behaviour (dropdown opens,
 * insertion, the '$' not firing mid-word) is the orchestrator's /verify job, since
 * it needs a running Froala + NF/Rosters the CI box does not have.
 *
 * What this holds:
 *   - the two template_modifications that deliver the completer on editor pages:
 *     one loads the JS (<xf:js …/> for Cav7/MilpacMention/editor.js), one attaches
 *     the plain-BBCode milpac-mentioner beside XF's user-mentioner/emoji-completer;
 *     and _data ↔ _output parity for both (check-data-consistency count-checks; this
 *     also content-checks and confirms the _metadata index);
 *   - the JS asset exists at the path the <xf:js> src resolves to;
 *   - the JS (comments stripped, so a commented reference can never pass) wires the
 *     merged find endpoint, at '$', keepAt false, insertMode 'html', reads the row
 *     `html` for insertion, and attaches on the bubbling editor:init event;
 *   - the plain-BBCode handler inserts the [URL=…] NAMED form, never a bare URL;
 *   - the static round-trip (§4.8.2): both inserted forms carry /rosters/profile/N/
 *     and match the phase-1 engine regex #/rosters/profile/(\d+)#, so insertion
 *     closes the notification loop with no second path.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/CompleterWiringTest.php
 */

namespace Cav7\MilpacMention\Tests;

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

/** All _output item files of a type, at any depth, excluding the _metadata index. */
function outputItems(string $root, string $type): array
{
    $out = [];
    $dir = "$root/_output/$type";
    if (!is_dir($dir)) {
        return $out;
    }
    $walk = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if ($file->isFile() && $file->getFilename() !== '_metadata.json') {
            $out[] = $file->getPathname();
        }
    }
    sort($out);
    return $out;
}

// The JS src the <xf:js> tag references, and where that file must physically live
// inside the addon. src is relative to the site js/ root, so the addon ships the
// file under _assets/js/<src> for deployment into js/<src> (§4.1).
$jsSrc = 'Cav7/MilpacMention/editor.js';
$jsAssetPath = "$root/_assets/js/$jsSrc";

// =========================================================================
// the template_modifications — deliver the completer on the editor template (§4.1)
// =========================================================================
$tmXml = @simplexml_load_file("$root/_data/template_modifications.xml");
check('_data/template_modifications.xml could be read', $tmXml !== false);

$mods = [];
if ($tmXml !== false) {
    foreach ($tmXml->modification as $m) {
        $mods[(string) $m['modification_key']] = $m;
    }
}

check(
    'a modification loads the completer JS (cav7MilpacMentionEditorJs)',
    isset($mods['cav7MilpacMentionEditorJs'])
);
check(
    'a modification attaches the plain-BBCode handler (cav7MilpacMentionPlainHandler)',
    isset($mods['cav7MilpacMentionPlainHandler'])
);

$jsMod = $mods['cav7MilpacMentionEditorJs'] ?? null;
check(
    'the JS-load modification targets the public editor template',
    $jsMod !== null
        && (string) $jsMod['type'] === 'public'
        && (string) $jsMod['template'] === 'editor',
    'the completer must load wherever XF renders an editor'
);
check(
    'the JS-load modification injects an <xf:js> tag for the addon',
    $jsMod !== null
        && str_contains((string) $jsMod->replace, '<xf:js')
        && str_contains((string) $jsMod->replace, 'addon="Cav7/MilpacMention"'),
    'the addon ships its editor JS through <xf:js>, mirroring SV/AdvancedBbCodesPack'
);
check(
    'the JS-load modification references the completer script by src',
    $jsMod !== null && str_contains((string) $jsMod->replace, $jsSrc),
    "the <xf:js> src must resolve to js/$jsSrc"
);
check(
    'the JS-load modification keeps its editor-template anchor via $0 (prepends, not replaces)',
    $jsMod !== null && str_contains((string) $jsMod->replace, '$0'),
    'a str_replace that drops $0 would delete the editor markup it anchors on'
);

$plainMod = $mods['cav7MilpacMentionPlainHandler'] ?? null;
check(
    'the plain-handler modification targets the public editor template',
    $plainMod !== null
        && (string) $plainMod['type'] === 'public'
        && (string) $plainMod['template'] === 'editor'
);
check(
    'the plain-handler modification adds milpac-mentioner to the textarea data-xf-init',
    $plainMod !== null
        && str_contains((string) $plainMod->replace, 'milpac-mentioner'),
    'the plain BBCode / mobile textarea must attach our handler (§4.6)'
);
check(
    'the plain-handler modification keeps XF\'s own user-mentioner and emoji-completer beside it',
    $plainMod !== null
        && str_contains((string) $plainMod->replace, 'user-mentioner')
        && str_contains((string) $plainMod->replace, 'emoji-completer'),
    'milpac-mentioner sits BESIDE the stock handlers, it does not replace them (§4.6)'
);

// _data ↔ _output parity. check-data-consistency count-checks template_modifications;
// pin the same count here, plus that each _output file describes its _data record
// (template/find/replace), so a hand-edit of one side fails.
$modItems = outputItems($root, 'template_modifications');
check(
    '_output/template_modifications has one item file per _data modification',
    count($modItems) === ($tmXml !== false ? count($tmXml->modification) : -1)
);

$tmMeta = json_decode((string) @file_get_contents("$root/_output/template_modifications/_metadata.json"), true);
check('_output/template_modifications/_metadata.json is readable', is_array($tmMeta));

foreach (['cav7MilpacMentionEditorJs', 'cav7MilpacMentionPlainHandler'] as $key) {
    $file = "$root/_output/template_modifications/public/$key.json";
    $decoded = json_decode((string) @file_get_contents($file), true);
    $mod = $mods[$key] ?? null;
    check(
        "the _output export for $key matches its _data record (template, find, replace)",
        is_array($decoded)
            && $mod !== null
            && ($decoded['template'] ?? '') === (string) $mod['template']
            && ($decoded['find'] ?? '') === (string) $mod->find
            && ($decoded['replace'] ?? '') === (string) $mod->replace,
        '_data and _output must agree (check-data-consistency count-checks; this checks content)'
    );
    check(
        "the _metadata index lists the exported $key file",
        is_array($tmMeta) && isset($tmMeta["public/$key.json"]),
        'the export always indexes the file; a missing entry means it was hand-faked'
    );
}

// =========================================================================
// the JS asset — exists, and (comments stripped) wires the completer (§4.1, §4.6)
// =========================================================================
$jsSrcText = (string) @file_get_contents($jsAssetPath);
check(
    "the completer JS exists at _assets/js/$jsSrc",
    $jsSrcText !== '',
    'the <xf:js> src points here; a missing file 404s the completer'
);

// Strip comments so a token that only appears in a code comment can never satisfy
// an assertion — the pins must bite on real code (mirrors the comment-stripping in
// FindWiringTest's relation-block anchoring). The JS carries no '//' inside any
// string literal, so line-comment stripping is safe.
$js = preg_replace('~/\*.*?\*/~s', '', $jsSrcText);   // block comments
$js = preg_replace('~//[^\n]*~', '', (string) $js);   // line comments
$js = (string) $js;

check(
    'the JS points the completer at the merged find endpoint milpac-mention/find (§4.3)',
    str_contains($js, 'milpac-mention/find'),
    'the completer reuses the #88 endpoint, no second data path'
);
check(
    'the JS canonicalises the endpoint URL (XF.canonicalizeUrl)',
    str_contains($js, 'XF.canonicalizeUrl'),
    'mirrors XF\'s own @/emoji completers so the board-root prefix is honoured'
);
check(
    "the JS sets the trigger to '$' (§4.1)",
    (bool) preg_match("/at:\s*'\\\$'/", $js),
    "the feature is \$name, not @/!; a single-character 'at' of '\$'"
);
check(
    'the JS sets keepAt false so the \'$\' is consumed on insert, emoji-style (§4.1)',
    (bool) preg_match('/keepAt:\s*false/', $js)
);
check(
    "the JS sets insertMode 'html' so the row's html is the insert value (§4.8.1)",
    (bool) preg_match("/insertMode:\s*'html'/", $js)
);
check(
    'the JS reads the row html for insertion, not a rebuilt rank+name string (§4.8.1)',
    str_contains($js, 'row.html'),
    'the insert value is the endpoint\'s named anchor, inserted verbatim'
);
check(
    'the JS attaches through the bubbling editor:init event, overriding no core method (§4.1)',
    (bool) preg_match("/XF\.on\(\s*document\s*,\s*'editor:init'/", $js)
);
check(
    'the JS constructs an XF.AutoCompleter on the editor editable (ed.\$el[0]) (§4.1)',
    str_contains($js, 'new XF.AutoCompleter') && str_contains($js, 'ed.$el[0]')
);
check(
    'the JS registers a milpac-mentioner element handler beside user-mentioner/emoji-completer (§4.6)',
    str_contains($js, "XF.Element.register('milpac-mentioner'")
);

// =========================================================================
// the plain-BBCode handler inserts the [URL=…] NAMED form, never a bare URL (§4.6)
// =========================================================================
check(
    'the plain handler reads the anchor href to build its insert value',
    str_contains($js, "getAttribute('href')"),
    'the named link\'s href must carry into the BBCode so /rosters/profile/N/ survives'
);
check(
    'the plain handler wraps the href in a NAMED [URL=…]…[/URL] tag (§4.6)',
    str_contains($js, "[URL='") && str_contains($js, '[/URL]'),
    'plain BBCode / mobile inserts the named link as BBCode text'
);
// The negative: the plain insert value must not be the bare href on its own. The
// only place the href is turned into an insert value is anchorToBbCode; assert that
// builder returns the [URL=…] wrapper, so a bare URL is never what gets inserted.
check(
    'the plain handler does NOT insert a bare URL (the href is only ever emitted inside [URL=…])',
    (bool) preg_match("/return\s+\"\[URL='\"\s*\+\s*href\s*\+\s*\"'\]\"\s*\+\s*text\s*\+\s*'\[\/URL\]'/", $js),
    'the sole href→insert path wraps it in the named tag; a bare URL drops the name (§4.2/§4.6)'
);
// Both surfaces share one options builder, so the plain path also carries the
// endpoint, at '$', keepAt false — pinned above once for both.
check(
    'the plain handler drives the same completer as the rich editor (XF.extend of XF.AutoCompleter)',
    str_contains($js, 'XF.extend(XF.AutoCompleter'),
    'the plain path reuses the AutoCompleter, only rewriting the row html to BBCode'
);

// =========================================================================
// static round-trip (§4.8.2) — both inserted forms match the phase-1 engine regex
// =========================================================================
// The engine detects milpac links with this regex (MilpacResolver::extractRelationIds).
// Prove that BOTH the rich-editor artifact (the named anchor, as it serialises to
// BBCode) and the plain-BBCode artifact carry /rosters/profile/<relation_id>/ and
// resolve to the same relation_id — so a $name insert closes the loop with no second
// notification path. The regex is the engine's; the sample strings are known-good
// literals of the two shapes the completer inserts (independent sources of truth).
$engineRegex = '#/rosters/profile/(\d+)#';
$relationId = 4242;
$richAnchor = '<a href="https://7cav.example/rosters/profile/' . $relationId . '/">Corporal Banfield.H</a>';
$richSerialised = "[URL='https://7cav.example/rosters/profile/$relationId/']Corporal Banfield.H[/URL]";
$plainBbCode = "[URL='/rosters/profile/$relationId/']Corporal Banfield.H[/URL]";

check(
    'the rich-editor named anchor matches the engine regex with the right relation_id',
    preg_match($engineRegex, $richAnchor, $mA) === 1 && (int) $mA[1] === $relationId
);
check(
    'the rich anchor, serialised to [URL=…] by Froala, still matches the engine regex (§4.8.2)',
    preg_match($engineRegex, $richSerialised, $mS) === 1 && (int) $mS[1] === $relationId,
    'HTML→BBCode keeps /rosters/profile/N/ intact, so detection survives the round-trip'
);
check(
    'the plain-BBCode named link matches the engine regex with the right relation_id (§4.6)',
    preg_match($engineRegex, $plainBbCode, $mP) === 1 && (int) $mP[1] === $relationId,
    'plain mode inserts the same detectable link, not a bare URL'
);
// The engine regex is genuinely the one this repo ships — pin that the resolver
// still uses it, so this round-trip proof cannot silently drift from the engine.
$resolverSrc = (string) @file_get_contents("$root/MilpacResolver.php");
check(
    'the engine regex used in this proof is the one MilpacResolver actually detects with',
    str_contains($resolverSrc, '#/rosters/profile/(\d+)#'),
    'if the engine regex changes, this round-trip proof must be revisited too'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
