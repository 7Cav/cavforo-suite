<?php

/**
 * Runs the milpac date template modifications over date-cell markup written out
 * below, and asks what they do to it: after XenForo applies them, does the date
 * cell go through the UTC getter? The two modifications match the date cell by
 * shape rather than by one exact vendor spelling: see the block above the
 * spellings loop for the regression that bought that, and the block above the
 * nonMatches loop for where the shape deliberately stops.
 *
 * What this pins is the patterns — the shipped <find>/<replace>, read straight
 * out of _data/template_modifications.xml — against date-cell spellings written
 * here by hand. It quotes no vendor file. The NF/Rosters expression it does name
 * is the same one the shipped <find> already describes, a template modification
 * being a description of the markup it targets.
 *
 * Two limits, neither of which this suite can close on its own:
 *
 *   - It never reaches the add-on you have installed. CI has no XenForo and no
 *     vendor tree, so an NF/Rosters release that moved or rewrote the date cell
 *     would leave every check here green while the board went back to per-viewer
 *     dates. Verifying the patterns against a real install is a dev-stack job,
 *     to be re-run after an NF/Rosters upgrade or a style edit. The two copies
 *     of nf_rosters_user_view come from different places there: a style's own
 *     copy is readable through the admin control panel's template editor
 *     (Appearance -> Templates), while the vendor's master copy is not on a
 *     production board (XF\Entity\Style::canEdit() returns false for style_id 0
 *     outside development mode, StyleRepository::createStyleTree() leaves master
 *     out of the style selector, and the editor answers
 *     templates_in_this_style_can_not_be_modified) — read that one out of
 *     NF/Rosters' own _data/templates.xml instead. A style with no copy of its
 *     own renders master, so its template list shows the vendor text, but that
 *     is the style's entry and not master's.
 *   - applyModification() below is a hand-written mirror of
 *     XF\Repository\TemplateModificationRepository::applyTemplateModifications(),
 *     not a call into it. It is faithful for everything this add-on ships — see
 *     the note on that function for the three places it diverges and why none of
 *     them is reachable — but nothing here enforces that it stays faithful
 *     across a XenForo upgrade.
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
 * The date expression each modification owns.
 *
 * The modification replaces a whole {{ date(...) }} expression with a bare
 * getter expression; the <xf:cell> tags around it belong to whatever markup the
 * expression sits in, and the modification neither matches nor writes them. So
 * the replacement literal below is wrapper-free — the expression alone, none of
 * the markup around it. What gets asserted is not: each expectation is a whole
 * template, wrapper and all, with that one expression swapped for the literal.
 * That kills a find that matches only the column reference, or one truncated so
 * the tail of the date() call survives, and it kills a replacement that carries
 * markup of its own.
 *
 * - replacement:        the getter expression the modification is expected to
 *                       write, spelled out here rather than read back off the
 *                       record under test. canonicalCall and spellings pin the
 *                       span the find takes; this pins what lands in it, so an
 *                       expectation cannot agree with a replacement that broke
 *                       the row it was written into.
 * - survivingCallRe:    a PCRE pattern, delimiters included, that finds a
 *                       viewer-timezone date() call the modification failed to
 *                       remove.
 * - canonicalCall:      the spelling the composed-pass template below is built
 *                       from — one date cell per modification, so the pass has
 *                       something to accumulate over. Written out here rather
 *                       than taken from a spellings label, so renaming a label
 *                       cannot quietly change what the pass runs against.
 * - spellings:          date() call spellings the pattern has to cope with, each
 *                       as [expression, the markup it sits in]. That markup is a
 *                       sprintf format carrying exactly one %s, which is where
 *                       the expression goes. The wrappers vary on purpose: a
 *                       find re-anchored onto <xf:cell> passes a harness that
 *                       only ever hands it a bare <xf:cell>, while on a board it
 *                       silently misses every style that attributes the cell,
 *                       lifts the expression out of the table, or writes
 *                       anything of its own after it.
 * - nonMatches:         expressions the pattern deliberately leaves alone. They
 *                       carry no markup of their own — the loop below drops each
 *                       into the same plain cell — because the property is that
 *                       nothing matches, which no wrapper can turn into a match
 *                       the whole-expression patterns would otherwise refuse.
 *                       Each has to be markup a style could actually carry, so
 *                       the concatenation entry uses '.', XenForo's concat
 *                       operator. Twig's '~' is not one: the compiler rejects
 *                       `{{ date(...) ~ ' UTC' }}` as a syntax error whatever
 *                       this add-on does, so it records no limit.
 *
 * Keys are modification_key, joining against loadModifications().
 *
 * @var array<string, array{
 *     replacement: string,
 *     survivingCallRe: string,
 *     canonicalCall: string,
 *     spellings: array<string, array{0: string, 1: string}>,
 *     nonMatches: array<string, string>
 * }> $dateCells
 */
$dateCells = [
    'cav7RosterPatchRecordDateUtc' => [
        'replacement'     => '{$record.getRecordDate()}',
        'survivingCallRe' => '/date\s*\(\s*\$record\.record_date/',
        'canonicalCall'   => "{{ date(\$record.record_date, 'Y-m-d') }}",
        'spellings' => [
            'the vendor spelling'                 => ["{{ date(\$record.record_date, 'Y-m-d') }}", '<xf:cell>%s</xf:cell>'],
            "a trailing 'Z' argument"             => ["{{ date(\$record.record_date, 'Y-m-d', 'Z' )}}", '<xf:cell>%s</xf:cell>'],
            'no space inside the braces'          => ["{{date(\$record.record_date, 'Y-m-d')}}", '<xf:cell>%s</xf:cell>'],
            'a space before the argument list'    => ["{{ date (\$record.record_date, 'Y-m-d') }}", '<xf:cell>%s</xf:cell>'],
            'space around the argument list'      => ["{{ date( \$record.record_date , 'Y-m-d' ) }}", '<xf:cell>%s</xf:cell>'],
            'a line break inside the call'        => ["{{ date(\n    \$record.record_date,\n    'Y-m-d'\n) }}", '<xf:cell>%s</xf:cell>'],
            'no format argument at all'           => ['{{ date($record.record_date) }}', '<xf:cell>%s</xf:cell>'],
            'an attributed cell around it'        => ["{{ date(\$record.record_date, 'Y-m-d') }}", '<xf:cell class="u-alignRight">%s</xf:cell>'],
            'no cell around it at all'            => ["{{ date(\$record.record_date, 'Y-m-d') }}", '%s'],
            'a style suffix after the expression' => ["{{ date(\$record.record_date, 'Y-m-d') }}", '<xf:cell>%s (UTC)</xf:cell>'],
        ],
        'nonMatches' => [
            'a null-guard ternary around the record call' => "{{ \$record.record_date ? date(\$record.record_date, 'Y-m-d') : '-' }}",
            'a filter after the record call'              => "{{ date(\$record.record_date, 'Y-m-d')|escape }}",
            'a concatenated suffix on the record call'    => "{{ date(\$record.record_date, 'Y-m-d') . ' UTC' }}",
            'a nested call as the record format'          => "{{ date(\$record.record_date, fmt('Y-m-d')) }}",
        ],
    ],
    'cav7RosterPatchAwardDateUtc' => [
        'replacement'     => '{$award.getAwardDate()}',
        'survivingCallRe' => '/date\s*\(\s*\$award\.award_date/',
        'canonicalCall'   => "{{ date(\$award.award_date, 'Y-m-d') }}",
        'spellings' => [
            'the vendor spelling'                 => ["{{ date(\$award.award_date, 'Y-m-d') }}", '<xf:cell>%s</xf:cell>'],
            "a trailing 'Z' argument"             => ["{{ date(\$award.award_date, 'Y-m-d', 'Z' )}}", '<xf:cell>%s</xf:cell>'],
            'no space inside the braces'          => ["{{date(\$award.award_date, 'Y-m-d')}}", '<xf:cell>%s</xf:cell>'],
            'a space before the argument list'    => ["{{ date (\$award.award_date, 'Y-m-d') }}", '<xf:cell>%s</xf:cell>'],
            'space around the argument list'      => ["{{ date( \$award.award_date , 'Y-m-d' ) }}", '<xf:cell>%s</xf:cell>'],
            'a line break inside the call'        => ["{{ date(\n    \$award.award_date,\n    'Y-m-d'\n) }}", '<xf:cell>%s</xf:cell>'],
            'no format argument at all'           => ['{{ date($award.award_date) }}', '<xf:cell>%s</xf:cell>'],
            'an attributed cell around it'        => ["{{ date(\$award.award_date, 'Y-m-d') }}", '<xf:cell class="u-alignRight">%s</xf:cell>'],
            'no cell around it at all'            => ["{{ date(\$award.award_date, 'Y-m-d') }}", '%s'],
            'a style suffix after the expression' => ["{{ date(\$award.award_date, 'Y-m-d') }}", '<xf:cell>%s (UTC)</xf:cell>'],
        ],
        'nonMatches' => [
            'a null-guard ternary around the award call' => "{{ \$award.award_date ? date(\$award.award_date, 'Y-m-d') : '-' }}",
            'a filter after the award call'              => "{{ date(\$award.award_date, 'Y-m-d')|escape }}",
            'a concatenated suffix on the award call'    => "{{ date(\$award.award_date, 'Y-m-d') . ' UTC' }}",
            'a nested call as the award format'          => "{{ date(\$award.award_date, fmt('Y-m-d')) }}",
        ],
    ],
];

$mods = loadModifications($root);

// Nothing below can mean anything if a modification failed to load, so say so
// once here rather than once per spelling.
//
// The replacement is pinned here too, once, against the table's own literal.
// Every expectation below is built by putting that literal into the markup, so
// this is the only place the shipped replacement is read for what it says rather
// than used as its own expected value: a replacement carrying a stray
// </xf:cell>, or a second expression beside the getter, breaks the row on a
// board while satisfying an expectation derived from itself.
foreach ($dateCells as $key => $cell) {
    check("$key loaded as an enabled modification", isset($mods[$key]));
    check(
        "$key replaces the date expression with exactly " . $cell['replacement'],
        ($mods[$key]['replace'] ?? null) === $cell['replacement'],
        'got: ' . var_export($mods[$key]['replace'] ?? null, true)
    );
    // The wrapper-free shape the docblock describes, asserted rather than
    // described. Every expectation below is built by putting this literal into
    // markup the table already carries, so a replacement that brought its own
    // <xf:cell> would be wrapped twice on a board while every expectation agreed
    // with it — the check above included, since it compares the shipped
    // <replace> against this same literal.
    check(
        "$key's replacement carries no markup of its own",
        !str_contains($cell['replacement'], '<'),
        'got: ' . var_export($cell['replacement'], true)
            . ' — the modification owns the expression, not the cell around it'
    );
}

// Every assertion further down is written "for each entry, assert X". That says
// nothing at all about a table with no entries — set 'spellings' or 'nonMatches'
// to [] and the loops below run zero times and report zero failures — and it
// says the same thing repeatedly about a table whose entries are copies of one
// another: replace three spellings with byte copies of the vendor spelling and
// the whitespace narrowing, the single-line narrowing and the trailing anchor
// all stop being covered while the label list still reads as ten spellings.
// nonMatches is the worse of the two to lose, being the only record left of the
// patterns' deliberate limits.
foreach ($dateCells as $key => $cell) {
    $signatures = [
        'spellings'  => array_map(
            static fn (array $spelling) => $spelling[1] . "\0" . $spelling[0],
            $cell['spellings']
        ),
        // Every nonMatch goes into the same plain cell, so the expression alone
        // is the whole of what one entry covers.
        'nonMatches' => $cell['nonMatches'],
    ];

    foreach ($signatures as $table => $entries) {
        check(
            "\$dateCells['$key'] carries $table to run",
            $entries !== [],
            'an emptied table runs no checks and reddens nothing'
        );

        $duplicates = [];
        $seen = [];
        foreach ($entries as $label => $signature) {
            if (isset($seen[$signature])) {
                $duplicates[] = "'$label' repeats '" . $seen[$signature] . "'";
            } else {
                $seen[$signature] = $label;
            }
        }
        check(
            "no two \$dateCells['$key'] $table are the same expression in the same wrapper",
            $duplicates === [],
            (implode('; ', $duplicates) ?: 'none')
                . ' — a copied entry reads as coverage this table does not have'
        );
    }
}

// --- the whole shipped set over one template, the way XenForo runs the pass --
// XenForo applies every enabled modification for one (type, template) to a
// single accumulating template, in (execution_order, modification_key) order, so
// each one sees the last one's output. The set comes from the XML rather than
// from $dateCells: a third modification on nf_rosters_user_view — the add-on is
// the declared home for later NF/Rosters patches — is in the pass whether this
// table knows about it or not, and if it puts a viewer-timezone date() call back
// the final markup says so. $dateCells stays the expectation table.
//
// The template is built here, one canonical date cell per modification, rather
// than captured from a board. Two rows is all the pass needs to accumulate over,
// and the spellings loop further down is where the shapes a real style might
// carry are covered.
$pass = modificationsForTemplate($mods, 'public', 'nf_rosters_user_view');
$passCarriesBoth = array_diff(array_keys($dateCells), array_keys($pass)) === [];

check(
    'the composed pass carries both date modifications',
    $passCarriesBoth,
    'the pass runs: ' . (implode(', ', array_keys($pass)) ?: 'nothing')
);

if ($passCarriesBoth) {
    $passTemplate = '';
    $passExpected = '';

    foreach ($dateCells as $key => $cell) {
        // survivingCallRe is only ever asserted negatively — no such call is
        // left. A pattern that matches nothing at all satisfies every one of
        // those, so pin it positively here: the call the pass template is built
        // from is one this pattern can actually find.
        check(
            "\$dateCells['$key'] finds its own canonical call before the modification runs",
            preg_match_all($cell['survivingCallRe'], $cell['canonicalCall']) === 1,
            'a pattern that cannot match the unmodified call turns every "no call survived" check into a tautology'
        );

        $row = "<xf:datarow>\n    <xf:cell>" . $cell['canonicalCall'] . "</xf:cell>\n</xf:datarow>\n";
        $passTemplate .= $row;
        $passExpected .= str_replace($cell['canonicalCall'], $cell['replacement'], $row);
    }

    [$composed, $counts] = applyPass($pass, $passTemplate);

    check(
        'one composed pass rewrites both date expressions and nothing else',
        $composed === $passExpected,
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
        'each date modification still matches exactly once in the composed pass',
        $dateCounts === $wantCounts,
        'counts: ' . var_export($counts, true)
    );

    // Scoped to the two milpac columns, not to the string 'date(' anywhere: a
    // later row carrying an unrelated date() call is not a regression.
    $surviving = [];
    foreach ($dateCells as $key => $cell) {
        if (preg_match($cell['survivingCallRe'], $composed) !== 0) {
            $surviving[] = $key;
        }
    }
    check(
        'the composed pass leaves no viewer-timezone date() call on either milpac column',
        $surviving === [],
        'still matched by: ' . implode(', ', $surviving)
            . ' — a surviving date() call keeps the per-viewer shift'
    );
}

// --- no shipped modification, on any template, comes back as a status --------
// applyModification() hands back a string sentinel instead of a count wherever
// this mirror stops, and those places are not all places XenForo stops. It does
// record a status and apply nothing for a find that will not compile, and for an
// unknown action. It does not for the other two: on a find carrying the /e
// modifier it records the error and replaces anyway, and action 'callback'
// shares the preg_replace branch and comes back with a match count. So this
// asserts something narrower than "XenForo would do nothing here" — that no
// modification the add-on ships reaches a status this mirror calls an error.
// Add a legitimate callback modification later and this reddens while the board
// is fine: the mirror is what needs teaching then, not the modification.
//
// Every (type, template) the XML targets, not just nf_rosters_user_view. This
// add-on is the declared home for later NF/Rosters patches, and a patch on
// another template is covered by nothing above, so an enabled preg_replace whose
// find will not compile would otherwise be exercised by nothing at all. The
// subject is an empty template on purpose: a find that cannot compile fails
// against any subject, and match counts are the other blocks' business.
$targets = [];
foreach ($mods as $mod) {
    $targets[$mod['type'] . ':' . $mod['template']] = [$mod['type'], $mod['template']];
}

foreach ($targets as $label => [$type, $template]) {
    [, $counts] = applyPass(modificationsForTemplate($mods, $type, $template), '');
    $errored = array_keys(array_filter($counts, fn ($count) => !is_int($count)));
    check(
        "no modification in the pass over $label reports an error status",
        $errored === [],
        'errored: ' . implode(', ', $errored)
            . ' — XenForo records the status and the modification does nothing'
    );
}

// --- both dates survive however the date() call is spelled -------------------
// A style copy can differ from the vendor in whitespace, or carry arguments the
// vendor never wrote, and neither should decide whether the swap happens. Nor
// should the markup around the expression: the modification replaces the
// expression and owns none of the wrapper, so the wrappers below vary — a bare
// cell, an attributed one, and none at all.
//
// This block carries the regression the patterns were written for. A find that
// quotes one exact spelling takes the vendor entry and misses every other one
// here — which is what a style carrying its own edited copy of the template does
// to it on a live board, silently, since XenForo records a zero-match
// modification without erroring.
//
// 'the vendor spelling' is the one NF/Rosters ships, as of 2.1.5; the rest are
// shapes a style could put in its own copy. That label is the only thing left
// recording which of these the vendor writes, the captured template having gone
// — so keep it, and re-check it when NF/Rosters is upgraded.
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
        // The wrapper is a sprintf format and the %s is where the expression
        // goes. Drop it and sprintf() quietly returns the wrapper alone, so the
        // template never carries the call and every check below reports a match
        // count of 0 — which reads as a broken pattern rather than a broken row
        // of this table. Say which it is.
        check(
            "the wrapper \$dateCells['$key'] gives $label has exactly one %s for the expression",
            substr_count($wrapper, '%s') === 1,
            'got: ' . var_export($wrapper, true)
                . ' — without it the spelling never reaches the template it is meant to be found in'
        );

        $template = "<xf:datarow>\n    " . sprintf($wrapper, $call) . "\n</xf:datarow>";
        $expected = str_replace($call, $cell['replacement'], $template);

        [$result, $count] = applyModification($mod, $template);

        check(
            "$key matches $label exactly once",
            $count === 1,
            'match count: ' . var_export($count, true)
                . ' — 0 means the date keeps rendering in the viewer timezone and XenForo says nothing'
        );
        check(
            "$key rewrites $label to exactly " . $cell['replacement'] . ', wrapper untouched',
            $result === $expected,
            'got: ' . str_replace("\n", '\n', $result)
        );
        check(
            "$key leaves no viewer-timezone date() call in $label",
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
            "$key is idempotent over $label",
            $againCount === 0 && $again === $result,
            're-applying matched ' . var_export($againCount, true) . ' time(s)'
        );
    }
}

// --- and the cells the pattern deliberately will not touch -------------------
// The pattern matches a whole {{ date(...) }} expression, not the date() call
// inside one, so a style that wrapped or extended the expression is left alone.
//
// These four are limits, recorded rather than fixed, and widening a pattern to
// cover one of them is a decision to take deliberately with the list in front
// of you.
//
// Not because a call-anchored replacement would produce invalid markup: put all
// four through XenForo 2.3.11's own template compiler with the call swapped for
// the getter and all four compile, the ternary to exactly the code you would
// write by hand —
// ($__vars['record']['record_date'] ? $__templater->escape($__templater->method(
// $__vars['record'], 'getRecordDate', array())) : '-') — since {$…} is an
// expression term inside {{ … }} the same way it is a variable in running text
// (expression_part ::= var in the compiler's own grammar).
//
// The limit stays because a <find> is a regular expression over template text
// rather than a parse of it, and these patterns swallow the format argument.
// Anchored on the whole {{ … }} expression they only ever reach a cell that
// renders a date, where losing the style's format is a cost the fix owns (see
// the spellings block above). Anchored on the call they would reach every
// date($record.record_date, …) in the template, including the ones where the
// format is doing work: <xf:if is="date($record.record_date, 'Y') == 2020">
// starts comparing '2020-03-04' against '2020', and
// <div data-day="{{ date($record.record_date, 'D') }}"> starts emitting a full
// date to whatever reads that attribute. Both spellings compile, so nothing
// reports either. Widening a find means first reading the surrounding logic of
// every expression it would newly take, and no one has.
foreach ($dateCells as $key => $cell) {
    $mod = $mods[$key] ?? null;
    if ($mod === null) {
        continue;
    }

    foreach ($cell['nonMatches'] as $label => $call) {
        // "The pattern does not match this" is satisfied by any string at all,
        // so a table entry that stopped being a date() call on this column would
        // pass while asserting nothing. These four are the documented limits, so
        // pin what each one is: a viewer-timezone call on the column this
        // modification owns, which the find is deliberately declining to take.
        check(
            "$label really is a viewer-timezone call on the column $key owns",
            preg_match($cell['survivingCallRe'], $call) === 1,
            'got: ' . var_export($call, true)
                . ' — an entry the surviving-call pattern cannot find records no limit'
        );

        $template = "<xf:datarow>\n    <xf:cell>$call</xf:cell>\n</xf:datarow>";
        [$result, $count] = applyModification($mod, $template);

        check(
            "$key leaves $label alone",
            $count === 0 && $result === $template,
            'match count: ' . var_export($count, true)
                . ' — matching here would swallow the format argument of an'
                . ' expression whose surrounding logic nobody has read'
        );
    }
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
