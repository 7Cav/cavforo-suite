<?php

/**
 * Issue #85 — pins the vendor-coupled wiring of the milpac-mention engine on the
 * two profile-post surfaces (profile post + profile-post comment) so a regression
 * fails CI rather than shipping silently. It mirrors WiringTest.php (the post
 * surface, #84): the two firing extensions and the one opt-out extension, the two
 * distinct alert templates and their alert-line phrases, the single opt-out phrase
 * that governs BOTH surfaces, and the depends_on_addon_id tag every alert must
 * carry.
 *
 * The firing rules themselves (self-skip, @-dedup, shared cap) are pure and run
 * for real in FiringRulesTest against the shared MilpacResolver both surfaces feed
 * through the detection hook — this test pins only the parts that need a live
 * XenForo to actually run.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ProfilePostWiringTest.php
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
// class_extensions — the two firing seams + the one opt-out seam (spec §2.4/§3.1)
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
    // The profile-post firing extension.
    'XF\Service\ProfilePost\NotifierService'
        => 'Cav7\MilpacMention\XF\Service\ProfilePost\NotifierService',
    // The profile-post-comment firing extension.
    'XF\Service\ProfilePostComment\NotifierService'
        => 'Cav7\MilpacMention\XF\Service\ProfilePostComment\NotifierService',
    // The opt-out registration on the profile-post alert handler — this ONE row
    // governs both profile posts and their comments (mirrors XF's own mention family).
    'XF\Alert\ProfilePostHandler'
        => 'Cav7\MilpacMention\XF\Alert\ProfilePostHandler',
];
foreach ($expectedExtensions as $from => $to) {
    check(
        "$from is extended to $to and active",
        isset($extByFrom[$from])
            && $extByFrom[$from]['to'] === $to
            && $extByFrom[$from]['active'] === '1'
    );
}

// The one-row rule (spec §3.1, build-time check §8.5): the comment surface must
// NOT carry its own opt-out override. XF registers the mention opt-out only on
// XF\Alert\ProfilePostHandler and omits it on XF\Alert\ProfilePostCommentHandler;
// milpac_mention mirrors that exactly.
check(
    'no opt-out extension is registered on XF\\Alert\\ProfilePostCommentHandler (one row covers both)',
    !isset($extByFrom['XF\Alert\ProfilePostCommentHandler']),
    'the single profile_post opt-out row governs comment alerts too; a comment override would be a fourth row XF does not have'
);
check(
    'no ProfilePostCommentHandler.php source file exists (do not add a comment opt-out override)',
    !is_file("$root/XF/Alert/ProfilePostCommentHandler.php")
);

check(
    '_output has one class_extensions file per _data extension',
    count(outputItems($root, 'class_extensions'))
        === ($classExtXml !== false ? count($classExtXml->extension) : -1)
);

// =========================================================================
// alert templates — one per surface, reusing the stock handler, deep-linking (spec §3.2)
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

// profile_post surface: deep-links to the profile post, renders the milpac phrase.
check(
    'public template alert_profile_post_milpac_mention is declared',
    isset($templateBody['alert_profile_post_milpac_mention'])
        && ($templateType['alert_profile_post_milpac_mention'] ?? '') === 'public'
);
check(
    'the profile_post template deep-links to the profile post and renders its phrase',
    str_contains($templateBody['alert_profile_post_milpac_mention'] ?? '', "link('profile-posts', \$content)")
        && str_contains($templateBody['alert_profile_post_milpac_mention'] ?? '', 'cav7_mm_alert_profile_post_milpac_mention')
        && str_contains($templateBody['alert_profile_post_milpac_mention'] ?? '', 'username_link($user'),
    'the alert opens the profile post in one click; {name} names the linker, {profile} the profile owner'
);
check(
    'the profile_post _output template body matches _data byte-for-byte (§3.2 verbatim)',
    ($templateBody['alert_profile_post_milpac_mention'] ?? '') !== ''
        && @file_get_contents("$root/_output/templates/public/alert_profile_post_milpac_mention.html")
            === ($templateBody['alert_profile_post_milpac_mention'] ?? null)
);

// profile_post_comment surface: deep-links to the comment, renders its own phrase.
check(
    'public template alert_profile_post_comment_milpac_mention is declared',
    isset($templateBody['alert_profile_post_comment_milpac_mention'])
        && ($templateType['alert_profile_post_comment_milpac_mention'] ?? '') === 'public'
);
check(
    'the comment template deep-links to the comment and renders its phrase',
    str_contains($templateBody['alert_profile_post_comment_milpac_mention'] ?? '', "link('profile-posts/comments', \$content)")
        && str_contains($templateBody['alert_profile_post_comment_milpac_mention'] ?? '', 'cav7_mm_alert_profile_post_comment_milpac_mention')
        && str_contains($templateBody['alert_profile_post_comment_milpac_mention'] ?? '', 'username_link($user'),
    'the alert opens the comment in one click (spec §2.4)'
);
check(
    'the comment _output template body matches _data byte-for-byte (§3.2 verbatim)',
    ($templateBody['alert_profile_post_comment_milpac_mention'] ?? '') !== ''
        && @file_get_contents("$root/_output/templates/public/alert_profile_post_comment_milpac_mention.html")
            === ($templateBody['alert_profile_post_comment_milpac_mention'] ?? null)
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
// phrases — two alert lines + ONE opt-out label covering both surfaces (spec §3.2)
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
    'the profile_post alert-line phrase carries the exact §3.2 copy',
    ($phraseText['cav7_mm_alert_profile_post_milpac_mention'] ?? null)
        === "{name} linked your milpac in a message on {profile}'s profile"
);
check(
    'the comment alert-line phrase carries the exact §3.2 copy',
    ($phraseText['cav7_mm_alert_profile_post_comment_milpac_mention'] ?? null)
        === "{name} linked your milpac in a comment on {profile}'s profile"
);
// ONE opt-out phrase for the profile_post content type — governs both surfaces.
// There is deliberately NO alert_opt_out.profile_post_comment_milpac_mention.
check(
    'the single opt-out phrase alert_opt_out.profile_post_milpac_mention has the exact §3.2 label',
    ($phraseText['alert_opt_out.profile_post_milpac_mention'] ?? null)
        === 'Links your milpac in a profile post or comment'
);
check(
    'there is NO separate comment opt-out phrase (one row covers both surfaces)',
    !isset($phraseText['alert_opt_out.profile_post_comment_milpac_mention'])
);

// _output byte-for-byte parity for each new phrase (copy ships verbatim, §3.2).
check(
    'the profile_post alert-line _output file matches byte-for-byte',
    @file_get_contents("$root/_output/phrases/cav7_mm_alert_profile_post_milpac_mention.txt")
        === "{name} linked your milpac in a message on {profile}'s profile"
);
check(
    'the comment alert-line _output file matches byte-for-byte',
    @file_get_contents("$root/_output/phrases/cav7_mm_alert_profile_post_comment_milpac_mention.txt")
        === "{name} linked your milpac in a comment on {profile}'s profile"
);
check(
    'the opt-out _output file matches byte-for-byte',
    @file_get_contents("$root/_output/phrases/alert_opt_out.profile_post_milpac_mention.txt")
        === 'Links your milpac in a profile post or comment'
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
// the opt-out MERGE (never replace) on the profile-post alert handler (spec §3.1)
// =========================================================================
$ppHandlerSrc = (string) @file_get_contents("$root/XF/Alert/ProfilePostHandler.php");
check(
    'ProfilePostHandler array_merges milpac_mention onto parent::getOptOutActions (never replaces it)',
    (bool) preg_match(
        '/function\s+getOptOutActions\b.*?return\s+array_merge\(\s*parent::getOptOutActions\(\)\s*,\s*\[[^\]]*\'milpac_mention\'/s',
        $ppHandlerSrc
    ),
    "returning ['milpac_mention'] alone would silently drop insert/mention/reaction"
);
check(
    'ProfilePostHandler extends the XFCP proxy so it augments rather than shadows the core handler',
    (bool) preg_match('/class\s+ProfilePostHandler\s+extends\s+XFCP_ProfilePostHandler/', $ppHandlerSrc)
);

// =========================================================================
// the two firing extensions (spec §2.4 / §2.5 / §2.6)
// =========================================================================
$surfaces = [
    'ProfilePost' => [
        'src' => "$root/XF/Service/ProfilePost/NotifierService.php",
        'entity' => 'profilePost',
        'contentType' => 'profile_post',
        'canViewVar' => '$profilePost->canView()',
        'selfSkip' => '$user->user_id == $profilePost->user_id',
    ],
    'ProfilePostComment' => [
        'src' => "$root/XF/Service/ProfilePostComment/NotifierService.php",
        'entity' => 'comment',
        'contentType' => 'profile_post_comment',
        'canViewVar' => '$comment->canView()',
        'selfSkip' => '$user->user_id == $comment->user_id',
    ],
];

foreach ($surfaces as $name => $s) {
    $src = (string) @file_get_contents($s['src']);
    check("$name: NotifierService source exists", $src !== '');

    // The parent notify() takes NO args (bespoke notify(), not the Post
    // loadNotifiers/$timeLimit shape) — match it exactly, then fire.
    check(
        "$name: notify() matches the parent no-arg signature and fires after parent::notify()",
        (bool) preg_match('/function\s+notify\s*\(\s*\).*?parent::notify\(\s*\).*?fireMilpacMentions\(/s', $src),
        'a mismatched signature would fatal when XF calls notify() (build-time check §8)'
    );
    check(
        "$name: firing reads the stashed recipients via the consuming MilpacStash::take(\$$s[entity])",
        str_contains($src, 'MilpacStash::take($' . $s['entity'] . ')'),
        'same-instance invariant: the notifier holds the very entity the detection hook prepared'
    );
    check(
        "$name: raises action 'milpac_mention' on content type '$s[contentType]', reusing the stock handler",
        str_contains($src, "'" . $s['contentType'] . "'") && str_contains($src, "'milpac_mention'")
    );
    check(
        "$name: self-links are suppressed at the firing edge ($s[selfSkip])",
        str_contains($src, $s['selfSkip']),
        'the core mention self-check does not run for the distinct action (rule §2.5.1)'
    );
    check(
        "$name: firing dedups against anyone already alerted (usersAlerted, one alert per member)",
        str_contains($src, '$this->usersAlerted['),
        'the bespoke notifier dedups via $this->usersAlerted, not the Post loadNotifiers $alerted (rules §2.5.2/5)'
    );
    check(
        "$name: gating parity — the recipient must be able to view the content (asVisitor canView)",
        str_contains($src, 'asVisitor') && str_contains($src, $s['canViewVar']),
        'a member who cannot see the content is filtered out, same as the stock notifier (§2.6)'
    );

    // Every alert() call carries depends_on_addon_id so uninstall clears alerts.
    $alertCalls = preg_match_all('/->alert\(/', $src);
    $dependsTags = preg_match_all("/'depends_on_addon_id'\s*=>\s*'Cav7\/MilpacMention'/", $src);
    check(
        "$name: every alert() call passes depends_on_addon_id => 'Cav7/MilpacMention'",
        $alertCalls > 0 && $alertCalls === $dependsTags,
        "alert() calls=$alertCalls tagged=$dependsTags — an untagged alert survives uninstall"
    );

    // Firing runs inline after the content saved+committed, so a failure must be
    // contained, logged, and skipped per-recipient (never surfaced on the action).
    $fireBody = methodBody($src, 'fireMilpacMentions');
    check(
        "$name: firing is contained — fireMilpacMentions catches and forwards \$e to logException(\$e, false, …), never rethrowing",
        $fireBody !== ''
            && (bool) preg_match('/catch\s*\(.*?logException\(\s*\$e,\s*false/s', $fireBody)
            && str_contains($fireBody, '[Cav7/MilpacMention] firing failed')
            && !str_contains($fireBody, 'throw'),
        'the content is already saved+committed; an uncontained alert()/canView() failure would surface on the member\'s action'
    );
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
