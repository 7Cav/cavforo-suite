<?php

/**
 * Runs the milpac date template modifications the way XenForo runs them, over
 * the captured date rows in tests/fixtures/ — the vendor's own
 * nf_rosters_user_view and the edited copy a board style carries.
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
 * The enabled modification records as XenForo would load them, keyed by
 * modification_key.
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
 * Apply one modification to a template, mirroring
 * XF\Repository\TemplateModificationRepository::applyTemplateModifications().
 * Returns the rewritten template and the match count XenForo would record —
 * 0 means the modification silently did nothing, which is the failure mode this
 * test exists to catch.
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

            // XenForo refuses a find with the (long gone) /e modifier rather
            // than running it, which would report as a no-op in production.
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

$mods = loadModifications($root);

// The date cell each modification owns: the UTC getter it has to install, a
// pattern that finds a viewer-timezone date() call the modification failed to
// remove, and the spellings of that call it has to cope with.
$dateCells = [
    'cav7RosterPatchRecordDateUtc' => [
        'getter'          => '{$record.getRecordDate()}',
        'survivingCallRe' => '/date\s*\(\s*\$record\.record_date/',
        'spellings' => [
            'the vendor spelling'               => "{{ date(\$record.record_date, 'Y-m-d') }}",
            "the style's trailing 'Z' argument" => "{{ date(\$record.record_date, 'Y-m-d', 'Z' )}}",
            'no space inside the braces'        => "{{date(\$record.record_date, 'Y-m-d')}}",
            'space around the argument list'    => "{{ date( \$record.record_date , 'Y-m-d' ) }}",
            'a line break inside the call'      => "{{ date(\n    \$record.record_date,\n    'Y-m-d'\n) }}",
            'no format argument at all'         => '{{ date($record.record_date) }}',
        ],
    ],
    'cav7RosterPatchAwardDateUtc' => [
        'getter'          => '{$award.getAwardDate()}',
        'survivingCallRe' => '/date\s*\(\s*\$award\.award_date/',
        'spellings' => [
            'the vendor spelling'            => "{{ date(\$award.award_date, 'Y-m-d') }}",
            "a trailing 'Z' argument"        => "{{ date(\$award.award_date, 'Y-m-d', 'Z' )}}",
            'no space inside the braces'     => "{{date(\$award.award_date, 'Y-m-d')}}",
            'space around the argument list' => "{{ date( \$award.award_date , 'Y-m-d' ) }}",
            'a line break inside the call'   => "{{ date(\n    \$award.award_date,\n    'Y-m-d'\n) }}",
            'no format argument at all'      => '{{ date($award.award_date) }}',
        ],
    ],
];

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

foreach ($fixtures as $fixtureLabel => $file) {
    $template = (string) file_get_contents("$root/tests/fixtures/$file");
    check("$fixtureLabel fixture could be read", $template !== '');

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
        check(
            "$key puts " . $cell['getter'] . " into $fixtureLabel",
            str_contains($result, $cell['getter']),
            'the UTC getter never reached the template'
        );
        check(
            "$key leaves no viewer-timezone date() call in $fixtureLabel",
            !preg_match($cell['survivingCallRe'], $result),
            'a surviving date() call keeps the per-viewer shift'
        );

        // XenForo re-applies every modification each time the template is
        // recompiled, so a second pass has to be a no-op. A pattern that also
        // matched its own replacement would multiply the cell on every rebuild.
        [$again, $againCount] = applyModification($mod, $result);
        check(
            "$key is idempotent over $fixtureLabel",
            $againCount === 0 && $again === $result,
            're-applying matched ' . var_export($againCount, true) . ' time(s)'
        );
    }
}

// --- both dates survive however the date() call is spelled -------------------
// A style copy can differ from the vendor in whitespace or carry arguments the
// vendor never wrote. None of that changes what the call renders, so none of it
// should decide whether the swap happens.
foreach ($dateCells as $key => $cell) {
    $mod = $mods[$key] ?? null;
    if ($mod === null) {
        continue;
    }

    foreach ($cell['spellings'] as $label => $call) {
        $template = "<xf:datarow>\n    <xf:cell>$call</xf:cell>\n</xf:datarow>";
        [$result] = applyModification($mod, $template);
        check(
            "$key rewrites $label",
            str_contains($result, $cell['getter']),
            'got: ' . str_replace("\n", '\n', $result)
        );
    }
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
