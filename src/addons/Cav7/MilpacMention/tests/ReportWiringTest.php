<?php

/**
 * Issue #86 — pins the vendor-coupled wiring of the milpac-mention engine on the
 * report-comment surface so a regression fails CI rather than shipping silently.
 * It mirrors ProfilePostWiringTest.php (#85) and WiringTest.php (#84): the one
 * firing extension, the one distinct alert template and its alert-line phrase, and
 * the depends_on_addon_id tag every alert must carry.
 *
 * The report surface is the odd one out on preferences: being named in a report
 * cannot be muted in XF, so — unlike Post/ProfilePost/NF-Tickets — it gets an alert
 * template but NO opt-out row and NO opt-out phrase. This test asserts that ABSENCE
 * as hard as it asserts the presences (spec §3.1: "mirror XF's own mention
 * behaviour on reports exactly").
 *
 * The firing rules themselves (self-skip, @-dedup, shared cap) are pure and run for
 * real in FiringRulesTest against the shared MilpacResolver every surface feeds
 * through the detection hook — this test pins only the parts that need a live
 * XenForo to actually run.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ReportWiringTest.php
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

/** All _output item files of a type, excluding the _metadata.json index. */
function outputItems(string $root, string $type): array
{
    return array_values(array_filter(
        glob("$root/_output/$type/*") ?: [],
        fn ($f) => basename($f) !== '_metadata.json'
    ));
}

/**
 * The source of one method's body, from its `function <name>` declaration up to
 * the next method's docblock/declaration or end-of-file. Keeps a check anchored to
 * the owning method (copied from WiringTest.php).
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

$addon = json_decode((string) @file_get_contents("$root/addon.json"), true);
$versionId = is_array($addon) ? ($addon['version_id'] ?? null) : null;
check('addon.json is valid JSON with a positive version_id', is_int($versionId) && $versionId > 0);

// =========================================================================
// class_extensions — the ONE report firing seam, and NO opt-out seam (spec §2.4/§3.1)
// =========================================================================
$classExtXml = @simplexml_load_file("$root/_data/class_extensions.xml");
check('_data/class_extensions.xml could be read', $classExtXml !== false);

$extByFrom = [];
if ($classExtXml !== false) {
    foreach ($classExtXml->extension as $ext) {
        $extByFrom[(string) $ext['from_class']] = [
            'to' => (string) $ext['to_class'],
            'active' => (string) $ext['active'],
        ];
    }
}

// The report-comment firing extension — the only class-extension #86 adds.
check(
    'XF\\Service\\Report\\NotifierService is extended to Cav7\\MilpacMention\\XF\\Service\\Report\\NotifierService and active',
    isset($extByFrom['XF\Service\Report\NotifierService'])
        && $extByFrom['XF\Service\Report\NotifierService']['to'] === 'Cav7\MilpacMention\XF\Service\Report\NotifierService'
        && $extByFrom['XF\Service\Report\NotifierService']['active'] === '1'
);

// NO opt-out on report (spec §3.1, build-time check §8.5): reports are not
// opt-out-able in XF, so milpac_mention must NOT register an opt-out override on
// XF\Alert\ReportHandler — match XF's own `mention` behaviour on reports exactly.
check(
    'no opt-out extension is registered on XF\\Alert\\ReportHandler (reports are not opt-out-able)',
    !isset($extByFrom['XF\Alert\ReportHandler']),
    'being named in a report cannot be muted in XF; a ReportHandler getOptOutActions override would add a row XF itself does not have'
);
check(
    'no ReportHandler.php source file exists (do not add a report opt-out override)',
    !is_file("$root/XF/Alert/ReportHandler.php")
);

check(
    '_output has one class_extensions file per _data extension',
    count(outputItems($root, 'class_extensions'))
        === ($classExtXml !== false ? count($classExtXml->extension) : -1)
);

// =========================================================================
// alert template — reuses the stock report handler, deep-links to the report (spec §3.2)
// =========================================================================
$templatesXml = @simplexml_load_file("$root/_data/templates.xml");
check('_data/templates.xml could be read', $templatesXml !== false);

$templateBody = [];
$templateType = [];
if ($templatesXml !== false) {
    foreach ($templatesXml->template as $tpl) {
        $title = (string) $tpl['title'];
        $templateBody[$title] = (string) $tpl;
        $templateType[$title] = (string) $tpl['type'];
    }
}

check(
    'public template alert_report_milpac_mention is declared',
    isset($templateBody['alert_report_milpac_mention'])
        && ($templateType['alert_report_milpac_mention'] ?? '') === 'public'
);
// The report alert deep-links to the report ($content is the Report entity, via the
// stock ReportHandler which is AbstractHandler<Report>) and renders the milpac
// phrase — same shape as XF's own alert_report_mention (link('reports', $content)).
check(
    'the report template deep-links to the report and renders its phrase',
    str_contains($templateBody['alert_report_milpac_mention'] ?? '', "link('reports', \$content)")
        && str_contains($templateBody['alert_report_milpac_mention'] ?? '', 'cav7_mm_alert_report_milpac_mention')
        && str_contains($templateBody['alert_report_milpac_mention'] ?? '', 'username_link($user'),
    'the alert opens the report in one click; {name} names the linker, {title} the report'
);
// {title} is the report title, which hangs off the Report entity directly. A wrong
// traversal renders an empty title, which the byte-for-byte compare below cannot
// catch (both _data and _output would agree on the wrong value).
check(
    'the report template traverses $content.title for {title}',
    str_contains($templateBody['alert_report_milpac_mention'] ?? '', '$content.title'),
    'the report title hangs off the Report entity directly on this surface'
);
check(
    'the report _output template body matches _data byte-for-byte (§3.2 verbatim)',
    ($templateBody['alert_report_milpac_mention'] ?? '') !== ''
        && @file_get_contents("$root/_output/templates/public/alert_report_milpac_mention.html")
            === ($templateBody['alert_report_milpac_mention'] ?? null)
);

// version_id parity: every _data template tracks addon.json's version_id.
$templateVersionsMatch = $templatesXml !== false && $versionId !== null;
if ($templatesXml !== false) {
    foreach ($templatesXml->template as $tpl) {
        if ((int) $tpl['version_id'] !== (int) $versionId) {
            $templateVersionsMatch = false;
        }
    }
}
check('every _data template carries version_id === addon.json version_id', $templateVersionsMatch);

// =========================================================================
// phrases — the alert line, and NO opt-out label (spec §3.2)
// =========================================================================
$phraseXml = @simplexml_load_file("$root/_data/phrases.xml");
check('_data/phrases.xml could be read', $phraseXml !== false);

$phraseText = [];
if ($phraseXml !== false) {
    foreach ($phraseXml->phrase as $phrase) {
        $phraseText[(string) $phrase['title']] = (string) $phrase;
    }
}

check(
    'the report alert-line phrase carries the exact §3.2 copy',
    ($phraseText['cav7_mm_alert_report_milpac_mention'] ?? null)
        === '{name} linked your milpac in a comment in the report {title}'
);
// Reports are not opt-out-able, so there is deliberately NO opt-out phrase for the
// report surface — mirror XF's own `mention` family, which omits it too.
check(
    'there is NO report opt-out phrase (reports are not opt-out-able)',
    !isset($phraseText['alert_opt_out.report_milpac_mention']),
    'an alert_opt_out.report_milpac_mention phrase would imply a preference row XF does not offer for reports'
);

// _output byte-for-byte parity for the new alert-line phrase (copy ships verbatim).
check(
    'the report alert-line _output file matches byte-for-byte',
    @file_get_contents("$root/_output/phrases/cav7_mm_alert_report_milpac_mention.txt")
        === '{name} linked your milpac in a comment in the report {title}'
);
check(
    'there is NO report opt-out _output phrase file',
    !is_file("$root/_output/phrases/alert_opt_out.report_milpac_mention.txt")
);

$phraseVersionsMatch = $phraseXml !== false && $versionId !== null;
if ($phraseXml !== false) {
    foreach ($phraseXml->phrase as $phrase) {
        if ((int) $phrase['version_id'] !== (int) $versionId) {
            $phraseVersionsMatch = false;
        }
    }
}
check('every _data phrase carries version_id === addon.json version_id', $phraseVersionsMatch);

// =========================================================================
// the report firing extension (spec §2.4 / §2.5 / §2.6)
// =========================================================================
$src = (string) @file_get_contents("$root/XF/Service/Report/NotifierService.php");
check('Report NotifierService source exists', $src !== '');

// The stock mention pass on the report surface is the bespoke notifyMentioned()
// (no args), NOT the Post loadNotifiers()/$timeLimit notify() — match it exactly,
// then fire. A mismatched signature would fatal when XF calls notifyMentioned().
check(
    'notifyMentioned() matches the parent no-arg signature and fires after parent::notifyMentioned()',
    (bool) preg_match('/function\s+notifyMentioned\s*\(\s*\).*?parent::notifyMentioned\(\s*\).*?fireMilpacMentions\(/s', $src),
    'the report notifier fires the stock mention alert through notifyMentioned(), not notify() (build-time check §8)'
);
// Same-instance invariant: the ReportComment the CommenterService prepared
// (message entity, stashed against) is the very $this->comment the NotifierService
// holds, so take() finds the stash. Report gates/self-skips against the comment, so
// the stash key is the comment, not the report.
check(
    'firing reads the stashed recipients via the consuming MilpacStash::take($comment)',
    str_contains($src, 'MilpacStash::take($comment)'),
    'same-instance invariant: the notifier holds the very ReportComment the detection hook prepared'
);
check(
    "raises action 'milpac_mention' on content type 'report', reusing the stock ReportHandler",
    str_contains($src, "'report'") && str_contains($src, "'milpac_mention'")
);
// The content-id argument decides which content the alert deep-links to. XF's own
// report mention alert keys on $comment->report_id (the Report PK carried on the
// comment), so the alert resolves against the Report handler — pin that exact field.
check(
    'the alert() content-id is the report id the stock report alert uses ($comment->report_id)',
    str_contains($src, '$comment->report_id'),
    'the wrong id would deep-link the alert to the wrong report (or a nonexistent one)'
);
check(
    'self-links are suppressed at the firing edge ($user->user_id == $comment->user_id)',
    (bool) preg_match('/\$user->user_id\s*==\s*\$comment->user_id/', $src),
    'the core report mention self-check does not run for the distinct action (rule §2.5.1); whitespace-tolerant so a reformat cannot spuriously fail'
);
check(
    'firing dedups against anyone already alerted — reads the guard AND writes it back (usersAlerted, one alert per member)',
    str_contains($src, 'if (!empty($this->usersAlerted[')
        && (bool) preg_match('/\$this->usersAlerted\[[^\]]*\]\s*=\s*true/', $src),
    'mere presence of the $this->usersAlerted token would pass even if it never guards the alert; pin both the read guard and the write-back (rules §2.5.2/5)'
);
// Gating parity (§2.6): the report notifier gates on the REPORT's canView (the
// vendor's getUsersForMentionedNotification / sendMentionNotification both call
// $this->report->canView()), so a member who cannot see the report is filtered out.
check(
    'gating parity — the recipient must be able to view the report (asVisitor $report->canView())',
    str_contains($src, 'asVisitor') && str_contains($src, '$report->canView()'),
    'a member who cannot see the report is filtered out, same as the stock report notifier (§2.6)'
);

// Every alert() call carries depends_on_addon_id so uninstall clears alerts.
$alertCalls = preg_match_all('/->alert\(/', $src);
$dependsTags = preg_match_all("/'depends_on_addon_id'\s*=>\s*'Cav7\/MilpacMention'/", $src);
check(
    "every alert() call passes depends_on_addon_id => 'Cav7/MilpacMention'",
    $alertCalls > 0 && $alertCalls === $dependsTags,
    "alert() calls=$alertCalls tagged=$dependsTags — an untagged alert survives uninstall"
);

// Report comment notifications run INLINE in the member's request (ReportController
// -> CommenterService::sendNotifications), with no deferred-job net (unlike Post's
// XF\Job\Notifier). So a failure must be contained, logged, and skipped
// per-recipient, never surfaced on the already-committed comment action.
$fireBody = methodBody($src, 'fireMilpacMentions');
check(
    'firing is contained — fireMilpacMentions catches and forwards $e to logException($e, false, …), never rethrowing',
    $fireBody !== ''
        && (bool) preg_match('/catch\s*\(.*?logException\(\s*\$e,\s*false/s', $fireBody)
        && str_contains($fireBody, '[Cav7/MilpacMention] firing failed')
        && !str_contains($fireBody, 'throw'),
    'the comment is already saved+committed; an uncontained alert()/canView() failure would surface on the member\'s action'
);
// The containment above only proves a catch→logException exists and the body never
// rethrows; it does NOT prove the pre-loop findByIds()/repository() lookups are
// inside the guard. This surface fires inline with no deferred-job net, so a DB
// error on findByIds would 500 the already-committed action unless it too is
// contained. Pin the layout: the outer try opens BEFORE findByIds, findByIds
// precedes the loop, and a catch follows the loop.
$outerTryPos = strpos($fireBody, 'try');
$findByIdsPos = strpos($fireBody, 'findByIds');
$foreachPos = strpos($fireBody, 'foreach');
$lastCatchPos = strrpos($fireBody, 'catch');
check(
    'the findByIds lookup sits inside the outer containment try/catch',
    $outerTryPos !== false && $findByIdsPos !== false && $foreachPos !== false && $lastCatchPos !== false
        && $outerTryPos < $findByIdsPos
        && $findByIdsPos < $foreachPos
        && $foreachPos < $lastCatchPos,
    'the pre-loop lookup must be within the outer guard, or a DB error on findByIds/repository would 500 the already-committed action'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
