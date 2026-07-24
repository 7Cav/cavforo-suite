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
 * any later bare `return;` in the file — alertClerks has one, several hundred
 * lines down. Slicing the block first bounds the search to the guard's own body,
 * so a deleted `return;` fails the pin instead of borrowing one from a stranger.
 *
 * Brace counting is naive about braces inside strings; none of the guards it is
 * used on has any, and a stray one would only ever end the slice early, which
 * fails the pin rather than passing it falsely.
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
                return substr($src, $start, $i - $start + 1);
            }
        }
    }
    return '';
}

/**
 * True when the given `if` block both logs an error and returns — the shape every
 * abort guard in remind() has to keep. Scoped to the block by ifBlock(), so the
 * `return;` has to be the guard's own.
 */
function abortsWithLoggedError(string $src, string $startMarker): bool
{
    $block = ifBlock($src, $startMarker);
    return $block !== ''
        && str_contains($block, 'logError(')
        && (bool) preg_match('/\breturn;/', $block);
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
// Read once here; the version bump and the SV/MultiPrefix require pair are checked
// further down, and the #186 vendor-active guard needs the declared floor to prove
// the worker does not carry a second copy of it.
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

// An empty resolved clerk set must abort the run with a logged signal, symmetric
// with the node/bot guards. If both position options are blank or garbage, or
// the roster drifts so nothing resolves, getClerkUserIds returns [] and no
// thread can reach an audience: every remindable thread would fall through the
// per-type empty-audience skip and log the same complaint once an hour. One
// abort with one line says the same thing without the noise.
check(
    'remind() aborts when no clerk resolves (logError + early return on the empty clerk set)',
    abortsWithLoggedError($worker, 'if (!$clerkUserIds)'),
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

// The status comes from SV/MultiPrefix's own link table, scoped to the threads
// this scan is about. The vendor stores EVERY prefix a thread carries there.
check(
    'the worker reads prefix links from the SV/MultiPrefix link table, scoped to the scanned threads',
    (bool) preg_match('/FROM xf_sv_thread_prefix_link\b/', $worker)
        && (bool) preg_match('/FROM xf_sv_thread_prefix_link\b.*?thread_id IN/s', $worker),
    'an unscoped read would pull the whole board\'s prefix links'
);

// A missing or unreadable link table (SV/MultiPrefix uninstalled, the table
// renamed, permissions revoked) must abort the run with a logged error. Without
// the guard, a thrown query would either kill the cron or, if swallowed into an
// empty result, read the whole queue as un-actioned and remind all of it at once.
$prefixLinkBody = methodBody($worker, 'fetchThreadPrefixLinks');
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
// here, so the caller keeps owning the emptiness check.
check(
    'the prefix-link read does not answer an empty request with an empty result',
    $prefixLinkBody !== ''
        && !(bool) preg_match('/!\$threadIds\s*\)\s*\{?\s*return\s*\[\];/s', $prefixLinkBody),
    'two different questions must not share the [] answer the caller reads as a table fault'
);
$emptyQueueReturnAt = strpos($worker, 'if (!$threads)');
$prefixLinkReadAt   = strpos($worker, '$this->fetchThreadPrefixLinks(');
check(
    'the empty-queue return comes before the prefix-link read, so the read is never asked about nothing',
    $emptyQueueReturnAt !== false && $prefixLinkReadAt !== false && $emptyQueueReturnAt < $prefixLinkReadAt,
    'the helper has no empty-input branch, so the caller has to hold that end up'
);
check(
    'remind() aborts on an unreadable prefix link table (logError + early return)',
    abortsWithLoggedError($worker, 'if ($prefixLinks === null)'),
    'without the abort, an unreadable table reads as "nothing is in processing" and reminds the whole queue'
);
// An empty result is the same failure wearing different clothes: every valid
// queue thread carries at least its type prefix in this table, so zero rows for
// a non-empty queue means the table is not populated, not that nothing is in
// processing. Abort rather than mass-remind, matching the empty-clerk guard.
check(
    'remind() aborts when a non-empty queue yields no prefix links at all',
    abortsWithLoggedError($worker, 'if (!$prefixLinks)'),
    'every valid queue thread carries a type prefix here, so no rows at all means the table is unpopulated'
);

// A blank or unparseable in-processing option resolves to no statuses, so
// nothing could ever suppress and every past-deadline thread would be reminded,
// including the ones a clerk is actively working. Abort with a signal instead.
check(
    'remind() aborts when the in-processing option parses to nothing (logError + early return)',
    abortsWithLoggedError($worker, 'if (!$inProcessingPrefixIds)')
        && str_contains($worker, 'cav7ERInProcessingPrefixIds'),
    'a blank or garbage option would otherwise remind every past-deadline thread in the queue'
);
// The abort must come before any reminder is posted; a guard that ran after the
// loop would be decorative.
$guardAt    = strpos($worker, 'if (!$inProcessingPrefixIds)');
$decisionAt = strpos($worker, 'selectThreadsToRemind');
check(
    'the in-processing guard runs before the remind loop',
    $guardAt !== false && $decisionAt !== false && $guardAt < $decisionAt,
    'a guard downstream of the decision cannot stop a mass remind'
);

// The blank-option guard's mirror image, and the worse fault of the two: an
// enlistment TYPE prefix (57/58) configured into the status set. Every valid queue
// thread carries one, so one entry reads the whole queue as handled and the add-on
// goes permanently, silently dark. The option is free text with no
// validation_class and sits immediately next to the two type-prefix options it
// must never contain, so prose in the help text is not a defence. The pure rule is
// EnlistmentRouting::typePrefixIdsAmong, exercised in EnlistmentRoutingTest.
check(
    'remind() aborts when a type prefix is configured as an in-processing status',
    abortsWithLoggedError($worker, 'if ($typePrefixesInStatusSet)')
        && (bool) preg_match('/\$typePrefixesInStatusSet\s*=\s*\$routing->typePrefixIdsAmong\(\s*\$inProcessingPrefixIds\s*\)/', $worker),
    'a 57 or 58 in the status set silences the add-on with nothing in the log to say why'
);
// The abort names both options an admin has to compare, since the collision spans
// two of them and neither is wrong on its own.
check(
    'the type-prefix collision abort names the status option and the type options',
    (bool) preg_match(
        '/cav7ERInProcessingPrefixIds.*?cav7ERStandardPrefixIds.*?cav7ERReenlistPrefixIds/s',
        ifBlock($worker, 'if ($typePrefixesInStatusSet)')
    ),
    'the admin has to know which two lists to compare'
);
$typeCollisionGuardAt = strpos($worker, 'if ($typePrefixesInStatusSet)');
check(
    'the type-prefix collision guard runs before the remind decision',
    $typeCollisionGuardAt !== false && $decisionAt !== false && $typeCollisionGuardAt < $decisionAt,
    'a guard downstream of the decision cannot stop the silencing it exists to catch'
);

// SV/MultiPrefix DISABLED rather than uninstalled is the state neither link-table
// guard can see. XenForo checks `require` on install and upgrade only, never at
// runtime, and does not cascade a disable to dependents, so the table and all its
// stale rows stay in place while the vendor stops maintaining them: the read
// succeeds, both guards pass, and every application picked up since the disable
// reads un-actioned and gets the note plus the clerk alert.
check(
    'remind() aborts when SV/MultiPrefix is not active (disabled, not just uninstalled)',
    abortsWithLoggedError($worker, "if (!\\XF::isAddOnActive('SV/MultiPrefix'"),
    'a disabled vendor add-on leaves a stale table that reads as "nothing is handled"'
);
// The floor is checked, not just activeness, and it is not a second copy of the
// number: it comes back out of the manifest that declares it, so the runtime check
// and addon.json cannot drift apart.
check(
    'the active check passes a version floor read from addon.json, not a duplicated literal',
    (bool) preg_match("/isAddOnActive\(\s*'SV\/MultiPrefix'\s*,\s*\\\$this->multiPrefixFloor\(\)\s*\)/", $worker)
        && (bool) preg_match("/require.*?SV\/MultiPrefix/s", methodBody($worker, 'multiPrefixFloor'))
        && !(bool) preg_match('/\b' . preg_quote((string) $multiPrefixFloorLiteral, '/') . '\b/', $worker),
    'hard-coding the floor here is how it drifts from the declared requirement'
);

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
// keys by thread is the trap wearing a different name. Four uses, all named.
$remindBody = methodBody($worker, 'remind');
$allowedPrefixLinkUses = [
    '/\$prefixLinks\s*=\s*\$this->fetchThreadPrefixLinks\(/',
    '/if\s*\(\s*\$prefixLinks\s*===\s*null\s*\)/',
    '/if\s*\(\s*!\$prefixLinks\s*\)/',
    '/ProcessingStatus::inProcessingThreadIds\(\s*\$prefixLinks\s*,/',
];
$prefixLinkUses = [];
$unexpectedPrefixLinkUses = [];
foreach (explode("\n", $remindBody) as $line) {
    if (!str_contains($line, '$prefixLinks')) {
        continue;
    }
    $prefixLinkUses[] = trim($line);
    foreach ($allowedPrefixLinkUses as $allowed) {
        if (preg_match($allowed, $line)) {
            continue 2;
        }
    }
    $unexpectedPrefixLinkUses[] = trim($line);
}
check(
    'the raw prefix-link rows are touched only by their two guards and the ProcessingStatus call',
    count($prefixLinkUses) === 4 && $unexpectedPrefixLinkUses === [],
    'unexpected: ' . implode(' | ', $unexpectedPrefixLinkUses)
        . ' (all ' . count($prefixLinkUses) . ' use(s): ' . implode(' | ', $prefixLinkUses) . ')'
);
check(
    'the in_processing fact handed to the decision is that map, not a bare link-table hit',
    (bool) preg_match(
        '/[\'"]in_processing[\'"]\s*=>\s*isset\(\$inProcessing\[[^\]]+\]\)/',
        $worker
    ),
    'the decision reads one boolean per thread, and it must be the configured-set membership'
);
// Acceptance criterion 4: reply authorship no longer influences the decision. The
// name-based checks above ("fetchReplyAuthorIds is gone") are walked past by any
// renamed helper, so pin the fact array itself: exactly the four keys
// ReminderDecision documents, no fifth one carrying who replied back in. The
// matching parameter list on shouldRemind is pinned in ReminderDecisionTest.
$factsLiteral = '';
if (preg_match('/\$facts\[\]\s*=\s*\[(.*?)\n            \];/s', $worker, $factsMatch)) {
    $factsLiteral = $factsMatch[1];
}
preg_match_all("/'([a-z_]+)'\s*=>/", $factsLiteral, $factKeyMatches);
$factKeys = $factKeyMatches[1] ?? [];
sort($factKeys);
check(
    'the fact array handed to the decision carries exactly the four documented keys',
    $factsLiteral !== ''
        && $factKeys === ['already_reminded', 'in_processing', 'op_timestamp', 'thread_id'],
    'got: ' . implode(', ', $factKeys)
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
    abortsWithLoggedError($worker, 'if (!$clerkUserIds)')
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
// The status prefixes are read out of SV/MultiPrefix's link table, so the
// dependency has to be declared with a floor XenForo can actually enforce. SV
// numbers its releases by build timestamp, not XenForo's AABBCCDE scheme, so a
// floor written in the AABBCCDE range is met by every SV release in existence and
// gates nothing — an install missing the table would sail through the check and
// fail at the first scan instead.
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
