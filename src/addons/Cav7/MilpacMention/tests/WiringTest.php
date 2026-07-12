<?php

/**
 * Issue #84 — pins the vendor-coupled wiring of the milpac-mention engine so a
 * regression fails CI rather than shipping silently. The pure logic (detection
 * regex, reverse-resolution shape, firing rules) is exercised for real in
 * DetectionTest / ResolverTest / FiringRulesTest; this holds the parts that need a
 * live XenForo + NF/Rosters to actually run: the three class extensions, the
 * distinct alert's template and phrases, the opt-out merge, and the
 * depends_on_addon_id tag every alert must carry.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/WiringTest.php
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

/** All _output template files across their style-type folders, minus _metadata.json. */
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
 * the next method's docblock/declaration or end-of-file. Keeps a check anchored to
 * the owning method.
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

// =========================================================================
// addon.json — identity and dependencies (spec §1)
// =========================================================================
$addon = json_decode((string) @file_get_contents("$root/addon.json"), true);
check('addon.json is valid JSON', is_array($addon));
// Read the version_id once (issue #84 L). The phrase and template version_id
// attributes are cross-checked against this single source below, so a release bump
// touches only addon.json plus a re-export — never a hardcoded literal in this test.
$versionId = is_array($addon) ? ($addon['version_id'] ?? null) : null;
if (is_array($addon)) {
    check('title is "7Cav - Milpac Mention"', ($addon['title'] ?? '') === '7Cav - Milpac Mention');
    check(
        'version_id is a positive integer (the single source the data items are pinned to)',
        is_int($versionId) && $versionId > 0
    );
    check('version_string is 1.0.0', ($addon['version_string'] ?? '') === '1.0.0');
    check(
        'requires XF 2.3.0+ (2030070)',
        (int) ($addon['require']['XF'][0] ?? 0) === 2030070
    );
    check(
        'hard-requires NF/Rosters 2.1+ (2010000)',
        (int) ($addon['require']['NF/Rosters'][0] ?? 0) === 2010000,
        'the reverse resolver runs against NF\\Rosters:RosterUser'
    );
    check(
        'does NOT require NF/Tickets (that surface is a later ticket)',
        !isset($addon['require']['NF/Tickets'])
    );
    foreach (['addon_id', 'namespace', 'setup'] as $derived) {
        check(
            "addon.json omits the XenForo-derived key '$derived'",
            !array_key_exists($derived, $addon)
        );
    }
}

// A class-extension-only addon ships no Setup.php (spec §1).
check(
    'no Setup.php (class-extension-only addon)',
    !is_file("$root/Setup.php"),
    'this addon creates no tables/options/fields; a Setup.php would be dead code'
);

// =========================================================================
// class_extensions — the three seams the engine hangs on (spec §2)
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

$expectedExtensions = [
    // The shared detection hook — one class every mention surface runs through.
    'XF\Service\Message\PreparerService' => 'Cav7\MilpacMention\XF\Service\Message\PreparerService',
    // The Post firing extension.
    'XF\Service\Post\NotifierService' => 'Cav7\MilpacMention\XF\Service\Post\NotifierService',
    // The opt-out registration on the post alert handler.
    'XF\Alert\PostHandler' => 'Cav7\MilpacMention\XF\Alert\PostHandler',
];
foreach ($expectedExtensions as $from => $to) {
    check(
        "$from is extended to $to and active",
        isset($extByFrom[$from])
            && $extByFrom[$from]['to'] === $to
            && $extByFrom[$from]['active'] === '1'
    );
}
check(
    'the seven engine extensions are registered (post + profile-post + profile-post-comment + report surfaces)',
    ($classExtXml !== false ? count($classExtXml->extension) : -1) === 7,
    'shared PreparerService + Post/ProfilePost/ProfilePostComment/Report notifiers + Post/ProfilePost opt-out handlers; the ticket surface is a later ticket. ProfilePostWiringTest pins the profile-post surfaces and ReportWiringTest pins the report surface'
);
check(
    '_output has one class_extensions file per _data extension',
    count(outputItems($root, 'class_extensions')) === ($classExtXml !== false ? count($classExtXml->extension) : -1)
);

// =========================================================================
// the alert template — reuses the stock post handler, deep-links, renders phrase
// =========================================================================
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
        if ($title === 'alert_post_milpac_mention') {
            $alertTemplateBody = (string) $tpl;
        }
    }
}
check(
    'the public template alert_post_milpac_mention is declared',
    in_array('alert_post_milpac_mention', $templateTitles, true)
        && ($templateTypeByTitle['alert_post_milpac_mention'] ?? '') === 'public',
    'XF renders public:alert_post_milpac_mention for content type post + action milpac_mention'
);
check(
    'the alert template deep-links to the post and renders the milpac phrase',
    str_contains($alertTemplateBody, "link('posts', \$content)")
        && str_contains($alertTemplateBody, 'cav7_mm_alert_post_milpac_mention'),
    'the alert must open the post in one click; the alert line is a phrase, not inline text'
);
check(
    'the alert line names the linker via username_link($user), mirroring alert_post_mention',
    str_contains($alertTemplateBody, 'username_link($user'),
    '{name} in the alert line is the member who linked the milpac'
);
check(
    'the _output template ships under the public style folder',
    is_file("$root/_output/templates/public/alert_post_milpac_mention.html")
);
// Issue #84 H — the alert copy ships verbatim per spec §3.2, so pin the _output
// body against the _data body byte-for-byte rather than merely checking the file
// exists. check-data-consistency only count-checks templates, so an _output body
// that drifts from _data would otherwise slip through CI.
check(
    'the _output template body matches the _data/templates.xml body byte-for-byte',
    $alertTemplateBody !== ''
        && @file_get_contents("$root/_output/templates/public/alert_post_milpac_mention.html") === $alertTemplateBody,
    'the _output template must not drift from its _data source (§3.2 verbatim)'
);
// Issue #84 L — every template's version_id attribute tracks addon.json's, read
// once above. A template pinned to a stale version_id would drift from the add-on
// on a release bump.
$templateVersionsMatch = $templatesXml !== false && $versionId !== null;
if ($templatesXml !== false) {
    foreach ($templatesXml->template as $tpl) {
        if ((int) $tpl['version_id'] !== (int) $versionId) {
            $templateVersionsMatch = false;
        }
    }
}
check(
    'every _data template carries version_id === addon.json version_id',
    $templateVersionsMatch,
    'a template version_id that drifts from addon.json must fail CI, robustly across release bumps'
);
check(
    '_output has one template file per _data template',
    count(outputTemplateItems($root)) === ($templatesXml !== false ? count($templatesXml->template) : -1)
);

// =========================================================================
// phrases — the exact opt-out label and the alert line (spec §3.2, ships verbatim)
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
    'the opt-out phrase alert_opt_out.post_milpac_mention has the exact §3.2 label',
    ($phraseText['alert_opt_out.post_milpac_mention'] ?? null) === 'Links your milpac in a message',
    'copy ships verbatim; getOptOutsMap labels the toggle with this phrase'
);
check(
    'the alert-line phrase carries the exact §3.2 copy',
    ($phraseText['cav7_mm_alert_post_milpac_mention'] ?? null)
        === '{name} linked your milpac in a post in the thread {title}',
    'copy ships verbatim, {name}/{title} filled by the template'
);
check(
    '_output has one phrases file per _data phrase',
    count(outputItems($root, 'phrases')) === ($phraseXml !== false ? count($phraseXml->phrase) : -1)
);
check(
    'the _output phrase file matches the opt-out label byte-for-byte',
    @file_get_contents("$root/_output/phrases/alert_opt_out.post_milpac_mention.txt") === 'Links your milpac in a message'
);
// Issue #84 H — the alert-line phrase also ships verbatim (§3.2), so pin its
// _output file byte-for-byte too, not just the opt-out label.
check(
    'the _output alert-line phrase file matches the §3.2 copy byte-for-byte',
    @file_get_contents("$root/_output/phrases/cav7_mm_alert_post_milpac_mention.txt")
        === '{name} linked your milpac in a post in the thread {title}',
    'the alert line ships verbatim; an _output drift from the §3.2 copy must fail CI'
);
// Issue #84 L — every phrase's version_id attribute tracks addon.json's version_id
// (read once above), so a release bump needs no test edit and a stale version_id
// fails CI.
$phraseVersionsMatch = $phraseXml !== false && $versionId !== null;
if ($phraseXml !== false) {
    foreach ($phraseXml->phrase as $phrase) {
        if ((int) $phrase['version_id'] !== (int) $versionId) {
            $phraseVersionsMatch = false;
        }
    }
}
check(
    'every _data phrase carries version_id === addon.json version_id',
    $phraseVersionsMatch,
    'a phrase version_id that drifts from addon.json must fail CI, robustly across release bumps'
);

// =========================================================================
// the opt-out MERGE (never replace) on the post alert handler (spec §3.1)
// =========================================================================
$postHandlerSrc = (string) @file_get_contents("$root/XF/Alert/PostHandler.php");
check(
    'PostHandler array_merges milpac_mention onto parent::getOptOutActions (never replaces it)',
    (bool) preg_match(
        '/function\s+getOptOutActions\b.*?return\s+array_merge\(\s*parent::getOptOutActions\(\)\s*,\s*\[[^\]]*\'milpac_mention\'/s',
        $postHandlerSrc
    ),
    "returning ['milpac_mention'] alone would silently drop every other core post opt-out (mention, quote, reaction, ...)"
);
check(
    'PostHandler extends the XFCP proxy so it augments rather than shadows the core handler',
    (bool) preg_match('/class\s+PostHandler\s+extends\s+XFCP_PostHandler/', $postHandlerSrc)
);

// =========================================================================
// the shared detection hook (spec §2.2)
// =========================================================================
$preparerSrc = (string) @file_get_contents("$root/XF/Service/Message/PreparerService.php");
check(
    'the detection hook matches the installed prepare($message, $checkValidity = true) signature',
    (bool) preg_match('/function\s+prepare\(\s*\$message\s*,\s*\$checkValidity\s*=\s*true\s*\)/', $preparerSrc),
    'a mismatched signature would fatal when XF calls prepare() (build-time check §8.2)'
);
check(
    'the hook runs AFTER the parent so the message is fully processed and mentions are known',
    (bool) preg_match('/\$message\s*=\s*parent::prepare\(\s*\$message\s*,\s*\$checkValidity\s*\)\s*;/', $preparerSrc)
);
check(
    'the hook extracts relation_ids and resolves them through the shared MilpacResolver',
    str_contains($preparerSrc, 'MilpacResolver::extractRelationIds')
        && str_contains($preparerSrc, 'MilpacResolver::resolveUserIds')
);
check(
    'the hook applies the shared author cap via getAllowedUserMentions-equivalent budget',
    str_contains($preparerSrc, 'MilpacResolver::milpacRecipients')
        && str_contains($preparerSrc, "hasPermission('general', 'maxMentionedUsers')"),
    'milpac links must count against the same maxMentionedUsers budget as @-mentions (rule §2.5.6)'
);
check(
    'the surviving recipients are stashed for the firing layer to read',
    str_contains($preparerSrc, 'MilpacStash::stash'),
    'detection and firing are different objects; the stash is the hand-off'
);
// Issue #84 — the resolving/stashing body runs a live NF\Rosters:RosterUser
// finder, a permission read, and entity-relation access on the save path of every
// mention surface. A vendor schema drift or a transient DB error must not abort the
// member's post: the body is wrapped in a \Throwable catch that forwards $e to
// logException($e, false, …) and lets the save proceed without a milpac alert,
// mirroring RosterPatch / EnlistmentReminder. Anchored to stashMilpacMentions's own
// body (see methodBody) so a catch elsewhere can't satisfy it.
$stashBody = methodBody($preparerSrc, 'stashMilpacMentions');
check(
    'detection is contained: stashMilpacMentions catches and forwards $e to logException($e, false, …), never rethrowing',
    $stashBody !== ''
        && (bool) preg_match('/catch\s*\(.*?logException\(\s*\$e,\s*false/s', $stashBody)
        && str_contains($stashBody, '[Cav7/MilpacMention] detection failed')
        && !str_contains($stashBody, 'throw'),
    'an uncontained finder/permission/relation failure on the shared save path would abort the member\'s whole post'
);

// =========================================================================
// the Post firing extension (spec §2.4 / §2.5)
// =========================================================================
$notifierSrc = (string) @file_get_contents("$root/XF/Service/Post/NotifierService.php");
check(
    'firing runs after the stock notifier pass (parent::notify then fire)',
    (bool) preg_match('/function\s+notify\b.*?parent::notify\(\s*\$timeLimit\s*\).*?fireMilpacMentions\(/s', $notifierSrc)
);
check(
    'firing reads the stashed recipients (consuming take, so a resumed job never refires)',
    str_contains($notifierSrc, 'MilpacStash::take'),
    'no edit/job refire falls out of the consuming stash (rule §2.5.4)'
);
check(
    "the alert raises action 'milpac_mention' on content type 'post', reusing the stock handler",
    str_contains($notifierSrc, "'post'") && str_contains($notifierSrc, "'milpac_mention'")
);
check(
    'self-links are suppressed at the firing edge (recipient != author)',
    (bool) preg_match('/\$user->user_id\s*==\s*\$post->user_id/', $notifierSrc),
    'the core Mention::canNotify self-check does not run for the distinct action (rule §2.5.1)'
);
check(
    'firing dedups against anyone the stock pass already alerted — reads the guard AND records the send (one alert per member)',
    str_contains($notifierSrc, 'if (!empty($this->alerted[')
        && str_contains($notifierSrc, 'setUserAsAlerted('),
    'XF alerts a member once across a post\'s notifiers; pin both the read guard and the write-back (via setUserAsAlerted) so mere array presence cannot satisfy it (rules §2.5.2/5)'
);
check(
    'gating parity: the recipient must be able to view the post (asVisitor canView)',
    str_contains($notifierSrc, 'asVisitor') && str_contains($notifierSrc, '$post->canView()'),
    'a member who cannot see the post is filtered out, same as the stock notifier (§2.6)'
);

// Every alert() call carries depends_on_addon_id so uninstall clears outstanding
// alerts. Pin both that the tag is present AND that no alert() call is missing it.
$alertCalls = preg_match_all('/->alert\(/', $notifierSrc);
$dependsTags = preg_match_all("/'depends_on_addon_id'\s*=>\s*'Cav7\/MilpacMention'/", $notifierSrc);
check(
    "every alert() call passes depends_on_addon_id => 'Cav7/MilpacMention'",
    $alertCalls > 0 && $alertCalls === $dependsTags,
    "alert() calls=$alertCalls tagged=$dependsTags — an untagged alert survives uninstall"
);
// Issue #84 — firing runs inline after the post has already saved+committed, so an
// alert()/canView() failure must not surface on the member's reply action. The
// firing loop is contained: a \Throwable is caught, forwarded to
// logException($e, false, …), and the loop continues to the next recipient,
// matching EnlistmentReminder\QueueReminder::alertClerks. Anchored to
// fireMilpacMentions's own body (see methodBody) so a catch elsewhere can't
// satisfy it.
$fireBody = methodBody($notifierSrc, 'fireMilpacMentions');
check(
    'firing is contained: fireMilpacMentions catches and forwards $e to logException($e, false, …), never rethrowing',
    $fireBody !== ''
        && (bool) preg_match('/catch\s*\(.*?logException\(\s*\$e,\s*false/s', $fireBody)
        && str_contains($fireBody, '[Cav7/MilpacMention] firing failed')
        && !str_contains($fireBody, 'throw'),
    'the post is already saved+committed; an uncontained alert()/canView() failure would surface on the reply action'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
