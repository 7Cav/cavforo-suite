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
 * Every field XenForo selects and orders on comes with them — type, template and
 * execution_order — so modificationsForTemplate() below can rebuild the set for
 * one template from the records rather than from a list written out here.
 *
 * Scope: this is the committed export, not a XenForo install. XenForo loads the
 * same records from xf_template_modification filtered by whereAddOnActive();
 * nothing here reads a database or knows about add-on state.
 *
 * @return array<string, array{
 *     type: string,
 *     template: string,
 *     execution_order: int,
 *     action: string,
 *     find: string,
 *     replace: string
 * }>
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
            'type'            => (string) $mod['type'],
            'template'        => (string) $mod['template'],
            'execution_order' => (int) $mod['execution_order'],
            'action'          => (string) $mod['action'],
            'find'            => (string) $mod->find,
            'replace'         => (string) $mod->replace,
        ];
    }

    return $mods;
}

/**
 * Every enabled modification XenForo would run over one template, in the order
 * it would run them: the records matching (type, template), sorted by
 * (execution_order, modification_key) — the order()
 * TemplateModificationRepository::applyModificationsToTemplate() puts on its
 * finder.
 *
 * Taking the set from the records instead of from a list of keys is the point.
 * This add-on is the declared home for later NF/Rosters patches, so a third
 * modification on nf_rosters_user_view is a realistic edit; picked up here it
 * joins the composed pass on its own, and if it undoes one of the date cells the
 * pass says so.
 *
 * @param array<string, array{type: string, template: string, execution_order: int, action: string, find: string, replace: string}> $mods
 *
 * @return array<string, array{type: string, template: string, execution_order: int, action: string, find: string, replace: string}>
 */
function modificationsForTemplate(array $mods, string $type, string $template): array
{
    $set = array_filter(
        $mods,
        fn (array $mod) => $mod['type'] === $type && $mod['template'] === $template
    );

    uksort(
        $set,
        fn (string $a, string $b) => [$set[$a]['execution_order'], $a] <=> [$set[$b]['execution_order'], $b]
    );

    return $set;
}

/**
 * Apply one modification to a template, mirroring one iteration of
 * XF\Repository\TemplateModificationRepository::applyTemplateModifications().
 * Returns the rewritten template and the match count XenForo would record —
 * 0 means the modification silently did nothing, which is the failure mode this
 * test exists to catch.
 *
 * Scope: one modification against one template. XenForo runs the whole ordered
 * set over a single accumulating template; applyPass() below does that for the
 * set modificationsForTemplate() hands it.
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
            // Returning early here is one of three places this mirror diverges,
            // none of them reachable by a modification the add-on ships:
            //   - /e: XenForo logs the error and replaces anyway; this stops.
            //   - action 'callback': XenForo shares this branch with
            //     preg_replace and dispatches on the action further down; the
            //     default below hands it 'error_unknown_action' instead.
            //   - a failed match: XenForo calls it an error only when
            //     preg_last_error() also says so, inside a try/catch
            //     (\ErrorException); this treats any false as an error.
            // A helper that stops at a status its own code already calls an
            // error is a safer shape than one that replaces anyway.
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
 * comment — or null when the file does not open with one. That comment says
 * where the capture came from and what it left out; it is not part of the
 * template, and the style fixture's header names date() in prose, so everything
 * below runs over the body alone. A silent no-strip would hand every assertion a
 * template with prose in it, so the caller is told instead.
 *
 * Line endings are normalised here, the one place fixture text enters this file.
 * applyModification() normalises its own input, while the expectations below are
 * built from this text, so a fixture arriving as CRLF would redden checks that
 * have nothing to do with the patterns — and the README sends a maintainer
 * recapturing a fixture to the XenForo admin template editor, whose textarea
 * hands back CRLF by spec.
 */
function fixtureBody(string $text): ?string
{
    $text = str_replace("\r\n", "\n", $text);
    $body = (string) preg_replace('/\A\s*<!--.*?-->\n/s', '', $text);

    return $body === $text ? null : $body;
}

/**
 * Run a set of modifications over one accumulating template, in the order given,
 * the way XenForo runs the pass. Ordering the set is
 * modificationsForTemplate()'s job, not this one's.
 *
 * @param array<string, array{action: string, find: string, replace: string}> $mods
 *
 * @return array{0: string, 1: array<string, int|string>}
 */
function applyPass(array $mods, string $template): array
{
    $counts = [];
    foreach ($mods as $key => $mod) {
        [$template, $counts[$key]] = applyModification($mod, $template);
    }

    return [$template, $counts];
}

/**
 * The expression $dateCells says a fixture spells, or null when the table and
 * the fixture have drifted apart. Whether the table has an entry for this
 * fixture at all is checked once, separately, below — so a null here means one
 * thing: the entry no longer matches the fixture.
 *
 * The expectations below are derived with str_replace() over the fixture, and
 * str_replace() is a silent no-op when its needle is absent: a recaptured
 * fixture the table was not updated for would leave the expectation as the
 * untouched template, and the failure would read as a broken pattern while
 * dumping the whole template. This turns that into one check that names the real
 * problem.
 *
 * @param array{fixtureExpressions: array<string, string>} $cell
 */
function tableExpression(array $cell, string $fixtureLabel, string $template): ?string
{
    $spelt = $cell['fixtureExpressions'][$fixtureLabel] ?? null;

    return is_string($spelt) && $spelt !== '' && str_contains($template, $spelt) ? $spelt : null;
}

/**
 * The date expression each modification owns.
 *
 * The modification replaces a whole {{ date(...) }} expression with a bare
 * getter expression; the <xf:cell> tags around it belong to whatever markup the
 * expression sits in, and the modification neither matches nor writes them. So
 * the expectations here are wrapper-free: each one is built by swapping the
 * expression out of its surrounding markup for the modification's own
 * replacement, which asserts the whole rewritten template — rather than "the
 * getter turns up somewhere" — and so still kills a find that matches only the
 * column reference, or one truncated so the tail of the date() call survives.
 *
 * - survivingCallRe:    a PCRE pattern, delimiters included, that finds a
 *                       viewer-timezone date() call the modification failed to
 *                       remove.
 * - fixtureExpressions: the exact expression each fixture spells, keyed by the
 *                       fixture labels in $fixtures below.
 * - spellings:          date() call spellings the pattern has to cope with, each
 *                       as [expression, the markup it sits in as a sprintf
 *                       format]. The wrappers vary on purpose: a find
 *                       re-anchored onto <xf:cell> passes a harness that only
 *                       ever hands it a bare <xf:cell>, while on a board it
 *                       silently misses every style that attributes the cell or
 *                       lifts the expression out of the table.
 * - nonMatches:         expressions the pattern deliberately leaves alone. These
 *                       stay bare: the property is that nothing matches, which
 *                       no wrapper can turn into a match the whole-expression
 *                       patterns would otherwise refuse.
 *
 * Keys are modification_key, joining against loadModifications().
 *
 * @var array<string, array{
 *     survivingCallRe: string,
 *     fixtureExpressions: array<string, string>,
 *     spellings: array<string, array{0: string, 1: string}>,
 *     nonMatches: array<string, string>
 * }> $dateCells
 */
$dateCells = [
    'cav7RosterPatchRecordDateUtc' => [
        'survivingCallRe' => '/date\s*\(\s*\$record\.record_date/',
        'fixtureExpressions' => [
            'the captured vendor rows' => "{{ date(\$record.record_date, 'Y-m-d') }}",
            'the style-edited copy'    => "{{ date(\$record.record_date, 'Y-m-d', 'Z' )}}",
        ],
        'spellings' => [
            'the vendor spelling'               => ["{{ date(\$record.record_date, 'Y-m-d') }}", '<xf:cell>%s</xf:cell>'],
            "the style's trailing 'Z' argument" => ["{{ date(\$record.record_date, 'Y-m-d', 'Z' )}}", '<xf:cell>%s</xf:cell>'],
            'no space inside the braces'        => ["{{date(\$record.record_date, 'Y-m-d')}}", '<xf:cell>%s</xf:cell>'],
            'a space before the argument list'  => ["{{ date (\$record.record_date, 'Y-m-d') }}", '<xf:cell>%s</xf:cell>'],
            'space around the argument list'    => ["{{ date( \$record.record_date , 'Y-m-d' ) }}", '<xf:cell>%s</xf:cell>'],
            'a line break inside the call'      => ["{{ date(\n    \$record.record_date,\n    'Y-m-d'\n) }}", '<xf:cell>%s</xf:cell>'],
            'no format argument at all'         => ['{{ date($record.record_date) }}', '<xf:cell>%s</xf:cell>'],
            'an attributed cell around it'      => ["{{ date(\$record.record_date, 'Y-m-d') }}", '<xf:cell class="u-alignRight">%s</xf:cell>'],
            'no cell around it at all'          => ["{{ date(\$record.record_date, 'Y-m-d') }}", '%s'],
        ],
        'nonMatches' => [
            'a null-guard ternary around the record call' => "{{ \$record.record_date ? date(\$record.record_date, 'Y-m-d') : '-' }}",
            'a filter after the record call'              => "{{ date(\$record.record_date, 'Y-m-d')|escape }}",
            'a concatenated suffix on the record call'    => "{{ date(\$record.record_date, 'Y-m-d') ~ ' UTC' }}",
            'a nested call as the record format'          => "{{ date(\$record.record_date, fmt('Y-m-d')) }}",
        ],
    ],
    'cav7RosterPatchAwardDateUtc' => [
        'survivingCallRe' => '/date\s*\(\s*\$award\.award_date/',
        'fixtureExpressions' => [
            'the captured vendor rows' => "{{ date(\$award.award_date, 'Y-m-d') }}",
            'the style-edited copy'    => "{{ date(\$award.award_date, 'Y-m-d') }}",
        ],
        'spellings' => [
            'the vendor spelling'              => ["{{ date(\$award.award_date, 'Y-m-d') }}", '<xf:cell>%s</xf:cell>'],
            "a trailing 'Z' argument"          => ["{{ date(\$award.award_date, 'Y-m-d', 'Z' )}}", '<xf:cell>%s</xf:cell>'],
            'no space inside the braces'       => ["{{date(\$award.award_date, 'Y-m-d')}}", '<xf:cell>%s</xf:cell>'],
            'a space before the argument list' => ["{{ date (\$award.award_date, 'Y-m-d') }}", '<xf:cell>%s</xf:cell>'],
            'space around the argument list'   => ["{{ date( \$award.award_date , 'Y-m-d' ) }}", '<xf:cell>%s</xf:cell>'],
            'a line break inside the call'     => ["{{ date(\n    \$award.award_date,\n    'Y-m-d'\n) }}", '<xf:cell>%s</xf:cell>'],
            'no format argument at all'        => ['{{ date($award.award_date) }}', '<xf:cell>%s</xf:cell>'],
            'an attributed cell around it'     => ["{{ date(\$award.award_date, 'Y-m-d') }}", '<xf:cell class="u-alignRight">%s</xf:cell>'],
            'no cell around it at all'         => ["{{ date(\$award.award_date, 'Y-m-d') }}", '%s'],
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

// "the table has no entry for this fixture" and "the entry has drifted from the
// fixture" are different mistakes with different fixes, and the containment
// check inside the loop can only say the second. Say the first once, here.
foreach ($dateCells as $key => $cell) {
    $missing = array_diff(array_keys($fixtures), array_keys($cell['fixtureExpressions']));
    $unknown = array_diff(array_keys($cell['fixtureExpressions']), array_keys($fixtures));
    check(
        "\$dateCells['$key'] carries an expected expression for every fixture and no others",
        $missing === [] && $unknown === [],
        'no entry for: ' . (implode(', ', $missing) ?: 'none')
            . '; entry for no such fixture: ' . (implode(', ', $unknown) ?: 'none')
    );
}

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
    check(
        "$fixtureLabel fixture opens with the provenance header the strip expects",
        $template !== null,
        'without it, the header text runs through the patterns as if it were template markup'
    );
    if ($template === null) {
        continue;
    }
    $fixtureText[$fixtureLabel] = $template;

    foreach ($dateCells as $key => $cell) {
        $mod = $mods[$key] ?? null;
        if ($mod === null) {
            continue;
        }

        // The expectations below are derived by swapping this expression out of
        // the fixture, so the table has to still spell the expression the
        // fixture spells. The README asks for a recapture on every NF/Rosters
        // upgrade, which is exactly when the two drift apart; without this, that
        // drift surfaces as a pattern failure dumping the whole template.
        $spelt = tableExpression($cell, $fixtureLabel, $template);
        check(
            "\$dateCells['$key'] spells the expression $fixtureLabel actually carries",
            $spelt !== null,
            'the table says ' . var_export($cell['fixtureExpressions'][$fixtureLabel] ?? null, true)
                . ', which is not in the fixture — recapture the fixture and the table together'
        );
        if ($spelt === null) {
            continue;
        }

        [$result, $count] = applyModification($mod, $template);

        check(
            "$key matches $fixtureLabel exactly once",
            $count === 1,
            'match count: ' . var_export($count, true)
                . ' — 0 means the date keeps rendering in the viewer timezone and XenForo says nothing'
        );

        // The whole fixture, with that one expression swapped for the getter and
        // nothing else touched. The span comes from the table by hand rather
        // than from the find, so a find that matches the wrong span fails here
        // instead of agreeing with itself.
        $expected = str_replace($spelt, $mod['replace'], $template);
        check(
            "$key rewrites the date expression in $fixtureLabel to exactly " . $mod['replace'],
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
        // hands it the unmodified text), so nothing accumulates across rebuilds,
        // and applyTemplateModifications() visits each record exactly once, so
        // this one never sees its own output either. What this pins is narrower:
        // the replacement contains nothing this pattern can match again, which
        // is what keeps a later widening of the pattern from rewriting the
        // getter expression a second time.
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
// copy of the vendor fixture the precondition check above does redden — the
// table still spells the 'Z' cell, which the copy no longer carries — but all it
// can say is that a cell went missing. These two name the cause.
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

// --- the whole shipped set over one template, the way XenForo runs the pass --
// XenForo applies every enabled modification for one (type, template) to a
// single accumulating template, in (execution_order, modification_key) order, so
// each one sees the last one's output. The set comes from the XML rather than
// from $dateCells: a third modification on nf_rosters_user_view — the add-on is
// the declared home for later NF/Rosters patches — is in the pass whether this
// table knows about it or not, and if it puts a viewer-timezone date() call back
// the final markup says so. $dateCells stays the expectation table.
$pass = modificationsForTemplate($mods, 'public', 'nf_rosters_user_view');
$passCarriesBoth = array_diff(array_keys($dateCells), array_keys($pass)) === [];

check(
    'the composed pass carries both date modifications',
    $passCarriesBoth,
    'the pass runs: ' . (implode(', ', array_keys($pass)) ?: 'nothing')
);

foreach ($passCarriesBoth ? $fixtureText : [] as $fixtureLabel => $template) {
    $expected = $template;
    foreach ($dateCells as $key => $cell) {
        $spelt = tableExpression($cell, $fixtureLabel, $template);
        if ($spelt === null) {
            // Already reported per fixture above; deriving an expectation from a
            // table that no longer matches would only fail for the wrong reason.
            continue 2;
        }
        $expected = str_replace($spelt, $mods[$key]['replace'], $expected);
    }

    [$composed, $counts] = applyPass($pass, $template);

    check(
        "one composed pass over $fixtureLabel rewrites both date expressions and nothing else",
        $composed === $expected,
        'got: ' . var_export($composed, true)
    );

    // $dateCells is written record-first while the pass runs award-first — the
    // two share an execution_order, so modification_key breaks the tie — and ===
    // on arrays compares keys in order. Sorting both sides keeps the comparison
    // about the counts rather than about which order the table happens to be
    // written in.
    $dateCounts = array_intersect_key($counts, $dateCells);
    $wantCounts = array_fill_keys(array_keys($dateCells), 1);
    ksort($dateCounts);
    ksort($wantCounts);
    check(
        "each date modification still matches exactly once in the composed pass over $fixtureLabel",
        $dateCounts === $wantCounts,
        'counts: ' . var_export($counts, true)
    );

    // Every key in the pass, not just the two the table knows: applyModification()
    // hands back a string sentinel where XenForo would record a status against
    // the modification and apply nothing, and the intersect above drops exactly
    // the third-modification case building the pass from the XML exists to pick
    // up — an enabled preg_replace whose find is an invalid regex is silent
    // otherwise.
    //
    // Deliberately not asserting every count is non-zero: the fixtures are
    // trimmed to the date rows, so a legitimate later modification aimed at
    // markup they elide would match nothing here for a reason that is not a
    // defect.
    $errored = array_keys(array_filter($counts, fn ($count) => !is_int($count)));
    check(
        "no modification in the composed pass over $fixtureLabel reports an error status",
        $errored === [],
        'errored: ' . implode(', ', $errored)
            . ' — XenForo records the status and the modification does nothing'
    );

    // Scoped to the two milpac columns, not to the string 'date(' anywhere: the
    // fixtures are trimmed captures, and a recapture that keeps a neighbouring
    // cell with an unrelated date() call in it is not a regression.
    $surviving = [];
    foreach ($dateCells as $key => $cell) {
        if (preg_match($cell['survivingCallRe'], $composed) !== 0) {
            $surviving[] = $key;
        }
    }
    check(
        "the composed pass over $fixtureLabel leaves no viewer-timezone date() call on either milpac column",
        $surviving === [],
        'still matched by: ' . implode(', ', $surviving)
            . ' — a surviving date() call keeps the per-viewer shift'
    );
}

// --- both dates survive however the date() call is spelled -------------------
// A style copy can differ from the vendor in whitespace, or carry arguments the
// vendor never wrote, and neither should decide whether the swap happens. Nor
// should the markup around the expression: the modification replaces the
// expression and owns none of the wrapper, so the wrappers below vary — a bare
// cell, an attributed one, and none at all.
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

    foreach ($cell['spellings'] as $label => [$call, $wrapper]) {
        $template = "<xf:datarow>\n    " . sprintf($wrapper, $call) . "\n</xf:datarow>";
        $expected = str_replace($call, $mod['replace'], $template);

        [$result, $count] = applyModification($mod, $template);

        check(
            "$key matches $label exactly once",
            $count === 1,
            'match count: ' . var_export($count, true)
        );
        check(
            "$key rewrites $label to exactly " . $mod['replace'] . ', wrapper untouched',
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
