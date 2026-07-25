<?php

/**
 * Issue #75 — pins the vendor-coupled wiring of the enlistment reminder so a
 * regression fails CI rather than shipping silently. The rules themselves are
 * exercised for real in ReminderDecisionTest (the remind rule),
 * ProcessingStatusTest (the #186 status-prefix read) and EnlistmentRoutingTest
 * (the #144 type split); this holds the parts that need a live XenForo,
 * NF/Rosters and SV/MultiPrefix to run: the hourly cron entry, the options and
 * their runtime reads, the marker table created on install and dropped on
 * uninstall, the deadline clamp, the clerk-seat query, the node-scoped scan, the
 * SteamChecker-style bot post with its first_post_id correction, the per-type
 * alert routing with its prefix-badged template and its two skip-and-log
 * branches, and — per issue #186 — the prefix link read with the guards that stop
 * a config or vendor fault either reminding the whole queue at once or silencing
 * it for good.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ScanWiringTest.php
 */

namespace Cav7\EnlistmentReminder\Tests;

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

/**
 * All _output template files across their style-type folders (public/admin/email),
 * excluding the _metadata.json index. Templates nest one level deeper than the flat
 * item types, so outputItems() (a flat glob) can't see the actual template files.
 */
function outputTemplateItems(string $root): array
{
    $items = [];
    foreach (glob("$root/_output/templates/*", GLOB_ONLYDIR) ?: [] as $styleDir) {
        foreach (glob("$styleDir/*") ?: [] as $f) {
            if (basename($f) !== '_metadata.json') {
                $items[] = $f;
            }
        }
    }
    return $items;
}

/**
 * The source of one method's body, from its `function <name>` declaration up to
 * whichever comes first: the next method's docblock, the next method's
 * declaration, or end-of-file for the last method. Anchoring a best-effort
 * catch check to the owning method this way keeps a catch in a later method from
 * satisfying it, and stops the following method's docblock prose (which may talk
 * about the same failure) from bleeding into the search.
 *
 * The four-space indent in the terminator pattern is load-bearing and is the one
 * place in this file where an exact indent is deliberate: a method-level docblock
 * sits at four spaces, while the `/** @var ... *\/` annotations INSIDE a body sit
 * at eight or more. Loosening it to `\s+` would cut postReminderNote's and
 * alertClerks's bodies off at their first annotation. Anything built on top of
 * this (see the whole-line allowlists below) trims before anchoring instead, so
 * re-indenting the worker only matters here.
 */
function methodBody(string $src, string $name): string
{
    $start = strpos($src, 'function ' . $name);
    if ($start === false) {
        return '';
    }
    $body = substr($src, $start);
    if (preg_match('~\n    (?:/\*\*|(?:private|protected|public)\s+function\s)~', $body, $m, PREG_OFFSET_CAPTURE)) {
        $body = substr($body, 0, $m[0][1]);
    }
    return $body;
}

/**
 * A skip branch's source, from a start marker up to its first `continue;`. Both
 * #144 skip branches (unrecognized prefix, empty per-type audience) are a single
 * `if (...) { ...logError(...); continue; }` with no nested braces, so slicing to
 * the first `continue;` after the marker captures exactly that branch body. Used
 * to assert the branch writes nothing: a regression that slipped a
 * postReminderNote/recordReminder in before the `continue` would mark a real
 * enlistment done with no clerk alerted — dropping its alert for good — yet still
 * satisfy a looser "a continue exists somewhere after the branch" pin.
 */
function branchToContinue(string $src, string $startMarker): string
{
    $start = strpos($src, $startMarker);
    if ($start === false) {
        return '';
    }
    $end = strpos($src, 'continue;', $start);
    if ($end === false) {
        return '';
    }
    return substr($src, $start, $end - $start);
}

/**
 * One `if (...) { ... }` block, from its start marker to the `}` that closes it,
 * found by counting braces rather than by matching up to some later token.
 *
 * This is what makes the abort-guard pins below real. Written as
 * `/if \(!\$x\).*?logError\(.*?return;/s`, a guard pin passes on a body with its
 * `return;` deleted, because the `/s` and the lazy `.*?` let the match run on to
 * any later bare `return;` in the file — `if (!$threads) { return; }` sits about
 * fifty lines below the guards, and alertClerks has another. Slicing the block
 * first bounds the search to the guard's own body, so a deleted `return;` fails
 * the pin instead of borrowing one from a stranger.
 *
 * Brace counting is naive about braces inside strings, and the two directions are
 * NOT equally safe. A stray CLOSING brace ends the slice early, which fails the
 * pin rather than passing it falsely. A stray OPENING brace does the opposite:
 * depth never returns to 0 at the guard's own `}`, so the slice runs on through
 * the rest of the enclosing method and can borrow exactly the `logError(` and
 * `return;` this helper exists to exclude. A BALANCED pair inside a string (a
 * `{option}` placeholder) is harmless either way; a lone one is not.
 *
 * The `rtrim` guard below is the cheap defence: a slice that reaches the end of
 * its input is the tell that counting lost the block, because every guard this is
 * used on has code after it. Callers pass a single method body (see methodBody),
 * so "end of input" means the enclosing method's own closing brace.
 */
function ifBlock(string $src, string $startMarker): string
{
    $start = strpos($src, $startMarker);
    if ($start === false) {
        return '';
    }
    $open = strpos($src, '{', $start);
    if ($open === false) {
        return '';
    }

    $depth = 0;
    for ($i = $open, $len = strlen($src); $i < $len; $i++) {
        if ($src[$i] === '{') {
            $depth++;
        } elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return rtrim(substr($src, $i + 1)) === ''
                    ? ''
                    : substr($src, $start, $i - $start + 1);
            }
        }
    }
    return '';
}

/**
 * True when the given `if` block both logs an error and returns — the shape every
 * abort guard in remind() has to keep. Scoped to the block by ifBlock(), so the
 * `return;` has to be the guard's own.
 *
 * Callers pass remind()'s body, never the whole file, so a guard lifted into some
 * other method — an uncalled private helper, or one declared above remind() —
 * cannot satisfy this: its text is not in the body under test.
 */
function abortsWithLoggedError(string $src, string $startMarker): bool
{
    $block = ifBlock($src, $startMarker);
    return $block !== ''
        && str_contains($block, 'logError(')
        && (bool) preg_match('/\breturn;/', $block);
}

/**
 * Every line of $methodBody that uses the variable $variable, trimmed, in source
 * order. "Uses" means the bare name: `$inProcessingPrefixIds` is not a use of
 * `$inProcessing`, so the lookahead stops the longer name from inflating a count
 * the pins below read as an exact number.
 *
 * @return string[]
 */
function variableUseLines(string $methodBody, string $variable): array
{
    $pattern = '/' . preg_quote($variable, '/') . '(?![A-Za-z0-9_])/';
    $lines = [];
    foreach (explode("\n", $methodBody) as $line) {
        if (preg_match($pattern, $line)) {
            $lines[] = trim($line);
        }
    }
    return $lines;
}

/**
 * The use lines no allowed pattern accounts for. Each pattern is matched against
 * the WHOLE trimmed line, anchored at both ends, which is what makes this a
 * use-counting pin rather than a substring hunt:
 *
 *   - a second statement appended to an allowed line (a suppression map rebuilt
 *     on the same line as the assignment that is allowed there) leaves the line
 *     unmatched, so it surfaces as unexpected instead of hiding behind the
 *     allowed prefix;
 *   - anything appended INSIDE an allowed expression (`&& $threadId < 0` on the
 *     in_processing fact) fails the end anchor for the same reason.
 *
 * Trimming first is deliberate: the anchors then say nothing about indentation,
 * so re-indenting the worker cannot break a pin.
 *
 * @param string[] $useLines
 * @param string[] $allowedWholeLine
 * @return string[]
 */
function unexpectedUseLines(array $useLines, array $allowedWholeLine): array
{
    $unexpected = [];
    foreach ($useLines as $line) {
        foreach ($allowedWholeLine as $allowed) {
            if (preg_match($allowed, $line)) {
                continue 2;
            }
        }
        $unexpected[] = $line;
    }
    return $unexpected;
}

/**
 * Where $marker sits inside $methodBody, or null if it is not there at all.
 *
 * Every ordering pin below runs through this, for two reasons. Offsets taken over
 * the WHOLE FILE are satisfied by text in any method that happens to be declared
 * earlier, so a guard lifted out of remind() into a private helper above it still
 * reads as "before the decision" — the ordering pin passes while the guard no
 * longer runs. And a marker that has moved or been renamed has to fail LOUDLY:
 * null is reported by name below rather than compared as an integer.
 */
function markerOffset(string $methodBody, string $marker): ?int
{
    $at = strpos($methodBody, $marker);
    return $at === false ? null : $at;
}

/**
 * Assert that $beforeMarker appears before $afterMarker within one method body,
 * failing with the marker it could not find rather than an empty mismatch.
 */
function checkOrderedWithin(string $methodBody, string $label, string $beforeMarker, string $afterMarker, string $why): void
{
    $beforeAt = markerOffset($methodBody, $beforeMarker);
    $afterAt  = markerOffset($methodBody, $afterMarker);

    if ($beforeAt === null || $afterAt === null) {
        $missing = [];
        if ($beforeAt === null) {
            $missing[] = $beforeMarker;
        }
        if ($afterAt === null) {
            $missing[] = $afterMarker;
        }
        check($label, false, 'not found in remind(): ' . implode(' | ', $missing));
        return;
    }

    check($label, $beforeAt < $afterAt, $why);
}

/**
 * Assert that $beforeMarker appears before EVERY marker in $afterMarkers, within one
 * method body.
 *
 * The strong form of checkOrderedWithin, and the only form that can pin "before all
 * the aborts". Naming one guard as the comparison point pins the ordering against
 * that guard alone: the pin then reads as a claim about every abort while holding
 * exactly one, and the moment a different abort moves above the named one the pin
 * passes on the arrangement it exists to forbid. Taking the minimum offset over the
 * whole inventory holds all of them and stops the pin depending on which abort
 * currently comes first.
 *
 * Fails loudly rather than quietly comparing against a smaller set: a marker missing
 * in either role is reported by name, and an empty $afterMarkers is a failure too,
 * since min() over nothing would otherwise be the bug this helper is here to avoid.
 *
 * @param string[] $afterMarkers
 */
function checkOrderedBeforeAll(string $methodBody, string $label, string $beforeMarker, array $afterMarkers, string $why): void
{
    $beforeAt = markerOffset($methodBody, $beforeMarker);
    $missing = $beforeAt === null ? [$beforeMarker] : [];

    $offsets = [];
    foreach ($afterMarkers as $marker) {
        $at = markerOffset($methodBody, $marker);
        if ($at === null) {
            $missing[] = $marker;
        } else {
            $offsets[$marker] = $at;
        }
    }

    if ($missing) {
        check($label, false, 'not found in remind(): ' . implode(' | ', $missing));
        return;
    }
    if (!$offsets) {
        check($label, false, 'no markers to compare against');
        return;
    }

    $earliestAt = min($offsets);
    $earliestMarker = array_search($earliestAt, $offsets, true);
    check($label, $beforeAt < $earliestAt, "$why (earliest abort in remind(): `$earliestMarker`)");
}

// --- the hourly cron entry is registered ----------------------------------
$cronXml = @simplexml_load_file("$root/_data/cron.xml");
check('_data/cron.xml could be read', $cronXml !== false);

$scanEntry = null;
if ($cronXml !== false) {
    foreach ($cronXml->entry as $entry) {
        if ((string) $entry['entry_id'] === 'cav7ERScanQueue') {
            $scanEntry = $entry;
        }
    }
}
check('the cav7ERScanQueue cron entry exists', $scanEntry !== null);
if ($scanEntry !== null) {
    check(
        'the cron points at Cav7\\EnlistmentReminder\\Cron\\ScanQueue::run and is active',
        (string) $scanEntry['cron_class'] === 'Cav7\EnlistmentReminder\Cron\ScanQueue'
            && (string) $scanEntry['cron_method'] === 'run'
            && (string) $scanEntry['active'] === '1',
        'class=' . (string) $scanEntry['cron_class'] . ' method=' . (string) $scanEntry['cron_method']
    );
    check(
        'the cron runs hourly (every hour, at minute 0)',
        str_contains((string) $scanEntry, '"hours":[-1]') && str_contains((string) $scanEntry, '"minutes":[0]'),
        'run_rules: ' . (string) $scanEntry
    );
}
check(
    '_output has one cron_entries file per _data cron entry',
    count(outputItems($root, 'cron_entries')) === ($cronXml !== false ? count($cronXml->entry) : -1)
);

// The cron entry method must actually exist on the class it names.
$scanSrc = (string) file_get_contents("$root/Cron/ScanQueue.php");
check('ScanQueue exposes a static run() entry method', (bool) preg_match('/static\s+function\s+run\s*\(/', $scanSrc));

// --- the options exist and are read at runtime ----------------------------
// $expectedOptions below is the list; every id in it must be defined in
// _data/options.xml and read somewhere at runtime, or it is dead config.
$optXml = @simplexml_load_file("$root/_data/options.xml");
check('_data/options.xml could be read', $optXml !== false);

$optionIds = [];
if ($optXml !== false) {
    foreach ($optXml->option as $option) {
        $optionIds[] = (string) $option['option_id'];
    }
}
$expectedOptions = [
    'cav7ERQueueNodeId', 'cav7ERBotUserId', 'cav7ERDeadlineHours',
    'cav7ERStandardPrefixIds', 'cav7ERStandardClerkPositionIds',
    'cav7ERReenlistPrefixIds', 'cav7ERReenlistClerkPositionIds',
    // Issue #186: the prefixes that mark an application as already being worked.
    'cav7ERInProcessingPrefixIds',
];
foreach ($expectedOptions as $id) {
    check("option $id is defined", in_array($id, $optionIds, true));
}
// The single pre-split option must be gone, not merely joined by the new four —
// a lingering cav7ERClerkPositionIds is dead config nothing reads any more.
check(
    'the retired single-clerk option cav7ERClerkPositionIds is removed',
    !in_array('cav7ERClerkPositionIds', $optionIds, true),
    'the type split replaces it with the four per-type options'
);
check(
    '_output has one options file per _data option',
    count(outputItems($root, 'options')) === ($optXml !== false ? count($optXml->option) : -1)
);

// Every option is read at runtime, across the cron entry and the worker together.
$worker = (string) file_get_contents("$root/QueueReminder.php");
$runtime = $scanSrc . $worker;
// remind()'s own body, read once. Every abort-guard pin and every ordering pin
// below is scoped to it rather than to the whole file: a guard moved into another
// method — even an uncalled private one declared above remind() — must fail its
// pins, and whole-file offsets cannot tell that apart from a guard that still runs.
$remindBody = methodBody($worker, 'remind');
check(
    "remind()'s body could be sliced, so the guard pins below have something to bind to",
    $remindBody !== '',
    'methodBody could not find "function remind" in QueueReminder.php'
);
// Read once here; the version bump and the SV/MultiPrefix require pair are checked
// further down.
$addonJson = (string) file_get_contents("$root/addon.json");
$addonManifest = json_decode($addonJson, true);
$multiPrefixFloorLiteral = $addonManifest['require']['SV/MultiPrefix'][0] ?? null;
foreach ($expectedOptions as $id) {
    check(
        "$id is read at runtime via \\XF::options()",
        (bool) preg_match('/options\(\)->' . preg_quote($id, '/') . '\b/', $runtime),
        'an option nothing reads is dead config'
    );
}

// The deadline default 24 and the queue node default 325 are what the issue asks;
// the four routing defaults are the agreed per-type sets whose position lists
// union to the pre-split default (579,580,751,960,1012), so clerk coverage is
// unchanged.
$defaults = [
    'cav7ERDeadlineHours'            => '24',
    'cav7ERQueueNodeId'              => '325',
    'cav7ERBotUserId'                => '598',
    'cav7ERStandardPrefixIds'        => '57',
    'cav7ERStandardClerkPositionIds' => '579,580,751,1012',
    'cav7ERReenlistPrefixIds'        => '58',
    'cav7ERReenlistClerkPositionIds' => '579,960,1012',
    // Issue #186: Hold (53), Approved (54) and In Progress (55) are the states
    // RRD's prefix state machine moves an application through once a clerk has
    // taken it on. The type prefixes 57/58 must NEVER appear here — every valid
    // queue thread carries one, so listing them would suppress the whole queue.
    'cav7ERInProcessingPrefixIds'    => '53,54,55',
];
$defaultByOption = [];
if ($optXml !== false) {
    foreach ($optXml->option as $option) {
        $defaultByOption[(string) $option['option_id']] = (string) $option->default_value;
    }
}
// A dev-mode install imports from _output, not _data, and
// check-data-consistency.php compares options by id and count rather than by
// content — so an _output default that has drifted from its _data twin reaches a
// dev stack unchallenged. A drifted cav7ERInProcessingPrefixIds is the worst of
// them: slip 57 in on the _output side and the add-on installs permanently silent.
foreach ($defaults as $id => $want) {
    check("the $id default is $want", ($defaultByOption[$id] ?? null) === $want);

    $outputFile = "$root/_output/options/$id.json";
    $outputJson = is_file($outputFile) ? json_decode((string) file_get_contents($outputFile), true) : null;
    check(
        "the _output copy of the $id default matches _data",
        is_array($outputJson) && ($outputJson['default_value'] ?? null) === $want,
        'dev mode installs from _output; got: ' . var_export($outputJson['default_value'] ?? null, true)
    );
}
// The default_value loop above covers one field. Everything else in an _output item
// is equally hand-editable and equally unchallenged by check-data-consistency, and
// `data_type` matters as much as the default does: flip the new option from `string`
// to `positive_integer` and `53,54,55` no longer survives an ACP save at all.
// Rather than enumerate fields, lean on the index xf-dev:export already writes —
// each item's md5 is recorded in its type's _metadata.json — so one loop catches
// every hand-edit to every item of every type.
foreach (['options', 'option_groups', 'cron_entries', 'class_extensions', 'phrases'] as $type) {
    $metadataFile = "$root/_output/$type/_metadata.json";
    $metadata = is_file($metadataFile) ? json_decode((string) file_get_contents($metadataFile), true) : null;
    check(
        "_output/$type/_metadata.json reads as the item index",
        is_array($metadata) && $metadata !== [],
        'without the index there is nothing to compare the item files against'
    );
    if (!is_array($metadata)) {
        continue;
    }

    $drifted = [];
    foreach (outputItems($root, $type) as $itemFile) {
        $name = basename($itemFile);
        $recorded = $metadata[$name]['hash'] ?? null;
        $actual = md5((string) file_get_contents($itemFile));
        if ($recorded !== $actual) {
            $drifted[] = $name . ' (recorded ' . var_export($recorded, true) . ', actual ' . $actual . ')';
        }
    }
    check(
        "every _output/$type item matches the md5 recorded for it in _metadata.json",
        $drifted === [],
        'hand-edited without re-exporting: ' . implode(' | ', $drifted)
    );
}
// The md5 loop proves _output is internally consistent, and nothing more. _DATA is
// what a production install imports, and check-data-consistency.php compares options
// by id and count rather than by content, so a hand-edited field there reaches the
// live board unchallenged. `data_type` is the field that matters most on the three
// comma-list options: as `unsigned_integer`, "53,54,55" coerces to 53, so In Progress
// and Approved threads read un-actioned and the queue is reminded on work already
// underway — #186's own regression, on the side the board installs from. The two type
// lists coerce the same way, taking the collision guard's ids with them.
$dataTypeByOption = [];
if ($optXml !== false) {
    foreach ($optXml->option as $option) {
        $dataTypeByOption[(string) $option['option_id']] = (string) $option['data_type'];
    }
}
foreach ([
    'cav7ERInProcessingPrefixIds',
    'cav7ERStandardPrefixIds',
    'cav7ERReenlistPrefixIds',
] as $listOption) {
    check(
        "the _data data_type for $listOption is string, so a comma list survives the save",
        ($dataTypeByOption[$listOption] ?? null) === 'string',
        'a numeric data_type keeps only the first id in the list; got: '
            . var_export($dataTypeByOption[$listOption] ?? null, true)
    );
    // And the two sides must agree, so the drift is caught whichever one is edited.
    $listOutputFile = "$root/_output/options/$listOption.json";
    $listOutputJson = is_file($listOutputFile)
        ? json_decode((string) file_get_contents($listOutputFile), true)
        : null;
    check(
        "the _output copy of $listOption's data_type matches _data",
        is_array($listOutputJson)
            && ($listOutputJson['data_type'] ?? null) === ($dataTypeByOption[$listOption] ?? null),
        'dev mode installs from _output; got: ' . var_export($listOutputJson['data_type'] ?? null, true)
    );
}
// Templates nest one level deeper and are indexed by their style-relative path
// ("public/alert_thread_enlistment_reminder.html") in one _metadata.json at the
// templates root, so they need their own pass rather than the flat loop above.
$templateMetadata = is_file("$root/_output/templates/_metadata.json")
    ? json_decode((string) file_get_contents("$root/_output/templates/_metadata.json"), true)
    : null;
$driftedTemplates = [];
foreach (outputTemplateItems($root) as $itemFile) {
    $key = basename(dirname($itemFile)) . '/' . basename($itemFile);
    $recorded = is_array($templateMetadata) ? ($templateMetadata[$key]['hash'] ?? null) : null;
    $actual = md5((string) file_get_contents($itemFile));
    if ($recorded !== $actual) {
        $driftedTemplates[] = $key . ' (recorded ' . var_export($recorded, true) . ', actual ' . $actual . ')';
    }
}
check(
    'every _output template matches the md5 recorded for it in _metadata.json',
    is_array($templateMetadata) && $templateMetadata !== [] && $driftedTemplates === [],
    'hand-edited without re-exporting: ' . implode(' | ', $driftedTemplates)
);
// The union of the two default position sets is exactly the old single default,
// so clerk coverage does not change when the alert audience splits by type.
$standardSeats = array_map('intval', explode(',', $defaults['cav7ERStandardClerkPositionIds']));
$reenlistSeats = array_map('intval', explode(',', $defaults['cav7ERReenlistClerkPositionIds']));
$union = array_values(array_unique(array_merge($standardSeats, $reenlistSeats)));
sort($union);
check(
    'the union of the two default clerk sets equals the pre-split five seats',
    $union === [579, 580, 751, 960, 1012],
    'got: ' . implode(',', $union)
);

// Issue #186's trap, pinned against the SHIPPED defaults rather than restated:
// the in-processing set must not overlap either type-prefix set. Every valid
// queue thread carries its Enlistment/Re-Enlistment prefix in the same link
// table, so a type prefix listed as a processing status reads the entire queue
// as handled and silences the add-on for good, with nothing to see in the log.
$shippedInProcessing = array_map('intval', explode(',', (string) ($defaultByOption['cav7ERInProcessingPrefixIds'] ?? '')));
$shippedTypePrefixes = array_map('intval', array_merge(
    explode(',', (string) ($defaultByOption['cav7ERStandardPrefixIds'] ?? '')),
    explode(',', (string) ($defaultByOption['cav7ERReenlistPrefixIds'] ?? ''))
));
check(
    'no enlistment type prefix is shipped as an in-processing status',
    array_intersect($shippedInProcessing, $shippedTypePrefixes) === [],
    'overlap: ' . implode(',', array_intersect($shippedInProcessing, $shippedTypePrefixes))
);

// --- the option group renders from the standard phrase pair ----------------
$phraseXml = @simplexml_load_file("$root/_data/phrases.xml");
check('_data/phrases.xml could be read', $phraseXml !== false);

$phraseTitles = [];
if ($phraseXml !== false) {
    foreach ($phraseXml->phrase as $phrase) {
        $phraseTitles[] = (string) $phrase['title'];
    }
}
check(
    'option_group.cav7EnlistmentReminder is present',
    in_array('option_group.cav7EnlistmentReminder', $phraseTitles, true)
);
check(
    'option_group_description.cav7EnlistmentReminder is present',
    in_array('option_group_description.cav7EnlistmentReminder', $phraseTitles, true)
);
check(
    '_output has one phrases file per _data phrase',
    count(outputItems($root, 'phrases')) === ($phraseXml !== false ? count($phraseXml->phrase) : -1)
);

// --- the marker table is created on install and dropped on uninstall -------
$setup = (string) file_get_contents("$root/Setup.php");
check('Setup.php reads', $setup !== '');
check(
    'installStep1 creates the marker table xf_cav7_enlistment_reminder',
    (bool) preg_match('/function\s+installStep1\b/', $setup)
        && (bool) preg_match("/createTable\(\s*'xf_cav7_enlistment_reminder'/", $setup),
    'the already-reminded signal has nowhere to live without the marker table'
);
check(
    'uninstallStep1 drops the marker table',
    (bool) preg_match('/function\s+uninstallStep1\b/', $setup)
        && (bool) preg_match("/dropTable\(\s*'xf_cav7_enlistment_reminder'/", $setup),
    'an add-on-owned table must be removed on uninstall'
);

// --- the deadline clamp is a loud substitution -----------------------------
check(
    'the cron clamps a below-minimum deadline to the default',
    str_contains($scanSrc, 'MIN_DEADLINE_HOURS')
        && str_contains($scanSrc, 'DEFAULT_DEADLINE_HOURS')
        && (bool) preg_match('/\$hours\s*<\s*self::MIN_DEADLINE_HOURS/', $scanSrc),
    'a 0 or negative deadline would remind the whole queue at once'
);
check(
    'the deadline substitution is logged, not silent',
    (bool) preg_match('/logError\(.*below the.*minimum/s', $scanSrc),
    'copy the loud-substitution pattern from RosterAudit AuditLogPrune'
);

// --- the clerk-seat query covers primary and secondary seats ---------------
check(
    'clerk holders are matched on the primary position (position_id IN)',
    (bool) preg_match('/position_id\s+IN/i', $worker)
);
check(
    'clerk holders are matched on secondary seats (FIND_IN_SET on secondary_position_ids)',
    str_contains($worker, 'FIND_IN_SET') && str_contains($worker, 'secondary_position_ids'),
    'dropping the secondary arm would miss every holder who carries the seat as a secondary duty'
);

// The two oldest config guards. Neither can mass-remind on its own — an
// unconfigured node scans nothing and an unconfigured bot posts nothing — but both
// are abort guards in the same family, and deleting either `return;` left the
// suite green until now: the run carried on to query xf_thread for node 0 and,
// with a bot the entity manager cannot find, logged a "not found" line per thread
// per hour instead of one line per run. Same shape, same pin.
check(
    'remind() aborts when the queue node id is not configured (logError + early return)',
    abortsWithLoggedError($remindBody, 'if (!$nodeId)'),
    'without the return the run scans node 0 and reports nothing useful'
);
check(
    'remind() aborts when the bot user id is not configured (logError + early return)',
    abortsWithLoggedError($remindBody, 'if (!$botUserId)'),
    'without the return every remindable thread logs "bot user not found" once an hour'
);

// An empty resolved clerk set must abort the run with a logged signal, symmetric
// with the node/bot guards. If both position options are blank or garbage, or
// the roster drifts so nothing resolves, getClerkUserIds returns [] and no
// thread can reach an audience: every remindable thread would fall through the
// per-type empty-audience skip and log the same complaint once an hour. One
// abort with one line says the same thing without the noise.
check(
    'remind() aborts when no clerk resolves (logError + early return on the empty clerk set)',
    abortsWithLoggedError($remindBody, 'if (!$clerkUserIds)'),
    'a run with nobody to alert should say so once, not once per thread per hour'
);

// --- the scan is scoped to open, visible threads in the one node -----------
check(
    'the queue scan filters on the configured node',
    (bool) preg_match('/node_id\s*=\s*\?/', $worker),
    'scanning all nodes would touch the Completed/Denied siblings'
);
check(
    'the queue scan takes only open, visible threads (discussion_state = visible AND discussion_open = 1)',
    (bool) preg_match(
        '/FROM xf_thread.*?discussion_state\s*=\s*\?.*?discussion_open\s*=\s*1\b.*?\[\$nodeId,\s*\'visible\'\]/s',
        $worker
    ),
    'flipping discussion_open to 0 or changing the bound visible literal must fail this, not merely renaming a column'
);
// Issue #144: the type split reads a thread's enlistment type from its primary
// prefix, so the queue fetch must additionally select prefix_id — without it the
// per-type routing has nothing to route on and every thread reads as unrouted.
check(
    'the queue scan selects prefix_id for type routing',
    (bool) preg_match('/SELECT[^;]*\bprefix_id\b.*?FROM xf_thread/s', $worker),
    'the alert audience is chosen from the thread prefix, which must be fetched'
);

// =========================================================================
// Issue #186 — the handled signal is the thread's processing status prefix,
// read from SV/MultiPrefix's link table, and no longer a reply from someone who
// happens to hold a clerk seat right now. Roster state is recomputed every scan,
// so the old rule could withdraw a pickup retroactively when the clerk who made
// it rotated out of RRD. The rule itself is exercised in ReminderDecisionTest
// and ProcessingStatusTest; this pins the vendor-coupled half.
// =========================================================================

// Reply authorship is out of the decision entirely, helper and all. A lingering
// fetchReplyAuthorIds would be dead code at best and, wired back into the facts,
// would reinstate the very bug #186 fixes.
check(
    'the reply-author machinery is gone from the worker',
    !str_contains($worker, 'fetchReplyAuthorIds')
        && !str_contains($worker, 'reply_author_ids')
        && !(bool) preg_match('/position\s*>\s*0/', $worker),
    'who replied no longer enters the decision, so the query that gathered it must go'
);
// Acceptance criterion 9: the docs have to describe the same rule the code
// implements. They read correctly today, but nothing would catch a regression that
// re-documented a clerk's reply as the handled signal — and the docs are what the
// next reader (and the next agent) works from. Criterion 9 names the class
// docblocks alongside the two prose homes, so all seven go through the same grep: the
// worker plus the four pure seams. PositionIdList belongs in that list as much as the
// others — its own class docblock names the handled signal ("the processing-status
// prefixes (cav7ERInProcessingPrefixIds) that decide whether a thread reads as
// handled"), which is exactly the clause a well-meaning reword could turn back into a
// clerk's reply. THREE of the add-on's non-test source files sit outside that list,
// not one — count the five test scripts and the generated _output/option_hint.php and
// nine PHP files are outside it, which is why the qualifier matters if you run the
// audit — and each of the three is out for the same reason rather than by oversight:
// Setup.php discusses the marker table and the retired #144 option, Cron/ScanQueue.php
// the deadline clamp, and XF/Alert/ThreadHandler.php the alert opt-out
// registration. None of the three says
// anything about what makes a thread read as handled, so none carries a clause these
// arms could bite. The list is closed only while that stays true — a file added later
// has to be held against that test, not assumed excluded because the list looks
// settled.
//
// Those docblocks do narrate the OLD rule, in the past tense ("their reply stopped
// counting", "a reply-authorship rule could withdraw a pickup"), which is why the
// patterns below all require a present-tense claim — "means", "counts as", "marks",
// "is the signal" — rather than banning the word "reply" near the word "handled".
$docsPhrasing = [];
foreach ([
    'README.md',
    'CONTEXT.md',
    'QueueReminder.php',
    'ProcessingStatus.php',
    'ReminderDecision.php',
    'EnlistmentRouting.php',
    'PositionIdList.php',
] as $docFile) {
    $doc = @file_get_contents("$root/$docFile");
    check("$docFile reads", is_string($doc) && $doc !== '');
    if (!is_string($doc)) {
        continue;
    }
    // Deliberately narrow: docs and docblocks alike DO discuss reply authorship, to
    // say it does not count and to record what the old rule cost. What must never
    // come back is prose asserting, in the present tense, that a reply is the signal.
    //
    // Tense is the axis that has to stay narrow; WORD ORDER is not, and the first
    // three arms alone missed it. "A reply marks the thread as picked up" walked past
    // `marks it`; "the reminder is suppressed by a clerk reply" and "the handled
    // signal is a reply from a seated clerk" put the reply last, where only an arm
    // reading state-then-reply can see it. Hence the verb widening and the two
    // reversed arms. All five are checked to stay silent on the past-tense narration
    // the real files carry ("their reply stopped counting", "unlike the authorship of
    // a reply", "Nothing else counts: not a reply") — which they do because a period
    // ends every window, and none of those sentences pairs a reply with a
    // present-tense claim.
    foreach ([
        '/repl(y|ied|ies)[^.]{0,80}\bmeans\b/i',
        '/repl(y|ied|ies)[^.]{0,80}(counts as|marks\b|indicates\b|is the signal|suppress)/i',
        '/(picked up|handled|actioned)[^.]{0,60}\bby\b[^.]{0,40}\brepl/i',
        '/suppress\w*[^.]{0,60}\bby\b[^.]{0,40}\brepl/i',
        '/\bsignal\b[^.]{0,40}\bis\b[^.]{0,40}\brepl/i',
    ] as $banned) {
        if (preg_match($banned, $doc, $bannedMatch)) {
            $docsPhrasing[] = "$docFile: " . trim($bannedMatch[0]);
        }
    }
}
check(
    'no doc or class docblock makes a clerk reply the handled signal (acceptance criterion 9)',
    $docsPhrasing === [],
    'the handled signal is the processing status prefix; found: ' . implode(' | ', $docsPhrasing)
);

// The status comes from SV/MultiPrefix's own link table, scoped to the threads
// this scan is about. The vendor stores EVERY prefix a thread carries there.
// Both halves anchor to fetchThreadPrefixLinks's own body: over the whole file the
// `thread_id IN` clause is satisfied by fetchAlreadyReminded's, forty-odd lines
// later, so deleting this query's WHERE clause outright — the unscoped whole-board
// read this pin's own message warns about — used to pass.
$prefixLinkBody = methodBody($worker, 'fetchThreadPrefixLinks');
check(
    'the worker reads prefix links from the SV/MultiPrefix link table, scoped to the scanned threads',
    (bool) preg_match('/FROM xf_sv_thread_prefix_link\b/', $prefixLinkBody)
        && (bool) preg_match('/FROM xf_sv_thread_prefix_link\b.*?thread_id IN/s', $prefixLinkBody),
    'an unscoped read would pull the whole board\'s prefix links'
);
// ...and it must stay scoped to the THREADS only. Narrowing the WHERE clause to the
// configured status ids as well — `AND prefix_id IN (...)` — is the one thing this
// method's docblock forbids, and it is invisible everywhere else: the option would be
// read inside the method, so the caller's argument fence never sees a change, and
// both callers of the result carry on looking healthy.
//
// The state it breaks is the one that most needs reminders. With no queue thread
// carrying a status prefix — nothing being worked, everything past the deadline — a
// filtered read returns zero rows, the zero-rows abort fires, and the run stops
// reporting the vendor's table as unpopulated. Silence, behind a log line that blames
// SV/MultiPrefix. Leaving the rows unfiltered is what keeps "no thread is being
// worked" and "the table is not populated" distinguishable, which is the whole reason
// the caller has two branches for them.
check(
    'the prefix-link read filters on the scanned threads only, never on the status prefix ids',
    $prefixLinkBody !== ''
        && !(bool) preg_match('/prefix_id\s*(?:=|!=|<>|\bNOT\s+IN\b|\bIN\b)/i', $prefixLinkBody)
        && !str_contains($prefixLinkBody, 'InProcessingPrefixIds')
        && !str_contains($prefixLinkBody, 'options()'),
    'a status filter here collapses "nothing is being worked" onto "the table is unpopulated", and the caller aborts the run on the second'
);

// A missing or unreadable link table (renamed, permissions revoked, or dropped
// under an add-on that is still active) must abort the run with a logged error.
// Without the guard, a thrown query would either kill the cron or, if swallowed
// into an empty result, read the whole queue as un-actioned and remind all of it.
check(
    'the prefix-link read is its own helper that reports failure rather than returning nothing',
    $prefixLinkBody !== ''
        && (bool) preg_match('/catch\s*\(.*?logException\(/s', $prefixLinkBody)
        && (bool) preg_match('/return null;/', $prefixLinkBody),
    'an unreadable link table must be distinguishable from a genuinely empty one'
);
// ...and [] must mean one thing only. An early `if (!$threadIds) return [];` for
// "nothing to ask" is indistinguishable from the table returning nothing, which is
// the fault the caller aborts on — it would abort reporting "no rows for the 0
// queue thread(s) scanned". remind() returns on an empty queue before it gets
// here, so the caller keeps owning the emptiness check, and the precondition is
// asserted in code with a throw rather than left to a comment.
check(
    'the prefix-link read does not answer an empty request with an empty result',
    $prefixLinkBody !== ''
        && !(bool) preg_match('/!\$threadIds\s*\)\s*\{?\s*return\s*\[\];/s', $prefixLinkBody),
    'two different questions must not share the [] answer the caller reads as a table fault'
);
check(
    'the prefix-link read asserts its non-empty precondition with a throw',
    $prefixLinkBody !== '' && (bool) preg_match('/if\s*\(\s*!\$threadIds\s*\)[^}]*?throw new/s', $prefixLinkBody),
    'an empty $threadIds would build WHERE thread_id IN (), a syntax error the broad catch reports to the admin as an unreadable table'
);
checkOrderedWithin(
    $remindBody,
    'the empty-queue return comes before the prefix-link read, so the read is never asked about nothing',
    'if (!$threads)',
    '$this->fetchThreadPrefixLinks(',
    'the helper throws on an empty request, so the caller has to hold that end up'
);
check(
    'remind() aborts on an unreadable prefix link table (logError + early return)',
    abortsWithLoggedError($remindBody, 'if ($prefixLinks === null)'),
    'without the abort, an unreadable table reads as "nothing is in processing" and reminds the whole queue'
);
// An empty result is the same failure wearing different clothes: every valid
// queue thread carries at least its type prefix in this table, so zero rows for
// a non-empty queue means the table is not populated, not that nothing is in
// processing. Abort rather than mass-remind, matching the empty-clerk guard.
check(
    'remind() aborts when a non-empty queue yields no prefix links at all',
    abortsWithLoggedError($remindBody, 'if (!$prefixLinks)'),
    'every valid queue thread carries a type prefix here, so no rows at all means the table is unpopulated'
);

// The option is a free-text list, so it has to go through the shared parser: an
// `explode(',', ...)` here would keep the blank segment a trailing comma leaves and
// the 0 that junk casts to, and PositionIdList's array_filter is the only thing
// making "a 0 can never match a prefix" true.
check(
    'the in-processing option is parsed by PositionIdList, not split by hand',
    (bool) preg_match(
        '/\$inProcessingPrefixIds\s*=\s*PositionIdList::parse\(\s*\$rawInProcessingPrefixIds\s*\);/',
        $remindBody
    )
        && !str_contains($remindBody, 'explode('),
    'a hand-rolled split lets a junk token become a phantom status id'
);
// The ACP help text is the admin's only warning about the 57/58 collision before a
// run happens, and the abort only fires after the mistake is saved. It has to name
// the ids, not gesture at "the type prefixes".
$inProcessingExplain = '';
if ($phraseXml !== false) {
    foreach ($phraseXml->phrase as $phrase) {
        if ((string) $phrase['title'] === 'option_explain.cav7ERInProcessingPrefixIds') {
            $inProcessingExplain = (string) $phrase;
        }
    }
}
check(
    'the in-processing option help text names the two type prefix ids it must never contain',
    $inProcessingExplain !== ''
        && (bool) preg_match('/\b57\b/', $inProcessingExplain)
        && (bool) preg_match('/\b58\b/', $inProcessingExplain),
    'this text is the only pre-runtime warning an admin gets; got: ' . $inProcessingExplain
);

// A blank or unparseable in-processing option resolves to no statuses, so nothing
// could ever suppress. ProcessingStatus refuses that input outright, so deleting
// this guard aborts the run at its throw rather than mass-reminding; what this
// guard adds is the admin-facing message naming the option, and a stop before any
// DB work. The pairing is stated from the seam's side in ProcessingStatus's own
// docblock.
check(
    'remind() aborts when the in-processing option parses to nothing (logError + early return)',
    abortsWithLoggedError($remindBody, 'if (!$inProcessingPrefixIds)')
        && str_contains($remindBody, 'cav7ERInProcessingPrefixIds'),
    'a blank or garbage option must be reported by name, before any query runs'
);

// The blank-option guard's mirror image, and the worse fault of the two: an
// enlistment TYPE prefix (57/58) configured into the status set. Every valid queue
// thread carries one, so one entry reads the whole queue as handled and the add-on
// goes permanently, silently dark. The option is free text with no
// validation_class, so prose in the help text is not a defence. The pure rule is
// EnlistmentRouting::typePrefixIdsAmong, exercised in EnlistmentRoutingTest.
check(
    'remind() aborts when a type prefix is configured as an in-processing status',
    abortsWithLoggedError($remindBody, 'if ($typePrefixesInStatusSet)')
        && (bool) preg_match('/\$typePrefixesInStatusSet\s*=\s*\$routing->typePrefixIdsAmong\(\s*\$inProcessingPrefixIds\s*\)/', $remindBody),
    'a 57 or 58 in the status set silences the add-on with nothing in the log to say why'
);
// The abort names both options an admin has to compare, since the collision spans
// two of them and neither is wrong on its own.
check(
    'the type-prefix collision abort names the status option and the type options',
    (bool) preg_match(
        '/cav7ERInProcessingPrefixIds.*?cav7ERStandardPrefixIds.*?cav7ERReenlistPrefixIds/s',
        ifBlock($remindBody, 'if ($typePrefixesInStatusSet)')
    ),
    'the admin has to know which two lists to compare'
);
// That collision guard intersects the status set with the UNION of both type-prefix
// sets, so ONE blank list does not make it inert: with cav7ERReenlistPrefixIds
// blank, a 57 in the status option is still caught and standard threads still
// route. Only both blank empties the union, and that is the guard's real
// precondition — so that, and only that, aborts. Naming both options there, since
// neither is wrong on its own.
$bothBlankMarker = 'if (!$standardPrefixIds && !$reenlistPrefixIds)';
$bothBlankBlock = ifBlock($remindBody, $bothBlankMarker);
check(
    'remind() aborts when BOTH type-prefix options parse to nothing',
    abortsWithLoggedError($remindBody, $bothBlankMarker)
        && str_contains($bothBlankBlock, 'cav7ERStandardPrefixIds')
        && str_contains($bothBlankBlock, 'cav7ERReenlistPrefixIds'),
    'with neither type set populated no thread can route at all and the collision guard has nothing to compare against'
);
// A SINGLE blank list must NOT abort. The fault is confined to one type: the other
// type still routes, still collides, and still has seated clerks, so aborting here
// stops the healthy type being reminded over a fault that is not about it — the
// same trade the clerk seats already resolve from the union to avoid. Warn by name
// (the per-thread unrecognized line otherwise blames each thread's own prefix) and
// carry on. The premise — one blank list still routes and still catches a collision
// on the other type's ids — is exercised for real in EnlistmentRoutingTest.
$standardBlankWarning = ifBlock($remindBody, 'if (!$standardPrefixIds && $reenlistPrefixIds)');
$reenlistBlankWarning = ifBlock($remindBody, 'if (!$reenlistPrefixIds && $standardPrefixIds)');
check(
    'a blank standard type-prefix option warns and lets the run continue',
    $standardBlankWarning !== ''
        && str_contains($standardBlankWarning, 'logError(')
        && str_contains($standardBlankWarning, 'cav7ERStandardPrefixIds')
        && !(bool) preg_match('/\breturn;/', $standardBlankWarning),
    'aborting on one empty type set would silence the other type, which still routes and still has holders'
);
check(
    'a blank re-enlistment type-prefix option warns and lets the run continue',
    $reenlistBlankWarning !== ''
        && str_contains($reenlistBlankWarning, 'logError(')
        && str_contains($reenlistBlankWarning, 'cav7ERReenlistPrefixIds')
        && !(bool) preg_match('/\breturn;/', $reenlistBlankWarning),
    'aborting on one empty type set would silence the other type, which still routes and still has holders'
);
// ...and each names its OWN option, or an admin fixing one textbox reads the line
// for the other.
check(
    'each blank type-prefix warning names only its own option',
    $standardBlankWarning !== ''
        && $reenlistBlankWarning !== ''
        && !str_contains($standardBlankWarning, 'cav7ERReenlistPrefixIds')
        && !str_contains($reenlistBlankWarning, 'cav7ERStandardPrefixIds'),
    'the warning has to point at the one textbox that is blank'
);
// The full inventory of aborts in remind(): every guard that can end the run before
// the remind decision. Two pins read it — the log-only checks have to precede ALL of
// them (here and at the overlap warning below), and each of them has to precede the
// decision itself (further down). It is hand-kept, which is the thing to remember
// when adding an abort: a guard left out of this list is held by neither pin.
$abortGuardMarkers = [
    'if (!$nodeId)',
    'if (!$botUserId)',
    'if (!$inProcessingPrefixIds)',
    'if (!$standardPrefixIds && !$reenlistPrefixIds)',
    'if ($typePrefixesInStatusSet)',
    "if (!\\XF::isAddOnActive('SV/MultiPrefix'",
    'if (!$clerkUserIds)',
    'if ($prefixLinks === null)',
    'if (!$prefixLinks)',
];

// Log-only, so both warnings belong ABOVE the aborts, for the same reason the
// overlap warning does: an admin carrying a blank type list AND one of the config
// faults below hears about both from one run.
//
// Compared against EVERY abort, by taking the minimum offset over the inventory
// above, rather than against one guard named as "the first abort". Named, the pin
// held that guard alone: with `if (!$nodeId)` as the comparison point, moving the
// blank-status abort or the bot abort back above these warnings left the whole suite
// green, and the blank-status arrangement is the exact defect this branch fixed — an
// admin holding a blank status option AND a blank type list hears about one fault and
// waits an hour for the next. What the minimum pins is what these comments have
// always claimed: every log-only check, then every abort. It also stops depending on
// which abort happens to come first, so reordering the guards among themselves cannot
// reopen the gap.
foreach ([
    'if (!$standardPrefixIds && $reenlistPrefixIds)',
    'if (!$reenlistPrefixIds && $standardPrefixIds)',
] as $blankWarningMarker) {
    checkOrderedBeforeAll(
        $remindBody,
        "the blank-type-list warning `$blankWarningMarker` comes before every abort that would end the run",
        $blankWarningMarker,
        $abortGuardMarkers,
        'a log-only check placed below an abort is never reached on a board that has both faults'
    );
}

// SV/MultiPrefix DISABLED rather than uninstalled is the state neither link-table
// guard can see. XenForo checks `require` on install and upgrade only, never at
// runtime, and does not cascade a disable to dependents, so the table and all its
// stale rows stay in place while the vendor stops maintaining them: the read
// succeeds, both guards pass, and every application picked up since the disable
// reads un-actioned and gets the note plus the clerk alert.
check(
    'remind() aborts when SV/MultiPrefix is not active (disabled, not just uninstalled)',
    abortsWithLoggedError($remindBody, "if (!\\XF::isAddOnActive('SV/MultiPrefix'"),
    'a disabled vendor add-on leaves a stale table that reads as "nothing is handled"'
);
// Activeness only, with no version floor. The floor belongs in addon.json's
// `require`, which is where XenForo enforces it (on install and upgrade); checked
// again here it would also fire on a board running a slightly older but perfectly
// healthy SV/MultiPrefix whose link table is fine, silencing this add-on over a
// fault that isn't the one the guard is for. It also carried a DB read per run to
// fetch the number back out of the manifest.
check(
    'the vendor-active check takes the add-on id alone, with no runtime version floor',
    (bool) preg_match("/isAddOnActive\(\s*'SV\/MultiPrefix'\s*\)/", $remindBody)
        && !str_contains($worker, 'multiPrefixFloor')
        && is_int($multiPrefixFloorLiteral)
        && !str_contains($worker, (string) $multiPrefixFloorLiteral),
    'the install-time floor lives in addon.json; a second runtime copy silences the add-on on a healthy older vendor release'
);

// The overlap warning only logs, so it belongs ABOVE the aborts: below them, an
// admin carrying both an overlap and one of the config faults fixes one, waits an
// hour, and only then hears about the other. One run, both reports.
// Both the resolve and the branch that logs it, since moving either one alone
// below the aborts is enough to lose the report. Compared against every abort in the
// inventory, for the reason given at the blank-type-list warnings above.
foreach (['$routing->overlappingPrefixIds()', 'if ($overlapPrefixIds)'] as $overlapMarker) {
    checkOrderedBeforeAll(
        $remindBody,
        "the overlap warning's `$overlapMarker` comes before every abort that would end the run",
        $overlapMarker,
        $abortGuardMarkers,
        'a log-only check placed below an abort is never reached on a board that has both faults'
    );
}

// EVERY abort guard has to run before the decision, not merely exist in the file.
// A guard whose whole `if` block is moved verbatim below the remind loop still
// logs "skipping this run" — after the entire queue has been reminded. Offsets are
// taken inside remind()'s own body, so text in a method declared above remind()
// does not read as "before the decision" either. Same inventory the log-only
// ordering pins above read.
foreach ($abortGuardMarkers as $marker) {
    checkOrderedWithin(
        $remindBody,
        "the abort guard `$marker` runs before the remind decision",
        $marker,
        'selectThreadsToRemind',
        'a guard downstream of the decision reminds the whole queue and then logs "skipping this run"'
    );
}

// --- the decision routes through the pure units ----------------------------
check(
    'the worker delegates the decision to ReminderDecision::selectThreadsToRemind',
    str_contains($worker, 'ReminderDecision::selectThreadsToRemind'),
    'the rule is extracted so it can be unit-tested without XenForo'
);
check(
    'the ProcessingStatus seam exists and has a pure test',
    is_file("$root/ProcessingStatus.php") && is_file("$root/tests/ProcessingStatusTest.php"),
    'the type-prefix trap must be covered for real in plain PHP, like the other seams'
);
// THE TRAP. The in-processing fact must come from membership in the configured
// set, which is what ProcessingStatus applies. A worker that instead tested
// "this thread has a row in the link table" would suppress every reminder
// forever and never log a thing, because every valid queue thread carries its
// type prefix there.
// The call must be what PRODUCES $inProcessing, not merely something the file
// contains. A worker that called the seam and threw the result away, then built
// its own thread-keyed map from the same rows, satisfies a pin that only looks
// for the call text — and that map is precisely "does this thread have any linked
// prefix", the regression the seam exists to prevent.
check(
    'the in-processing map is ASSIGNED from ProcessingStatus against the configured set',
    (bool) preg_match(
        '/\$inProcessing\s*=\s*ProcessingStatus::inProcessingThreadIds\(\s*\$prefixLinks\s*,\s*\$inProcessingPrefixIds\s*\)\s*;/',
        $worker
    ),
    'testing mere presence in the link table would silently disable the add-on'
);
// And the raw rows must have no second consumer. $prefixLinks is the unfiltered
// link table — every valid queue thread has rows in it — so any other use that
// keys by thread is the trap wearing a different name. Four uses, all named, each
// pattern matched against a WHOLE line (see unexpectedUseLines) so a second
// statement sharing an allowed line cannot ride along invisibly.
$seamCallLine = '/^\$inProcessing\s*=\s*ProcessingStatus::inProcessingThreadIds\(\s*\$prefixLinks\s*,\s*\$inProcessingPrefixIds\s*\);$/';
$prefixLinkUses = variableUseLines($remindBody, '$prefixLinks');
$unexpectedPrefixLinkUses = unexpectedUseLines($prefixLinkUses, [
    '/^\$prefixLinks\s*=\s*\$this->fetchThreadPrefixLinks\(\s*\$threadIds\s*\);$/',
    '/^if\s*\(\s*\$prefixLinks\s*===\s*null\s*\)$/',
    '/^if\s*\(\s*!\$prefixLinks\s*\)$/',
    $seamCallLine,
]);
check(
    'the raw prefix-link rows are touched only by their two guards and the ProcessingStatus call',
    count($prefixLinkUses) === 4 && $unexpectedPrefixLinkUses === [],
    'unexpected: ' . implode(' | ', $unexpectedPrefixLinkUses)
        . ' (all ' . count($prefixLinkUses) . ' use(s): ' . implode(' | ', $prefixLinkUses) . ')'
);
// The same technique on the map itself, because fencing the raw rows is only half
// the fence. A rebuild of $inProcessing from $prefixByThread never touches
// $prefixLinks at all, so the four-uses count above stays at 4 while every prefixed
// queue thread reads as handled — the whole queue goes permanently, silently dark,
// exactly what #186 fixes. Two uses only: the seam assignment, and the isset in the
// fact array. Anything else — a reassignment, a rebuild loop, a `&& $threadId < 0`
// bolted onto the fact — is an unexpected line here.
$inProcessingUses = variableUseLines($remindBody, '$inProcessing');
$unexpectedInProcessingUses = unexpectedUseLines($inProcessingUses, [
    $seamCallLine,
    '/^[\'"]in_processing[\'"]\s*=>\s*isset\(\s*\$inProcessing\[\s*\$threadId\s*\]\s*\),$/',
]);
check(
    'the in-processing map is assigned once from the seam and read once as the fact, and nothing else',
    count($inProcessingUses) === 2 && $unexpectedInProcessingUses === [],
    'unexpected: ' . implode(' | ', $unexpectedInProcessingUses)
        . ' (all ' . count($inProcessingUses) . ' use(s): ' . implode(' | ', $inProcessingUses) . ')'
);
// The two links between that map and the remind loop — $facts going in, $toRemind
// coming out — are the rest of the plumbing, and they need the same fence. The
// decision call is pinned as an ASSIGNMENT with its exact arguments, which is what
// makes it a pin rather than a restatement of the call: it is the only thing standing
// between the board and a silent mass remind if the `* 3600` were changed or dropped.
// The option is in HOURS and the seam takes SECONDS, so a bare $deadlineHours makes
// the deadline 24 seconds. Every open queue thread is then past it, the whole queue is
// noted and alerted in one run, and nothing errors and nothing is logged — the cron
// reports a clean run. `* 60`, or a 3600 quietly turned into 360, is the same fault
// wearing a smaller number.
//
// The other mutations the shape of this pin rules out are worth naming for what they
// are NOT: handing the decision the raw $threads rows instead of the built facts
// throws rather than mis-decides, because selectThreadsToRemind reads
// $thread['op_timestamp'] unguarded (only in_processing and already_reminded default
// with `??`) and XenForo's error handler turns that warning into an ErrorException,
// outside any try. Same for a call whose result is thrown away: the foreach below
// then reads an undefined $toRemind. Those fail loudly in the error log, which is the
// one class of regression this suite does not need to pin.
check(
    'the remind list is ASSIGNED from ReminderDecision::selectThreadsToRemind over the built facts, with the deadline converted to seconds',
    (bool) preg_match(
        '/\$toRemind\s*=\s*ReminderDecision::selectThreadsToRemind\(\s*\\\\XF::\$time,\s*\$deadlineHours\s*\*\s*3600,\s*\$facts\s*\);/',
        $remindBody
    ),
    'the option is in hours and the seam takes seconds: without the * 3600 the deadline is 24 seconds, the whole queue is reminded in one run, and the cron reports success'
);
// $facts: initialised, appended once per thread, handed to the decision. Nothing
// else. A post-loop rewrite of one fact is the sharpest mutation this fences off —
// `$facts[$i]['in_processing'] = false` is issue #186's own bug restored, and it
// leaves $inProcessing's use count at 2 so the fence above never notices; `= true`
// reads the whole queue as handled (permanent silence); `['already_reminded'] =
// false` uncaps the once-only rule and re-notes the applicant's thread hourly.
$factsUses = variableUseLines($remindBody, '$facts');
$unexpectedFactsUses = unexpectedUseLines($factsUses, [
    '/^\$facts\s*=\s*\[\];$/',
    '/^\$facts\[\]\s*=\s*\[$/',
    '/^\$facts$/',
]);
check(
    'the facts are built once and handed straight to the decision, with nothing rewriting them in between',
    count($factsUses) === 3 && $unexpectedFactsUses === [],
    'unexpected: ' . implode(' | ', $unexpectedFactsUses)
        . ' (all ' . count($factsUses) . ' use(s): ' . implode(' | ', $factsUses) . ')'
);
// $toRemind: assigned from the decision, iterated once. Nothing else. Looping over
// $threadIds instead — or re-assigning $toRemind = $threadIds after the call — notes
// and alerts every open queue thread every hour, deadline, status and marker all
// ignored, and every pin above stays green.
$toRemindUses = variableUseLines($remindBody, '$toRemind');
$unexpectedToRemindUses = unexpectedUseLines($toRemindUses, [
    '/^\$toRemind\s*=\s*ReminderDecision::selectThreadsToRemind\($/',
    '/^foreach\s*\(\$toRemind as \$threadId\)$/',
]);
check(
    'the remind loop iterates the decision\'s own answer and nothing else',
    count($toRemindUses) === 2 && $unexpectedToRemindUses === [],
    'unexpected: ' . implode(' | ', $unexpectedToRemindUses)
        . ' (all ' . count($toRemindUses) . ' use(s): ' . implode(' | ', $toRemindUses) . ')'
);
// Acceptance criterion 4: reply authorship no longer influences the decision. The
// name-based checks above ("fetchReplyAuthorIds is gone") are walked past by any
// renamed helper, so pin the fact array itself: exactly the four keys
// ReminderDecision documents, no fifth one carrying who replied back in. The
// matching parameter list on shouldRemind is pinned in ReminderDecisionTest.
// The closing bracket is matched as `\n\s*];` rather than a literal twelve-space
// indent, so re-indenting remind() cannot silently empty $factsLiteral and pass
// this on a comparison of nothing. It fails by name when the marker is not there.
$factsLiteral = '';
if (preg_match('/\$facts\[\]\s*=\s*\[(.*?)\n\s*\];/s', $remindBody, $factsMatch)) {
    $factsLiteral = $factsMatch[1];
}
preg_match_all("/'([a-z_]+)'\s*=>/", $factsLiteral, $factKeyMatches);
$factKeys = $factKeyMatches[1] ?? [];
sort($factKeys);
check(
    'the fact array handed to the decision carries exactly the four documented keys',
    $factsLiteral !== ''
        && $factKeys === ['already_reminded', 'in_processing', 'op_timestamp', 'thread_id'],
    $factsLiteral === ''
        ? 'the `$facts[] = [ ... ];` literal was not found in remind()'
        : 'got: ' . implode(', ', $factKeys)
);

// --- the bot posts the note the SteamChecker way, note included ------------
check(
    'the note is posted by saving a Post entity directly (SteamChecker approach)',
    (bool) preg_match("/em\(\)->create\(\s*'XF:Post'\s*\)/", $worker),
    'XF 2.3 removed the post Creator service; the direct save is the current path'
);
check(
    'the freshly-created-OP first_post_id correction is carried over',
    (bool) preg_match('/UPDATE xf_thread SET first_post_id/', $worker),
    'without it the bot note can become first_post_id and break the hover card'
);
// FIX 2: the note is saved before the first_post_id correction runs, so that
// correction must be best-effort — a DB blip on it must never throw back out and
// stop recordReminder, or the same applicant-visible note re-posts every run.
check(
    'the first_post_id correction is best-effort so a saved post always returns true',
    (bool) preg_match(
        '/\$post->save\(\);.*?try\s*\{.*?UPDATE xf_thread SET first_post_id.*?catch\s*\(.*?\$e\s*\).*?logException\(\s*\$e,\s*false.*?return true;/s',
        $worker
    ),
    'a saved note followed by a throwing correction would never be recorded, and would re-post next run'
);
// Issue #76 slots the clerk alert into #75's once-guard: a saved note alerts the
// clerks, then records the marker, all in the one success block. Record still
// happens iff the post saved, so the remind-once guarantee holds and the alerts
// go out in the same run as the note.
check(
    'a saved note alerts the clerks then records the marker, all inside the once-guard',
    (bool) preg_match('/if\s*\(\s*\$this->postReminderNote\([^)]*\)\s*\)\s*\{\s*(?:\/\/[^\n]*\n\s*)*\$this->alertClerks\([^)]*\);\s*\$this->recordReminder\(/s', $worker),
    'the alert must fire in the same success block as the note and before the marker, so it fires once per thread'
);
check(
    'the visible note is the applicant-safe reminder phrase',
    in_array('cav7_er_reminder_note', $phraseTitles, true)
        && str_contains($worker, "phrase('cav7_er_reminder_note')"),
    'the in-thread note must stay neutral; the pointed wording is the clerk alert (issue #76)'
);

// --- the marker is read and written ----------------------------------------
check(
    'the already-reminded flag is read from the marker table',
    (bool) preg_match('/SELECT thread_id\s+FROM xf_cav7_enlistment_reminder/', $worker),
    'the once-only guard depends on reading the marker'
);
check(
    'a reminded thread is recorded in the marker table',
    (bool) preg_match('/INSERT( IGNORE)? INTO xf_cav7_enlistment_reminder/', $worker),
    'without the write, the same thread would be reminded every hour'
);

// Issue #77 — the marker write is the last step in the once-guard, so a DB blip
// on it means the note already posted and the clerk-alert step already ran. It
// must log as a bookkeeping failure that re-reminds next run, distinct from the
// outer "reminder failed", so a debugger is not sent chasing a note that in fact
// posted. The full rationale lives in recordReminder's docblock.
$recordReminderBody = methodBody($worker, 'recordReminder');
// "never fatal" has to pin two things a bare "has a catch" check misses: the
// caught $e must be forwarded to logException (not a fresh exception that drops
// the trace), and the catch must not rethrow — either would bubble to the outer
// catch and be mis-logged as a reminder failure, reverting #77 while still
// looking like a catch. The forwarding pattern matches the first_post_id check.
check(
    'the marker write is best-effort: recordReminder catches, forwards $e non-fatally, and never rethrows',
    $recordReminderBody !== ''
        && (bool) preg_match('/catch\s*\(.*?logException\(\s*\$e,\s*false/s', $recordReminderBody)
        && !str_contains($recordReminderBody, 'throw'),
    'a rethrow, or logging a fresh exception instead of $e, would bubble to the outer catch and be mis-logged as a reminder failure though the note already posted'
);
// Distinct message: the negative (not "reminder failed") is the load-bearing
// #77 assertion; "posted" is a light positive so a present-but-empty message
// can't pass vacuously. Both scope to recordReminder's own body via methodBody.
check(
    'the marker-write failure logs distinctly from a note-post failure (not "reminder failed")',
    $recordReminderBody !== ''
        && !str_contains($recordReminderBody, 'reminder failed')
        && str_contains($recordReminderBody, 'posted'),
    'the marker-write log line must not read "reminder failed"; it says the note posted and the thread re-reminds next run'
);

// =========================================================================
// Issue #82 — a persistent marker-WRITE failure (while the READ still works)
// must not re-post the note and re-alert the clerks every hour. The marker table
// is both the "already reminded" source of truth AND the thing that can fail to
// be written, so the fix derives "already reminded" from the bot's own note in
// xf_post (a different table, still writable in that failure mode). The note
// gates the WHOLE reminder, capping it at one note and one alert per thread, and
// a noted-but-unmarked thread re-attempts the marker write to self-heal on
// recovery. Rationale lives in fetchAlreadyNoted's docblock.
// =========================================================================

// The backstop reads the bot's OWN reminder note back out of xf_post, matched on
// BOTH the bot as author (user_id) AND the reminder phrase as the message,
// visible only. The author gate defeats a member quoting or copy-pasting the
// note; the phrase gate defeats the same S6 bot's SteamChecker VAC reply in the
// same thread. Anchor to fetchAlreadyNoted's own body so the multi-clause regex
// can't be satisfied by another xf_post query elsewhere in the worker:
// postReminderNote runs its own SELECT post_id FROM xf_post for the first_post_id
// correction, and a clause-by-clause match is happy to straddle the two.
$fetchNotedBody = methodBody($worker, 'fetchAlreadyNoted');
check(
    'a note-presence backstop lives in its own helper (fetchAlreadyNoted)',
    $fetchNotedBody !== '',
    'the #82 fallback belongs in its own helper mirroring fetchAlreadyReminded'
);
check(
    'the backstop matches the bot-authored, visible reminder note on user_id AND message',
    (bool) preg_match(
        '/FROM xf_post\b.*?user_id\s*=\s*\?.*?message_state\s*=\s*\?.*?message\s*=\s*\?/s',
        $fetchNotedBody
    )
        && str_contains($fetchNotedBody, "phrase('cav7_er_reminder_note')"),
    'without matching on both the bot as author and the reminder phrase, a quote/copy-paste or the bot VAC reply would fake the "already reminded" signal'
);

// The note feeds the SAME already_reminded fact the decision reads, so a present
// note skips the whole reminder — note AND alert — not just the note.
check(
    'the already-reminded fact is the marker OR the note',
    (bool) preg_match(
        '/[\'"]already_reminded[\'"]\s*=>\s*isset\(\$alreadyReminded\[[^\]]+\]\)\s*\|\|\s*isset\(\$alreadyNoted\[[^\]]+\]\)/',
        $worker
    ),
    'the note-presence signal must OR into the same fact ReminderDecision reads, or it would gate nothing'
);

// Self-heal: a thread the bot has already noted but that is missing from the
// marker table had its marker write fail earlier; re-attempt the write so a
// recovered DB backfills it and the thread rejoins the marker fast-path.
check(
    'a noted-but-unmarked thread re-attempts the marker write (self-heal)',
    (bool) preg_match(
        '/isset\(\$alreadyNoted\[[^\]]+\]\)\s*&&\s*!isset\(\$alreadyReminded\[[^\]]+\]\)[^}]*?\$this->recordReminder\(/s',
        $worker
    ),
    'without the self-heal, a recovered DB never backfills the marker and the thread leans on the fuzzier note-match forever'
);

// =========================================================================
// Issue #76 — direct clerk alerts. IN ADDITION to the applicant-safe note, a
// reminded thread alerts every current processing clerk with a direct XenForo
// alert (content type thread, custom action enlistment_reminder), inside #75's
// marker once-guard so the note and the alerts fire together and only once. The
// pointed "pick this up" wording lives in the alert, which only clerks see.
// Mechanism and rationale: docs/adr/0001-alert-not-mention.md.
// =========================================================================

// Issue #144: the alert no longer targets the global clerk set. It targets the
// per-type set the router resolved for this thread's prefix ($alertUserIds). The
// union is resolved once per run into $clerkUserIds purely to answer "is any seat
// held at all?" for the empty-clerk guard; it only ever reaches an alert as
// route()'s fail-safe audience for a prefix listed under both types.
check(
    'the clerk alert targets the per-type resolved set, not the global clerk set',
    (bool) preg_match('/alertClerks\(\s*\$threadId\s*,\s*\$botUserId\s*,\s*\$alertUserIds\s*\)/', $worker)
        && !(bool) preg_match('/alertClerks\(\s*\$threadId\s*,\s*\$botUserId\s*,\s*\$clerkUserIds\s*\)/', $worker),
    'the alert must reach only the clerks who own this thread\'s enlistment type'
);
// ...and the pin above keys on the NAME handed to alertClerks, so it says nothing
// about what fills it. Feed $alertUserIds from allClerkPositionIds() and every
// clerk is alerted for both types while that pin stays green — acceptance criterion
// 7's whole point, walked past. The audience has to come from the route's own
// position ids.
check(
    "the per-type audience is resolved from the route's own position ids",
    (bool) preg_match(
        '/\$alertUserIds\s*=\s*\$this->resolveClerkUserIds\(\s*\$route\[[\'"]position_ids[\'"]\]\s*\);/',
        $remindBody
    ),
    'resolving the audience from the union alerts every clerk for both types, which criterion 7 forbids'
);

// The alert goes through UserAlertRepository::alert (not insertAlert), so a clerk
// who muted the type via their alert preferences is skipped — alert() gates on
// doesReceiveAlert, insertAlert does not.
check(
    'the alert is sent via the opt-out-respecting UserAlertRepository::alert path',
    str_contains($worker, 'UserAlertRepository') && str_contains($worker, '->alert('),
    'insertAlert bypasses the per-member opt-out; alert() honours doesReceiveAlert'
);
check(
    "the alert uses content type 'thread' and custom action 'enlistment_reminder'",
    str_contains($worker, "'thread'") && str_contains($worker, "'enlistment_reminder'"),
    'the core thread handler then renders public:alert_thread_enlistment_reminder and handles viewability/click-through'
);
check(
    'the S6 bot user id is the alert sender',
    (bool) preg_match('/->alert\(\s*\$\w+\s*,\s*\$botUserId\s*,/s', $worker),
    'the reminder is attributed to the configured bot, matching the visible note'
);
check(
    'the alert is tied to the add-on via dependsOnAddOnId so uninstall clears outstanding ones',
    (bool) preg_match("/'dependsOnAddOnId'\s*=>\s*'Cav7\/EnlistmentReminder'/", $worker),
    'without dependsOnAddOnId an outstanding alert survives uninstall'
);
// Best-effort send: the note has already posted by the time alertClerks runs, so
// a repository blip must be logged, never thrown, or the marker is skipped and
// the applicant-visible note re-posts next run. Same forwarding-and-no-rethrow
// shape the marker-write check pins below, for the same reason. Anchor to
// alertClerks's own body (see methodBody) so a catch elsewhere can't satisfy it.
$alertClerksBody = methodBody($worker, 'alertClerks');
check(
    'the clerk alert send is best-effort: alertClerks catches, forwards $e non-fatally, and never rethrows',
    $alertClerksBody !== ''
        && (bool) preg_match('/catch\s*\(.*?logException\(\s*\$e,\s*false/s', $alertClerksBody)
        && !str_contains($alertClerksBody, 'throw'),
    'a throwing or rethrowing alert send after a posted note would block recordReminder and re-post the note'
);
// No clerk is @-mentioned in the post body — the split of audiences is the whole
// point of ADR-0001. The neutral note phrase must carry no @mention markup.
$reminderNoteText = '';
if ($phraseXml !== false) {
    foreach ($phraseXml->phrase as $phrase) {
        if ((string) $phrase['title'] === 'cav7_er_reminder_note') {
            $reminderNoteText = (string) $phrase;
        }
    }
}
check(
    'the visible note @-mentions no one (clerks are alerted directly, not tagged)',
    $reminderNoteText !== '' && !str_contains($reminderNoteText, '@') && !str_contains($worker, "'@'"),
    'tagging clerks in the applicant thread is exactly what ADR-0001 rejects'
);

// --- the alert wording ships as the public template, per ADR-0001 -----------
$templatesXml = @simplexml_load_file("$root/_data/templates.xml");
check('_data/templates.xml could be read', $templatesXml !== false);

$templateTitles = [];
$templateTypeByTitle = [];
$alertTemplateBody = '';
if ($templatesXml !== false) {
    foreach ($templatesXml->template as $tpl) {
        $title = (string) $tpl['title'];
        $templateTitles[] = $title;
        $templateTypeByTitle[$title] = (string) $tpl['type'];
        if ($title === 'alert_thread_enlistment_reminder') {
            $alertTemplateBody = (string) $tpl;
        }
    }
}
check(
    'the public template alert_thread_enlistment_reminder is declared',
    in_array('alert_thread_enlistment_reminder', $templateTitles, true)
        && ($templateTypeByTitle['alert_thread_enlistment_reminder'] ?? '') === 'public',
    'getTemplateName renders public:alert_thread_enlistment_reminder for content type thread + action enlistment_reminder'
);
check(
    'the alert template clicks through to the thread and renders the staff-facing phrase',
    str_contains($alertTemplateBody, "link('threads'")
        && str_contains($alertTemplateBody, 'cav7_er_alert_thread_awaiting_pickup'),
    'a clerk must reach the application in one click; the pointed wording is a phrase, not inline text'
);
// Issue #144: the alert renders the thread's prefix the stock XenForo way, so a
// Senior/Lead clerk who receives both types can tell a re-enlistment from a
// standard enlistment straight from the bell. prefix('thread', $content) is the
// same idiom stock alert_* templates use.
check(
    'the alert template renders the thread prefix badge (stock prefix() idiom)',
    (bool) preg_match("/prefix\(\s*'thread'\s*,\s*\\\$content\s*\)/", $alertTemplateBody),
    'without the prefix badge the two types are indistinguishable in the notification'
);
check(
    'the _output template file ships under the public style folder',
    is_file("$root/_output/templates/public/alert_thread_enlistment_reminder.html"),
    'the _output side must ship the template or check-data-consistency fails on the templates count'
);
check(
    '_output has one template file per _data template',
    count(outputTemplateItems($root)) === ($templatesXml !== false ? count($templatesXml->template) : -1),
    'a _data/templates.xml entry with no _output counterpart (or vice versa) must fail here, mirroring the other item types'
);

// --- the opt-out entry lets members mute the alert type ---------------------
// getOptOutsMap builds each toggle from the handler's getOptOutActions plus the
// alert_opt_out.{type}_{action} phrase, so registering the opt-out means both:
// extend the core thread alert handler to list the action, and ship the label.
$classExtXml = @simplexml_load_file("$root/_data/class_extensions.xml");
check('_data/class_extensions.xml could be read', $classExtXml !== false);

$extensionsByFrom = [];
if ($classExtXml !== false) {
    foreach ($classExtXml->extension as $ext) {
        $extensionsByFrom[(string) $ext['from_class']] = [
            'to'     => (string) $ext['to_class'],
            'active' => (string) $ext['active'],
        ];
    }
}
check(
    'the core thread alert handler XF\\Alert\\ThreadHandler is extended and active',
    isset($extensionsByFrom['XF\Alert\ThreadHandler'])
        && $extensionsByFrom['XF\Alert\ThreadHandler']['to'] === 'Cav7\EnlistmentReminder\XF\Alert\ThreadHandler'
        && $extensionsByFrom['XF\Alert\ThreadHandler']['active'] === '1',
    'the opt-out action is registered by extending the thread handler; without it the toggle never renders'
);
check(
    '_output has one class_extensions file per _data extension',
    count(outputItems($root, 'class_extensions')) === ($classExtXml !== false ? count($classExtXml->extension) : -1)
);

$handlerSrc = @file_get_contents("$root/XF/Alert/ThreadHandler.php") ?: '';
check(
    'the extended handler MERGES enlistment_reminder into the parent getOptOutActions (never replaces it)',
    (bool) preg_match(
        '/function\s+getOptOutActions\b.*?return\s+array_merge\(\s*parent::getOptOutActions\(\)\s*,\s*\[[^\]]*\'enlistment_reminder\'/s',
        $handlerSrc
    ),
    'returning [\'enlistment_reminder\'] alone would silently drop every OTHER core thread opt-out (watched-reply, quote, ...); the action must be array_merge-d onto the parent list'
);
check(
    'the opt-out label phrase alert_opt_out.thread_enlistment_reminder is declared',
    in_array('alert_opt_out.thread_enlistment_reminder', $phraseTitles, true),
    'getOptOutsMap labels the toggle with the alert_opt_out.{type}_{action} phrase'
);
check(
    'the staff-facing alert body phrase cav7_er_alert_thread_awaiting_pickup is declared',
    in_array('cav7_er_alert_thread_awaiting_pickup', $phraseTitles, true),
    'the pointed wording only clerks see lives in this phrase'
);

// =========================================================================
// Issue #144 — route the un-actioned alert by enlistment type. The pure rule is
// exercised in EnlistmentRoutingTest; this pins the vendor-coupled wiring: the
// worker builds the router from the four options, the empty-clerk guard resolves
// the union, the per-type set is alerted, an unrecognized thread is skipped with
// one breadcrumb, an overlap config is warned, and the Setup upgrade step retires
// the old option.
// =========================================================================

// The pure routing seam exists and is a plain-PHP twin of the other seams.
check(
    'the EnlistmentRouting seam file exists',
    is_file("$root/EnlistmentRouting.php"),
    'the type-routing rule is extracted so it can be unit-tested without XenForo'
);
check(
    'a pure EnlistmentRoutingTest exercises the seam',
    is_file("$root/tests/EnlistmentRoutingTest.php"),
    'the routing branches must be covered for real in plain PHP, like ReminderDecisionTest'
);

// The worker builds the router from the four parsed options and delegates the
// type decision to it, rather than re-implementing the prefix-to-clerks mapping.
check(
    'the worker constructs EnlistmentRouting and routes per thread',
    str_contains($worker, 'new EnlistmentRouting(')
        && (bool) preg_match('/->route\(\s*\$prefixByThread\[/', $worker),
    'the prefix-to-clerks decision must go through the pure seam'
);
// ...and the pin above says nothing about WHICH option feeds which parameter. The
// four arguments are named, which closes the positional transposition — all four
// parameters are array and the pairs interleave, so swapping two of them by
// position would type-check and construct. Named arguments do NOT close handing the
// wrong variable to the right name, and nothing else in the run catches it. Three of
// the four mutations below DO reach the error log — the trap is that the line names an
// option that is correctly configured, so an admin following it edits a healthy
// textbox — and the fourth is silent outright:
//
//   standardPrefixIds: $reenlistPrefixIds     — both prefix lists then hold 58, so
//                                               overlappingPrefixIds() returns [58]
//                                               and the run reports an overlap
//                                               against cav7ERStandardPrefixIds and
//                                               cav7ERReenlistPrefixIds, neither of
//                                               which is wrong. 57 threads take the
//                                               unrecognized skip (its own line,
//                                               blaming the thread's own prefix); 58
//                                               threads route BOTH and alert the union
//   reenlistPrefixIds: $standardPrefixIds     — the mirror image, overlap [57]
//   standardPositionIds: parse($rawStandardPrefixIds)
//                                             — the standard seats become [57], a
//                                               prefix id used as a position id, so no
//                                               holder resolves and the empty-audience
//                                               skip fires hourly naming
//                                               cav7ERStandardClerkPositionIds, which
//                                               is also correct
//   BOTH prefix bindings swapped, positions   — rows 1 and 2 applied together rather
//   left alone                                  than a fourth case. 57 then alerts the
//                                               re-enlistment clerks and 58 the
//                                               standard ones, every alert reaching
//                                               the wrong seats — and the two lists
//                                               are disjoint again, so the overlap is
//                                               empty, no skip fires and NOTHING is
//                                               logged. The one genuinely silent
//                                               mutation of the four, and the same
//                                               routing a positional swap of the two
//                                               prefix arguments produces
//
// Rotating both PAIRS — prefixes and positions together — is deliberately NOT on that
// list. It preserves the prefix-to-positions mapping: route(58) still returns the
// re-enlistment seats and route(57) the standard ones, the overlap stays empty and
// typePrefixIdsAmong is unchanged because it merges both lists, so nobody is alerted
// wrongly. All it corrupts is the `%s` type string in the no-seat skip's log line and
// the option that line tells an admin to check — and that line only fires on a board
// already short of a seated clerk.
//
// So bind each parameter to its own variable, one check each, inside the
// construction statement itself.
$routingConstruction = '';
if (preg_match('/new EnlistmentRouting\((.*?)\n\s*\);/s', $remindBody, $routingConstructionMatch)) {
    $routingConstruction = $routingConstructionMatch[1];
}
check(
    "the EnlistmentRouting construction statement could be sliced out of remind()",
    $routingConstruction !== '',
    'without the argument list there is nothing for the four binding pins to read'
);
foreach ([
    'standardPrefixIds'   => '$standardPrefixIds',
    'standardPositionIds' => 'PositionIdList::parse($rawStandardPositionIds)',
    'reenlistPrefixIds'   => '$reenlistPrefixIds',
    'reenlistPositionIds' => 'PositionIdList::parse($rawReenlistPositionIds)',
] as $parameter => $wantArgument) {
    // The parameter's own line, found by its `name:` label so the check reports the
    // one binding that drifted rather than the whole statement. Exactly one line may
    // carry each label: a duplicate named argument is a fatal error in PHP, but a
    // renamed-away label would otherwise read as zero and pass a looser count test.
    $bindingLines = [];
    foreach (explode("\n", $routingConstruction) as $line) {
        $line = trim($line);
        if (preg_match('/^' . preg_quote($parameter, '/') . '\s*:/', $line)) {
            $bindingLines[] = $line;
        }
    }
    check(
        "the $parameter argument is bound to $wantArgument",
        count($bindingLines) === 1
            && (bool) preg_match(
                '/^' . preg_quote($parameter, '/') . '\s*:\s*' . preg_quote($wantArgument, '/') . '\s*,?$/',
                $bindingLines[0]
            ),
        'handing the wrong list to the right name routes a whole enlistment type to the wrong clerks, or to nobody, with nothing logged; got: '
            . ($bindingLines === [] ? '(no `' . $parameter . ':` argument at all)' : implode(' | ', $bindingLines))
    );
}

// The empty-clerk guard resolves the UNION of both position lists, so it aborts
// only when NEITHER type has a seated holder. The guard names the new options,
// not the retired one.
check(
    'the empty-clerk guard resolves the union of both clerk sets via allClerkPositionIds()',
    (bool) preg_match('/resolveClerkUserIds\(\s*\$routing->allClerkPositionIds\(\)\s*\)/', $worker),
    'aborting on one empty type set would silence the other type, which still has holders'
);
check(
    'the empty-clerk guard names the new per-type options, not cav7ERClerkPositionIds',
    abortsWithLoggedError($remindBody, 'if (!$clerkUserIds)')
        && str_contains($worker, 'cav7ERStandardClerkPositionIds')
        && str_contains($worker, 'cav7ERReenlistClerkPositionIds')
        && !str_contains($worker, 'cav7ERClerkPositionIds'),
    'the abort log must point an admin at the options that actually exist'
);

// An unrecognized thread (prefix in neither type set) is skipped: it is NOT noted,
// NOT alerted and NOT marked, but a breadcrumb is logged because it reached the
// remind list, i.e. it would otherwise have been reminded (stories 13-14). The
// TYPE_UNRECOGNIZED branch must `continue` before postReminderNote — and it must
// sit AFTER selectThreadsToRemind, so a within-deadline unroutable thread stays
// silent (story 15) rather than log-spamming the whole queue every hour.
check(
    'an unrecognized-prefix thread is skipped with a breadcrumb, after the remind decision and before any note',
    (bool) preg_match(
        '/selectThreadsToRemind\(.*?TYPE_UNRECOGNIZED\s*\)\s*\{.*?logError\(.*?\bcontinue;/s',
        $worker
    ),
    'a junk or mis-prefixed thread must be logged and skipped only if it would otherwise have been reminded'
);
// The pin above matches even if a note-post or marker-write were slipped into the
// branch before its `continue` (the `.*?` swallows it). Slice the branch body and
// assert it writes nothing: noting or marking an unrecognized thread would mark a
// mis-prefixed real enlistment done and drop its alert for good (stories 13-14),
// the exact failure the skip exists to prevent.
$unrecognizedSkip = branchToContinue($worker, 'EnlistmentRouting::TYPE_UNRECOGNIZED');
check(
    'the unrecognized-prefix skip branch logs but never notes, alerts, or marks before it continues',
    $unrecognizedSkip !== ''
        && str_contains($unrecognizedSkip, 'logError')
        && !str_contains($unrecognizedSkip, 'postReminderNote')
        && !str_contains($unrecognizedSkip, 'recordReminder')
        && !str_contains($unrecognizedSkip, 'alertClerks'),
    'a postReminderNote/recordReminder/alertClerks inside this branch would mark a mis-prefixed enlistment done and never alert a clerk, yet still pass the looser ordering pin above'
);

// The branch's comparison OPERATOR is invisible to both pins above: they match
// `TYPE_UNRECOGNIZED)` followed by `{`, and the sliced branch body still reads as a
// log-only skip either way. Inverted to `!==`, every VALID enlistment takes the skip
// and is never reminded, while an unrecognized thread falls through to the
// empty-audience skip — one character, nothing ever reminded again, both skip pins
// green. So pin the operator, and pin the absence of the inversion.
check(
    'the unrecognized skip tests identity with TYPE_UNRECOGNIZED, not its negation',
    (bool) preg_match(
        '/if\s*\(\s*\$route\[[\'"]type[\'"]\]\s*===\s*EnlistmentRouting::TYPE_UNRECOGNIZED\s*\)/',
        $remindBody
    )
        && !(bool) preg_match(
            '/\$route\[[\'"]type[\'"]\]\s*!==\s*EnlistmentRouting::TYPE_UNRECOGNIZED/',
            $remindBody
        ),
    'inverted, the skip swallows every valid enlistment and the queue is never reminded again'
);

// A RECOGNIZED thread whose per-type clerk positions resolve to no seated holder
// (a blank per-type option, or an all-vacant seat set, while the OTHER type still
// has holders so the union guard above passed) must be skipped like an
// unrecognized one — logged and left UNMARKED so it retries once the config is
// fixed or a seat is filled. Silently posting the note and recording the marker
// with no clerk alerted would drop a real enlistment's alert for good. Pin: right
// after resolving $alertUserIds, an empty set logs and `continue`s before the note.
check(
    'a recognized thread that resolves no clerk to alert is logged and skipped, not silently noted+marked',
    (bool) preg_match(
        '/\$alertUserIds\s*=\s*\$this->resolveClerkUserIds\([^;]*;\s*if\s*\(\s*!\$alertUserIds\s*\)\s*\{.*?logError\(.*?\bcontinue;/s',
        $worker
    ),
    'an empty per-type audience must not post a note or write a marker; it must log and retry'
);
// As with the unrecognized branch, the pin above tolerates a write slipped in
// before the `continue`. Slice the branch body and assert it neither notes nor
// marks: doing either with no clerk resolved would post the applicant note and
// record the marker with nobody alerted — silently dropping a real enlistment's
// alert, the very failure this skip guards against.
$emptyAudienceSkip = branchToContinue($worker, 'if (!$alertUserIds)');
check(
    'the empty-audience skip branch logs but never notes, alerts, or marks before it continues',
    $emptyAudienceSkip !== ''
        && str_contains($emptyAudienceSkip, 'logError')
        && !str_contains($emptyAudienceSkip, 'postReminderNote')
        && !str_contains($emptyAudienceSkip, 'recordReminder')
        && !str_contains($emptyAudienceSkip, 'alertClerks'),
    'a postReminderNote/recordReminder/alertClerks inside this branch would silently mark the thread done with no clerk alerted, yet still pass the looser structural pin above'
);

// A prefix listed under BOTH type sets is a config error: it fail-safes to the
// union (handled in the seam) and the worker logs a config warning once.
check(
    'an overlapping-prefix config is surfaced with a warning',
    str_contains($worker, 'overlappingPrefixIds()')
        && (bool) preg_match('/if\s*\(\s*\$overlapPrefixIds\s*\)\s*\{\s*[^}]*?logError\(/s', $worker),
    'an ambiguous prefix must never silently drop a responsible clerk'
);

// --- the Setup upgrade step retires the old option -------------------------
// A real 1.0.0 -> 1.1.0 upgrade must remove the retired cav7ERClerkPositionIds so
// no dead option lingers in the ACP; the step is keyed to the 1.1.0 version id.
check(
    'a Setup upgrade step for 1.1.0 removes the retired cav7ERClerkPositionIds option',
    (bool) preg_match('/function\s+upgrade1010070Step1\b/', $setup)
        && (bool) preg_match("/delete\(\s*'xf_option'\s*,.*?cav7ERClerkPositionIds/s", $setup),
    'without the removal an admin upgrading keeps a dead option nothing reads'
);
preg_match('/"version_id"\s*:\s*(\d+)/', $addonJson, $versionMatch);
$versionId = (int) ($versionMatch[1] ?? 0);
check(
    'the addon.json version is at or past 1.1.0 so the upgrade step runs',
    $versionId >= 1010070,
    'the upgrade1010070Step1 step only runs if the installed version crosses 1.1.0; got ' . $versionId
);
// Issue #186 changes the handled signal and adds an option, so the version must
// move past 1.1.0 as well. XenForo only re-imports an add-on's data when the
// version rises, so without the bump cav7ERInProcessingPrefixIds never reaches a
// live install and the new guard aborts every run.
check(
    'the addon.json version is bumped past 1.1.0 for the #186 option',
    $versionId > 1010070,
    'a new option only lands on an existing install when the version rises; got ' . $versionId
);

// --- the SV/MultiPrefix floor is stated in SV's own numbering ---------------
// The status prefixes are read out of SV/MultiPrefix's link table, and the manifest
// is the only place the floor lives: XenForo enforces `require` on install and
// upgrade, and the runtime guard checks activeness alone (see above). So the floor
// has to be written in a numbering XenForo can compare. SV numbers its releases by
// build timestamp, not XenForo's AABBCCDE scheme, so a floor written in the AABBCCDE
// range is met by every SV release in existence and gates nothing — an install
// missing the table would sail through and fail at the first scan instead.
$multiPrefixFloor = $multiPrefixFloorLiteral;
check(
    'addon.json requires SV/MultiPrefix',
    $multiPrefixFloor !== null,
    'the link-table read has no declared dependency at all'
);
check(
    'the SV/MultiPrefix floor is a timestamp version id, not an AABBCCDE one',
    is_int($multiPrefixFloor) && $multiPrefixFloor >= 1000000000,
    'SV version ids are build timestamps; a floor below 1000000000 is cleared by every SV release and enforces nothing. Got: ' . var_export($multiPrefixFloor, true)
);
// The second element of a require pair is admin-facing: XenForo prints it
// verbatim in the ACP's unmet-dependency message, so it has to read as the
// version an admin should go and install, like every other label in the suite
// ('XenForo 2.3.0+'). Why the floor is a timestamp belongs in the comment above,
// not in a string the ACP shows.
$multiPrefixLabel = $addonManifest['require']['SV/MultiPrefix'][1] ?? '';
check(
    'the SV/MultiPrefix require label is a short version string, not developer rationale',
    is_string($multiPrefixLabel)
        && $multiPrefixLabel !== ''
        && strlen($multiPrefixLabel) <= 60
        && !str_contains($multiPrefixLabel, '('),
    'an admin reads this label as the requirement itself. Got: ' . var_export($multiPrefixLabel, true)
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
