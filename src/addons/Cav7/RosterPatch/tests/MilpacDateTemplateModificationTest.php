<?php

/**
 * Runs the milpac date template modifications over the captured date rows in
 * tests/fixtures/ — the vendor's own nf_rosters_user_view and the edited copy
 * the 7Cav style carries.
 *
 * MilpacDateWiringTest pins the shape of the modification records; this asks
 * what those records do to markup: after XenForo applies them, does the date
 * cell go through the UTC getter? RosterPatch's README ("The date cells are
 * matched by pattern") is where that question and its history live.
 *
 * What this pins is the patterns, against markup taken from NF/Rosters 2.1.5.
 * The fixtures are frozen copies, not the installed add-on, and CI has neither
 * XenForo nor a vendor tree — so a later NF/Rosters release that moves the date
 * cell leaves the fixtures matching and this suite green. Recapture them when
 * NF/Rosters is upgraded.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/MilpacDateTemplateModificationTest.php
 */

namespace Cav7\RosterPatch\Tests;

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

/**
 * The enabled modification records, read straight out of the committed XML and
 * keyed by modification_key.
 *
 * Scope: this is the committed export, not a XenForo install. XenForo loads the
 * same records from xf_template_modification filtered by whereAddOnActive() and
 * ordered by (execution_order, modification_key); nothing here reads a database
 * or knows about add-on state.
 *
 * @return array<string, array{action: string, find: string, replace: string}>
 */
function loadModifications(string $root): array
{
    $xml = @simplexml_load_file("$root/_data/template_modifications.xml");
    if ($xml === false) {
        return [];
    }

    $mods = [];
    foreach ($xml->modification as $mod) {
        if ((string) $mod['enabled'] !== '1') {
            continue;
        }
        $mods[(string) $mod['modification_key']] = [
            'action'  => (string) $mod['action'],
            'find'    => (string) $mod->find,
            'replace' => (string) $mod->replace,
        ];
    }

    return $mods;
}

/**
 * Apply one modification to a template, mirroring one iteration of
 * XF\Repository\TemplateModificationRepository::applyTemplateModifications().
 * Returns the rewritten template and the match count XenForo would record —
 * 0 means the modification silently did nothing, which is the failure mode this
 * test exists to catch.
 *
 * Scope: one modification against one template. XenForo runs the whole ordered
 * set over a single accumulating template; applyBoth() below does that for the
 * two records this add-on ships.
 *
 * @param array{action: string, find: string, replace: string} $mod
 *
 * @return array{0: string, 1: int|string}
 */
function applyModification(array $mod, string $template): array
{
    $template = str_replace("\r\n", "\n", $template);

    switch ($mod['action']) {
        case 'str_replace':
            $find = str_replace("\r\n", "\n", $mod['find']);
            $replace = str_replace('$0', $find, $mod['replace']);

            return [str_replace($find, $replace, $template), substr_count($template, $find)];

        case 'preg_replace':
            $find = str_replace(["\r\n", '\r\n'], ["\n", '\n'], trim($mod['find']));

            // A find carrying the (long gone) /e modifier: XenForo records
            // 'error_invalid_regex' for it and then falls through and runs
            // preg_replace() anyway, a few lines further down the same method.
            // Returning early here is a deliberate divergence — this mirror has
            // no status log to carry the error forward, and no modification the
            // add-on ships can reach the branch, so stopping is a safer shape
            // for a test helper than reproducing a replace its own status
            // already calls an error.
            if (preg_match('/\W[\s\w]*e[\s\w]*$/', $find)) {
                return [$template, 'error_invalid_regex'];
            }

            $count = @preg_match_all($find, $template, $matches);
            if ($count === false) {
                return [$template, 'error_invalid_regex'];
            }

            return [(string) preg_replace($find, $mod['replace'], $template), $count];
    }

    return [$template, 'error_unknown_action'];
}

/**
 * The captured markup a fixture file holds, without the leading provenance
 * comment. That comment says where the capture came from and what it left out;
 * it is not part of the template, and it names date() in prose, so everything
 * below runs over the body alone.
 */
function fixtureBody(string $text): string
{
    return (string) preg_replace('/\A\s*<!--.*?-->\n/s', '', $text);
}

/**
 * Run a set of modifications over one accumulating template, the way XenForo
 * runs the pass. Both records this add-on ships sit at execution_order 10, so
 * modification_key breaks the tie.
 *
 * @param array<string, array{action: string, find: string, replace: string}> $mods
 *
 * @return array{0: string, 1: array<string, int|string>}
 */
function applyBoth(array $mods, string $template): array
{
    ksort($mods);

    $counts = [];
    foreach ($mods as $key => $mod) {
        [$template, $counts[$key]] = applyModification($mod, $template);
    }

    return [$template, $counts];
}

/**
 * The date cell each modification owns.
 *
 * - getter:          the UTC getter the modification has to install.
 * - cellAfter:       the exact rewritten cell, start tag to end tag. Asserting
 *                    the whole cell — rather than "the getter turns up
 *                    somewhere" — is what kills a find that matches only the
 *                    column reference, or one truncated so the tail of the
 *                    date() call survives.
 * - survivingCallRe: a PCRE pattern, delimiters included, that finds a
 *                    viewer-timezone date() call the modification failed to
 *                    remove.
 * - fixtureCells:    the exact cell as each fixture spells it, keyed by the
 *                    fixture labels in $fixtures below.
 * - spellings:       date() call spellings the pattern has to cope with, each
 *                    dropped into a cell of its own.
 * - nonMatches:      cells the pattern deliberately leaves alone.
 *
 * Keys are modification_key, joining against loadModifications().
 *
 * @var array<string, array{
 *     getter: string,
 *     cellAfter: string,
 *     survivingCallRe: string,
 *     fixtureCells: array<string, string>,
 *     spellings: array<string, string>,
 *     nonMatches: array<string, string>
 * }> $dateCells
 */
$dateCells = [
    'cav7RosterPatchRecordDateUtc' => [
        'getter'          => '{$record.getRecordDate()}',
        'cellAfter'       => '<xf:cell>{$record.getRecordDate()}</xf:cell>',
        'survivingCallRe' => '/date\s*\(\s*\$record\.record_date/',
        'fixtureCells' => [
            'the captured vendor rows' => "<xf:cell>{{ date(\$record.record_date, 'Y-m-d') }}</xf:cell>",
            'the style-edited copy'    => "<xf:cell>{{ date(\$record.record_date, 'Y-m-d', 'Z' )}}</xf:cell>",
        ],
        'spellings' => [
            'the vendor spelling'               => "{{ date(\$record.record_date, 'Y-m-d') }}",
            "the style's trailing 'Z' argument" => "{{ date(\$record.record_date, 'Y-m-d', 'Z' )}}",
            'no space inside the braces'        => "{{date(\$record.record_date, 'Y-m-d')}}",
            'space around the argument list'    => "{{ date( \$record.record_date , 'Y-m-d' ) }}",
            'a line break inside the call'      => "{{ date(\n    \$record.record_date,\n    'Y-m-d'\n) }}",
            'no format argument at all'         => '{{ date($record.record_date) }}',
        ],
        'nonMatches' => [
            'a null-guard ternary around the record call' => "{{ \$record.record_date ? date(\$record.record_date, 'Y-m-d') : '-' }}",
            'a filter after the record call'              => "{{ date(\$record.record_date, 'Y-m-d')|escape }}",
            'a concatenated suffix on the record call'    => "{{ date(\$record.record_date, 'Y-m-d') ~ ' UTC' }}",
            'a nested call as the record format'          => "{{ date(\$record.record_date, fmt('Y-m-d')) }}",
        ],
    ],
    'cav7RosterPatchAwardDateUtc' => [
        'getter'          => '{$award.getAwardDate()}',
        'cellAfter'       => '<xf:cell>{$award.getAwardDate()}</xf:cell>',
        'survivingCallRe' => '/date\s*\(\s*\$award\.award_date/',
        'fixtureCells' => [
            'the captured vendor rows' => "<xf:cell>{{ date(\$award.award_date, 'Y-m-d') }}</xf:cell>",
            'the style-edited copy'    => "<xf:cell>{{ date(\$award.award_date, 'Y-m-d') }}</xf:cell>",
        ],
        'spellings' => [
            'the vendor spelling'            => "{{ date(\$award.award_date, 'Y-m-d') }}",
            "a trailing 'Z' argument"        => "{{ date(\$award.award_date, 'Y-m-d', 'Z' )}}",
            'no space inside the braces'     => "{{date(\$award.award_date, 'Y-m-d')}}",
            'space around the argument list' => "{{ date( \$award.award_date , 'Y-m-d' ) }}",
            'a line break inside the call'   => "{{ date(\n    \$award.award_date,\n    'Y-m-d'\n) }}",
            'no format argument at all'      => '{{ date($award.award_date) }}',
        ],
        'nonMatches' => [
            'a null-guard ternary around the award call' => "{{ \$award.award_date ? date(\$award.award_date, 'Y-m-d') : '-' }}",
            'a filter after the award call'              => "{{ date(\$award.award_date, 'Y-m-d')|escape }}",
            'a concatenated suffix on the award call'    => "{{ date(\$award.award_date, 'Y-m-d') ~ ' UTC' }}",
            'a nested call as the award format'          => "{{ date(\$award.award_date, fmt('Y-m-d')) }}",
        ],
    ],
];

$mods = loadModifications($root);

// Nothing below can mean anything if a modification failed to load, so say so
// once here rather than once per fixture. Their shape is MilpacDateWiringTest's.
foreach (array_keys($dateCells) as $key) {
    check("$key loaded as an enabled modification", isset($mods[$key]));
}

// --- both dates render in UTC in the captured vendor rows and the style copy -
$fixtures = [
    'the captured vendor rows' => 'nf_rosters_user_view.vendor-2.1.5.html',
    'the style-edited copy'    => 'nf_rosters_user_view.style-edited-2.1.5.html',
];

/** @var array<string, string> $fixtureText */
$fixtureText = [];

foreach ($fixtures as $fixtureLabel => $file) {
    $raw = @file_get_contents("$root/tests/fixtures/$file");
    check("$fixtureLabel fixture could be read", is_string($raw) && $raw !== '');
    if (!is_string($raw) || $raw === '') {
        // Every assertion below would fail against an empty template, burying
        // the one failure that actually says what went wrong.
        continue;
    }

    $template = fixtureBody($raw);
    $fixtureText[$fixtureLabel] = $template;

    foreach ($dateCells as $key => $cell) {
        $mod = $mods[$key] ?? null;
        if ($mod === null) {
            continue;
        }

        [$result, $count] = applyModification($mod, $template);

        check(
            "$key matches $fixtureLabel exactly once",
            $count === 1,
            'match count: ' . var_export($count, true)
                . ' — 0 means the date keeps rendering in the viewer timezone and XenForo says nothing'
        );

        // The whole fixture, with that one cell swapped for the getter cell and
        // nothing else touched. Written out by hand rather than derived from the
        // pattern, so a find that matches the wrong span fails here instead of
        // agreeing with itself.
        $expected = str_replace($cell['fixtureCells'][$fixtureLabel], $cell['cellAfter'], $template);
        check(
            "$key rewrites $fixtureLabel to exactly " . $cell['cellAfter'],
            $result === $expected,
            'got: ' . var_export($result, true)
        );
        check(
            "$key leaves no viewer-timezone date() call in $fixtureLabel",
            preg_match($cell['survivingCallRe'], $result) === 0,
            'a surviving date() call keeps the per-viewer shift'
        );

        // XenForo applies the ordered set to the stored, unmodified template
        // source on every compile (XF\Entity\Template::validateTemplateText()
        // hands it the unmodified text), so nothing accumulates across rebuilds
        // and a self-matching pattern could not multiply the cell that way. It
        // still has to be a no-op over its own output: another modification on
        // the same template can run after this one in the same pass and would
        // hand this one's result back to it.
        [$again, $againCount] = applyModification($mod, $result);
        check(
            "$key is idempotent over $fixtureLabel",
            $againCount === 0 && $again === $result,
            're-applying matched ' . var_export($againCount, true) . ' time(s)'
        );
    }
}

// --- the style fixture still carries the style's own markup ------------------
// Its whole job is holding the live board's variant. Overwritten with a byte
// copy of the vendor fixture it would keep every check above green while
// pinning nothing new, so say what makes it different.
if (isset($fixtureText['the captured vendor rows'], $fixtureText['the style-edited copy'])) {
    check(
        'the style fixture is not a copy of the vendor fixture',
        $fixtureText['the style-edited copy'] !== $fixtureText['the captured vendor rows'],
        'recapture it from the style, or it pins nothing the vendor fixture does not'
    );
    check(
        "the style fixture still carries the style's third date() argument",
        str_contains(
            $fixtureText['the style-edited copy'],
            "date(\$record.record_date, 'Y-m-d', 'Z' )"
        ),
        "the 'Z' argument is the edit this fixture exists to cover"
    );
}

// --- both modifications over one template, the way XenForo runs the pass -----
// XenForo applies the whole ordered set to a single accumulating template, so
// the second modification sees the first one's output. Nothing about these two
// makes them interfere; this is what says so.
foreach ($fixtureText as $fixtureLabel => $template) {
    $wanted = array_intersect_key($mods, $dateCells);
    if (count($wanted) !== count($dateCells)) {
        continue;
    }

    [$composed, $counts] = applyBoth($wanted, $template);

    $expected = $template;
    foreach ($dateCells as $cell) {
        $expected = str_replace($cell['fixtureCells'][$fixtureLabel], $cell['cellAfter'], $expected);
    }

    check(
        "one composed pass over $fixtureLabel rewrites both cells",
        $composed === $expected,
        'got: ' . var_export($composed, true)
    );
    check(
        "each modification still matches exactly once in the composed pass over $fixtureLabel",
        $counts === array_fill_keys(array_keys($wanted), 1),
        'counts: ' . var_export($counts, true)
    );
    check(
        "no date() call is left anywhere in $fixtureLabel after the composed pass",
        !str_contains($composed, 'date('),
        'a surviving date() call keeps the per-viewer shift'
    );
}

// --- both dates survive however the date() call is spelled -------------------
// A style copy can differ from the vendor in whitespace, or carry arguments the
// vendor never wrote, and neither should decide whether the swap happens.
//
// The pattern swallows the format argument rather than preserving it, and the
// getter always renders 'Y-m-d'. That is deliberate — one fixed calendar day in
// one fixed format is the whole point of the fix — but it does cost something:
// a style that reformatted the date loses that reformatting, and the 'no format
// argument at all' spelling stops rendering in the viewer's language format and
// starts rendering as 'Y-m-d'.
foreach ($dateCells as $key => $cell) {
    $mod = $mods[$key] ?? null;
    if ($mod === null) {
        continue;
    }

    foreach ($cell['spellings'] as $label => $call) {
        $template = "<xf:datarow>\n    <xf:cell>$call</xf:cell>\n</xf:datarow>";
        $expected = "<xf:datarow>\n    " . $cell['cellAfter'] . "\n</xf:datarow>";

        [$result, $count] = applyModification($mod, $template);

        check(
            "$key matches $label exactly once",
            $count === 1,
            'match count: ' . var_export($count, true)
        );
        check(
            "$key rewrites $label to exactly " . $cell['cellAfter'],
            $result === $expected,
            'got: ' . str_replace("\n", '\n', $result)
        );
        check(
            "$key leaves no viewer-timezone date() call in $label",
            preg_match($cell['survivingCallRe'], $result) === 0,
            'a surviving date() call keeps the per-viewer shift'
        );

        [$again, $againCount] = applyModification($mod, $result);
        check(
            "$key is idempotent over $label",
            $againCount === 0 && $again === $result,
            're-applying matched ' . var_export($againCount, true) . ' time(s)'
        );
    }
}

// --- and the cells the pattern deliberately will not touch -------------------
// The pattern matches a whole {{ date(...) }} expression, not the date() call
// inside one, so a style that wrapped or extended the expression is left alone.
// That is the right trade: anchoring on the call alone would rewrite
// `{{ $record.record_date ? date(...) : '-' }}` into
// `{{ $record.record_date ? {$record.getRecordDate()} : '-' }}`, which is not
// valid template markup, and a broken page is worse than a per-viewer date.
//
// So these are limits, recorded rather than fixed. Widening the pattern to
// cover one of them is a decision to take deliberately, with this list in front
// of you.
foreach ($dateCells as $key => $cell) {
    $mod = $mods[$key] ?? null;
    if ($mod === null) {
        continue;
    }

    foreach ($cell['nonMatches'] as $label => $call) {
        $template = "<xf:datarow>\n    <xf:cell>$call</xf:cell>\n</xf:datarow>";
        [$result, $count] = applyModification($mod, $template);

        check(
            "$key leaves $label alone",
            $count === 0 && $result === $template,
            'match count: ' . var_export($count, true)
                . ' — matching here would rewrite the expression into invalid markup'
        );
    }
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
