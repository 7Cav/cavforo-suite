<?php

/**
 * Runs the milpac date template modifications the way XenForo runs them, over
 * committed copies of the markup they have to survive: the vendor's own
 * nf_rosters_user_view and the edited copy a board style carries.
 *
 * MilpacDateWiringTest pins the shape of the modification records; this asks the
 * only question that matters in production — after XenForo applies them, does
 * the date cell still go through the UTC getter? An exact-string find answered
 * yes for the vendor template and no for the style copy, and nothing failed
 * (issue #106).
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
 * The modification records as XenForo would load them, keyed by modification_key.
 *
 * @return array<string, array{action: string, find: string, replace: string, template: string}>
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
            'action'   => (string) $mod['action'],
            'find'     => (string) $mod->find,
            'replace'  => (string) $mod->replace,
            'template' => (string) $mod['template'],
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

            $count = @preg_match_all($find, $template, $null);
            if ($count === false) {
                return [$template, 'error_invalid_regex'];
            }

            return [(string) preg_replace($find, $mod['replace'], $template), $count];
    }

    return [$template, 'error_unknown_action'];
}

$mods = loadModifications($root);

// The date cell each modification owns, and what has to be true of the template
// once XenForo has applied it.
$dateCells = [
    'cav7RosterPatchRecordDateUtc' => [
        'getter'   => '{$record.getRecordDate()}',
        'vendorRe' => '/date\s*\(\s*\$record\.record_date/',
    ],
    'cav7RosterPatchAwardDateUtc' => [
        'getter'   => '{$award.getAwardDate()}',
        'vendorRe' => '/date\s*\(\s*\$award\.award_date/',
    ],
];

// --- both dates render in UTC in the vendor template and in the style copy ---
// "vendor" is the markup NF/Rosters 2.1.5 ships, so a vendor update that moves
// the date cell fails here instead of shipping a modification that matches
// nothing. "style-edited" is the copy the board style carries: a third argument
// on the record-date call, which is what an exact-string find missed.
$fixtures = [
    'the vendor template'      => 'nf_rosters_user_view.vendor.html',
    'the style-edited copy'    => 'nf_rosters_user_view.style-edited.html',
];

foreach ($fixtures as $fixtureLabel => $file) {
    $template = (string) file_get_contents("$root/tests/fixtures/$file");
    check("$fixtureLabel fixture could be read", $template !== '');

    foreach ($dateCells as $key => $cell) {
        $mod = $mods[$key] ?? null;
        check("$key is present and enabled", $mod !== null);
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
            !preg_match($cell['vendorRe'], $result),
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
$spellings = [
    'cav7RosterPatchRecordDateUtc' => [
        'getter'   => '{$record.getRecordDate()}',
        'variants' => [
            "the vendor spelling"                 => "{{ date(\$record.record_date, 'Y-m-d') }}",
            "the style's trailing 'Z' argument"   => "{{ date(\$record.record_date, 'Y-m-d', 'Z' )}}",
            'no space inside the braces'          => "{{date(\$record.record_date, 'Y-m-d')}}",
            'space around the argument list'      => "{{ date( \$record.record_date , 'Y-m-d' ) }}",
            'a line break inside the call'        => "{{ date(\n    \$record.record_date,\n    'Y-m-d'\n) }}",
            'no format argument at all'           => '{{ date($record.record_date) }}',
        ],
    ],
    'cav7RosterPatchAwardDateUtc' => [
        'getter'   => '{$award.getAwardDate()}',
        'variants' => [
            'the vendor spelling'                 => "{{ date(\$award.award_date, 'Y-m-d') }}",
            "a trailing 'Z' argument"             => "{{ date(\$award.award_date, 'Y-m-d', 'Z' )}}",
            'no space inside the braces'          => "{{date(\$award.award_date, 'Y-m-d')}}",
            'space around the argument list'      => "{{ date( \$award.award_date , 'Y-m-d' ) }}",
            'a line break inside the call'        => "{{ date(\n    \$award.award_date,\n    'Y-m-d'\n) }}",
            'no format argument at all'           => '{{ date($award.award_date) }}',
        ],
    ],
];

foreach ($spellings as $key => $spec) {
    $mod = $mods[$key] ?? null;
    check("$key is present and enabled", $mod !== null);
    if ($mod === null) {
        continue;
    }

    foreach ($spec['variants'] as $label => $cell) {
        $template = "<xf:datarow>\n    <xf:cell>$cell</xf:cell>\n</xf:datarow>";
        [$result] = applyModification($mod, $template);
        check(
            "$key rewrites $label",
            str_contains($result, $spec['getter']),
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
