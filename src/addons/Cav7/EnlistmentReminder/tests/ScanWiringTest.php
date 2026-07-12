<?php

/**
 * Issue #75 — pins the vendor-coupled wiring of the enlistment reminder so a
 * regression fails CI rather than shipping silently. The decision itself is
 * exercised for real in ReminderDecisionTest; this holds the parts that need a
 * live XenForo + NF/Rosters to run: the hourly cron entry, the four options and
 * their runtime reads, the marker table created on install and dropped on
 * uninstall, the deadline clamp, the clerk-seat query, the node-scoped scan, and
 * the SteamChecker-style bot post with its first_post_id correction.
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

// --- the four options exist and are read at runtime -----------------------
$optXml = @simplexml_load_file("$root/_data/options.xml");
check('_data/options.xml could be read', $optXml !== false);

$optionIds = [];
if ($optXml !== false) {
    foreach ($optXml->option as $option) {
        $optionIds[] = (string) $option['option_id'];
    }
}
$expectedOptions = ['cav7ERQueueNodeId', 'cav7ERBotUserId', 'cav7ERClerkPositionIds', 'cav7ERDeadlineHours'];
foreach ($expectedOptions as $id) {
    check("option $id is defined", in_array($id, $optionIds, true));
}
check(
    '_output has one options file per _data option',
    count(outputItems($root, 'options')) === ($optXml !== false ? count($optXml->option) : -1)
);

// Every option is read at runtime (the cron entry reads the deadline; the worker
// reads the node, bot user, and clerk positions).
$worker = (string) file_get_contents("$root/QueueReminder.php");
$runtime = $scanSrc . $worker;
foreach ($expectedOptions as $id) {
    check(
        "$id is read at runtime via \\XF::options()",
        (bool) preg_match('/options\(\)->' . preg_quote($id, '/') . '\b/', $runtime),
        'an option nothing reads is dead config'
    );
}

// The deadline default 24 and the queue node default 325 are what the issue asks.
if ($optXml !== false) {
    foreach ($optXml->option as $option) {
        if ((string) $option['option_id'] === 'cav7ERDeadlineHours') {
            check('the deadline default is 24 hours', (string) $option->default_value === '24');
        }
        if ((string) $option['option_id'] === 'cav7ERQueueNodeId') {
            check('the queue node default is 325', (string) $option->default_value === '325');
        }
        if ((string) $option['option_id'] === 'cav7ERClerkPositionIds') {
            check(
                'the clerk positions default to the five configured seats',
                (string) $option->default_value === '579,580,751,960,1012'
            );
        }
    }
}

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
// with the node/bot guards — never silently mass-remind. If cav7ERClerkPositionIds
// is blank/garbage or the roster drifts so nothing resolves, getClerkUserIds
// returns [], and with no guard every past-deadline thread (clerk-handled or not)
// gets a one-shot note.
check(
    'remind() aborts when no clerk resolves (logError + early return on the empty clerk set)',
    (bool) preg_match('/if\s*\(\s*!\$clerkUserIds\s*\).*?logError\(.*?return;/s', $worker),
    'without this guard a blank or drifted clerk-position option reminds the whole past-deadline queue'
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

// The reply-author query counts only visible replies, so a soft-deleted clerk
// reply is not mistaken for a live pickup. Bind the message_state = visible
// filter on the xf_post reply query, not just the column name.
check(
    'the reply-author query drops soft-deleted replies (message_state filtered to visible)',
    (bool) preg_match(
        '/FROM xf_post\b.*?position\s*>\s*0.*?message_state\s*=\s*\?.*?\[\'visible\'\]/s',
        $worker
    ),
    'without the message_state filter a deleted clerk reply would suppress a live reminder'
);

// --- the decision routes through the pure unit -----------------------------
check(
    'the worker delegates the decision to ReminderDecision::selectThreadsToRemind',
    str_contains($worker, 'ReminderDecision::selectThreadsToRemind'),
    'the rule is extracted so it can be unit-tested without XenForo'
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
check(
    'a saved note is always followed by recording the marker (record iff the post saved)',
    (bool) preg_match('/if\s*\(\s*\$this->postReminderNote\([^)]*\)\s*\)\s*\{\s*\$this->recordReminder\(/s', $worker),
    'if the marker write is skipped after a successful post, the remind-once guarantee breaks'
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

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
