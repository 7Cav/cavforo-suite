<?php

/**
 * Issue #75 — pins the vendor-coupled wiring of the enlistment reminder so a
 * regression fails CI rather than shipping silently. The decision itself is
 * exercised for real in ReminderDecisionTest (the remind rule) and
 * EnlistmentRoutingTest (the #144 type split); this holds the parts that need a
 * live XenForo + NF/Rosters to run: the hourly cron entry, the options and their
 * runtime reads, the marker table created on install and dropped on uninstall,
 * the deadline clamp, the clerk-seat query, the node-scoped scan, the
 * SteamChecker-style bot post with its first_post_id correction, and — per issue
 * #144 — the per-type alert routing, the prefix-badged alert template, the
 * skip-and-log of an unrecognized thread, and the option-retiring upgrade step.
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
// Issue #144 replaced the single cav7ERClerkPositionIds with four per-type
// options (a prefix list and a clerk-position list for each of Standard and
// Re-Enlistment). All four, plus node/bot/deadline, must be defined and read.
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

// Every option is read at runtime (the cron entry reads the deadline; the worker
// reads the node, bot user, and the four per-type prefix/clerk-position options).
$worker = (string) file_get_contents("$root/QueueReminder.php");
$runtime = $scanSrc . $worker;
foreach ($expectedOptions as $id) {
    check(
        "$id is read at runtime via \\XF::options()",
        (bool) preg_match('/options\(\)->' . preg_quote($id, '/') . '\b/', $runtime),
        'an option nothing reads is dead config'
    );
}

// The deadline default 24 and the queue node default 325 are what the issue asks;
// the four routing defaults are the agreed per-type sets whose position lists
// union to the pre-split default (579,580,751,960,1012), so pickup coverage is
// unchanged and only the alert audience narrows.
$defaults = [
    'cav7ERDeadlineHours'            => '24',
    'cav7ERQueueNodeId'              => '325',
    'cav7ERStandardPrefixIds'        => '57',
    'cav7ERStandardClerkPositionIds' => '579,580,751,1012',
    'cav7ERReenlistPrefixIds'        => '58',
    'cav7ERReenlistClerkPositionIds' => '579,960,1012',
];
$defaultByOption = [];
if ($optXml !== false) {
    foreach ($optXml->option as $option) {
        $defaultByOption[(string) $option['option_id']] = (string) $option->default_value;
    }
}
foreach ($defaults as $id => $want) {
    check("the $id default is $want", ($defaultByOption[$id] ?? null) === $want);
}
// The union of the two default position sets is exactly the old single default,
// so pickup coverage does not change when the alert audience splits by type.
$standardSeats = array_map('intval', explode(',', $defaults['cav7ERStandardClerkPositionIds']));
$reenlistSeats = array_map('intval', explode(',', $defaults['cav7ERReenlistClerkPositionIds']));
$union = array_values(array_unique(array_merge($standardSeats, $reenlistSeats)));
sort($union);
check(
    'the union of the two default clerk sets equals the pre-split five seats',
    $union === [579, 580, 751, 960, 1012],
    'got: ' . implode(',', $union)
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
// Issue #144: the type split reads a thread's enlistment type from its primary
// prefix, so the queue fetch must additionally select prefix_id — without it the
// per-type routing has nothing to route on and every thread reads as unrouted.
check(
    'the queue scan selects prefix_id for type routing',
    (bool) preg_match('/SELECT[^;]*\bprefix_id\b.*?FROM xf_thread/s', $worker),
    'the alert audience is chosen from the thread prefix, which must be fetched'
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
// can't be satisfied by fetchReplyAuthorIds's separate xf_post query.
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
// per-type set the router resolved for this thread's prefix ($alertUserIds),
// while pickup and the mass-remind guard still resolve the union ($clerkUserIds).
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
// worker builds the router from the four options, pickup resolves the union, the
// per-type set is alerted, an unrecognized thread is skipped with one breadcrumb,
// an overlap config is warned, and the Setup upgrade step retires the old option.
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

// Pickup and the mass-remind guard resolve the UNION of both position lists, so a
// reply from any of the five seats still clears the reminder — unchanged from the
// single-option behaviour. The guard names the new options, not the retired one.
check(
    'pickup resolves the union of both clerk sets via pickupPositionIds()',
    (bool) preg_match('/resolveClerkUserIds\(\s*\$routing->pickupPositionIds\(\)\s*\)/', $worker),
    'pickup coverage must be the union, so any seat replying counts as a pickup'
);
check(
    'the empty-clerk guard names the new per-type options, not cav7ERClerkPositionIds',
    (bool) preg_match('/if\s*\(\s*!\$clerkUserIds\s*\).*?logError\(.*?return;/s', $worker)
        && str_contains($worker, 'cav7ERStandardClerkPositionIds')
        && str_contains($worker, 'cav7ERReenlistClerkPositionIds')
        && !str_contains($worker, 'cav7ERClerkPositionIds'),
    'the abort log must point an admin at the options that actually exist'
);

// An unrecognized thread (prefix in neither type set) is skipped: it is NOT noted,
// NOT alerted and NOT marked, but a breadcrumb is logged because it reached the
// remind list, i.e. it would otherwise have been reminded (stories 13-14). The
// TYPE_UNRECOGNIZED branch must `continue` before postReminderNote.
check(
    'an unrecognized-prefix thread is skipped with a breadcrumb, before any note',
    (bool) preg_match(
        '/TYPE_UNRECOGNIZED\s*\)\s*\{.*?logError\(.*?\bcontinue;/s',
        $worker
    ),
    'a junk or mis-prefixed thread must be logged and skipped, never mass-alerted or noted'
);

// A prefix listed under BOTH type sets is a config error: it fail-safes to the
// union (handled in the seam) and the worker logs a config warning once.
check(
    'an overlapping-prefix config is surfaced with a warning',
    str_contains($worker, 'overlappingPrefixIds()')
        && (bool) preg_match('/overlapPrefixIds\b.*?logError\(/s', $worker),
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
check(
    'the addon.json version is bumped so the upgrade step runs',
    (bool) preg_match('/"version_id"\s*:\s*1010070/', (string) file_get_contents("$root/addon.json")),
    'the upgrade1010070Step1 step only runs if the installed version crosses 1.1.0'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
