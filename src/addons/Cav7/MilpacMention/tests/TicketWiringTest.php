<?php

/**
 * Issue #87 — pins the vendor-coupled wiring of the milpac-mention engine on the
 * NF/Tickets ticket-message surface (spec §2.1's fifth and last mention surface) so
 * a regression fails CI rather than shipping silently. It mirrors ReportWiringTest.php
 * (#86) and ProfilePostWiringTest.php (#85): the firing extension, the opt-out
 * extension, the distinct alert template and its alert-line phrase, the opt-out phrase,
 * and the depends_on_addon_id tag every alert must carry.
 *
 * Two things make the ticket surface its own test rather than a row in the others:
 *
 *  - SOFT DEPENDENCY. NF/Tickets is NOT a hard require (spec §1, §2.1). The addon
 *    installs and runs on sites without it, with the two ticket extensions inert (the
 *    from_class never loads, so XF never builds the XFCP proxy). This test PINS that
 *    posture where it is statically checkable: addon.json carries no NF/Tickets
 *    require. The CI gate itself runs with NF/Tickets ABSENT, so php -l + validate +
 *    consistency + package + these tests all passing IS the without-NF/Tickets proof.
 *
 *  - CONTAINMENT IS INLINE-ONLY, like the Report/ProfilePost surfaces — NOT Post.
 *    NF\Tickets\Service\Message\Notifier is dispatched via notifyAndEnqueue()
 *    (Service\Ticket\Creator/Replier), whose FIRST notify() pass runs INLINE in the
 *    member's request; milpac firing is stash-inline-only (a resumed XF\Job\Notifier
 *    loads a fresh Message with an empty stash and fires nothing), so it NEVER runs
 *    under the deferred-job net. So the firing extension carries an OUTER try/catch
 *    around the pre-loop lookups plus the recipient loop (mirroring
 *    XF\Service\Report\NotifierService), AND the per-recipient inner guard. This test
 *    pins that report-style layout.
 *
 * The firing rules themselves (self-skip, @-dedup, shared cap) are pure and run for
 * real in FiringRulesTest against the shared MilpacResolver every surface feeds
 * through the detection hook — this test pins only the parts that need a live
 * XenForo + NF/Tickets to actually run.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/TicketWiringTest.php
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
 * The source of one method's body, from its `function <name>` declaration up to the
 * next method's docblock/declaration or end-of-file. Keeps a check anchored to the
 * owning method (copied from WiringTest.php).
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
 * The source with every PHP comment removed — line comments and block comments
 * (including docblocks) — reconstructed through the PHP tokenizer, so a comment
 * marker inside a string literal is preserved. Anchoring an assertion here instead of
 * to the raw source stops a token that appears only in a comment from satisfying it:
 * the ticket NotifierService carries a deep-link comment that echoes both
 * 'nf_tickets_message' and $message->message_id verbatim (issue #86, Finding 1).
 */
function stripComments(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

$addon = json_decode((string) @file_get_contents("$root/addon.json"), true);
$versionId = is_array($addon) ? ($addon['version_id'] ?? null) : null;
check('addon.json is valid JSON with a positive version_id', is_int($versionId) && $versionId > 0);

// The soft-dependency posture, statically pinned: NF/Tickets is covered when present
// and must never become a hard require, or the addon would refuse to install on the
// four-core-surface sites without it (spec §1, §2.1).
check(
    'addon.json does NOT hard-require NF/Tickets (the ticket surface is a soft dependency)',
    is_array($addon) && !isset($addon['require']['NF/Tickets']),
    'a require would block install on sites without tickets; the surface must degrade to inert instead'
);

// =========================================================================
// class_extensions — the ONE ticket firing seam + the ONE ticket opt-out seam (spec §2.4/§3.1)
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
    // The ticket-message firing extension.
    'NF\Tickets\Service\Message\Notifier'
        => 'Cav7\MilpacMention\NF\Tickets\Service\Message\Notifier',
    // The opt-out registration on the ticket-message alert handler.
    'NF\Tickets\Alert\Message'
        => 'Cav7\MilpacMention\NF\Tickets\Alert\Message',
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
    '_output has one class_extensions file per _data extension',
    count(outputItems($root, 'class_extensions'))
        === ($classExtXml !== false ? count($classExtXml->extension) : -1)
);

// =========================================================================
// alert template — reuses the stock ticket handler, deep-links to the message (spec §3.2)
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
    'public template alert_nf_tickets_message_milpac_mention is declared',
    isset($templateBody['alert_nf_tickets_message_milpac_mention'])
        && ($templateType['alert_nf_tickets_message_milpac_mention'] ?? '') === 'public'
);
// The ticket alert deep-links to the ticket message and renders the milpac phrase —
// same shape as the stock alert_nf_tickets_message_mention, which links via
// link('tickets/messages', $content) with $content the Message entity.
check(
    'the ticket template deep-links to the ticket message and renders its phrase',
    str_contains($templateBody['alert_nf_tickets_message_milpac_mention'] ?? '', "link('tickets/messages', \$content)")
        && str_contains($templateBody['alert_nf_tickets_message_milpac_mention'] ?? '', 'cav7_mm_alert_nf_tickets_message_milpac_mention')
        && str_contains($templateBody['alert_nf_tickets_message_milpac_mention'] ?? '', 'username_link($user'),
    'the alert opens the ticket message in one click; {name} names the linker, {title} the ticket'
);
// {title} is the ticket title, one hop out from the Message via its Ticket relation. A
// wrong traversal ($content.title on the Message) renders an empty title, which the
// byte-for-byte compare below cannot catch (both _data and _output would agree on the
// wrong value). Pin the exact traversal the stock ticket mention alert uses.
check(
    'the ticket template traverses $content.Ticket.title for {title}',
    str_contains($templateBody['alert_nf_tickets_message_milpac_mention'] ?? '', '$content.Ticket.title'),
    'the ticket title hangs off the Ticket relation, one hop out from the Message entity'
);
check(
    'the ticket _output template body matches _data byte-for-byte (§3.2 verbatim)',
    ($templateBody['alert_nf_tickets_message_milpac_mention'] ?? '') !== ''
        && @file_get_contents("$root/_output/templates/public/alert_nf_tickets_message_milpac_mention.html")
            === ($templateBody['alert_nf_tickets_message_milpac_mention'] ?? null)
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
// phrases — the alert line AND the ticket opt-out label (spec §3.2)
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
    'the ticket alert-line phrase carries the exact §3.2 copy',
    ($phraseText['cav7_mm_alert_nf_tickets_message_milpac_mention'] ?? null)
        === '{name} linked your milpac in a message in the ticket {title}'
);
// Unlike reports, the ticket surface IS opt-out-able (spec §3.1): it carries its own
// opt-out row, so the opt-out phrase must exist with the exact §3.2 label.
check(
    'the ticket opt-out phrase alert_opt_out.nf_tickets_message_milpac_mention has the exact §3.2 label',
    ($phraseText['alert_opt_out.nf_tickets_message_milpac_mention'] ?? null)
        === 'Links your milpac in a ticket'
);

// _output byte-for-byte parity for both new phrases (copy ships verbatim, §3.2).
check(
    'the ticket alert-line _output file matches byte-for-byte',
    @file_get_contents("$root/_output/phrases/cav7_mm_alert_nf_tickets_message_milpac_mention.txt")
        === '{name} linked your milpac in a message in the ticket {title}'
);
check(
    'the ticket opt-out _output file matches byte-for-byte',
    @file_get_contents("$root/_output/phrases/alert_opt_out.nf_tickets_message_milpac_mention.txt")
        === 'Links your milpac in a ticket'
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
// the opt-out MERGE (never replace) on the ticket alert handler (spec §3.1)
// =========================================================================
$ticketAlertSrc = (string) @file_get_contents("$root/NF/Tickets/Alert/Message.php");
check('NF/Tickets Alert\\Message source exists', $ticketAlertSrc !== '');
check(
    'NF\\Tickets\\Alert\\Message array_merges milpac_mention onto parent::getOptOutActions (never replaces it)',
    (bool) preg_match(
        '/function\s+getOptOutActions\b.*?return\s+array_merge\(\s*parent::getOptOutActions\(\)\s*,\s*\[[^\]]*\'milpac_mention\'/s',
        $ticketAlertSrc
    ),
    "returning ['milpac_mention'] alone would silently drop the core ticket opt-outs (insert, quote, mention, reaction)"
);
check(
    'the ticket opt-out handler extends the XFCP proxy so it augments rather than shadows the vendor handler',
    (bool) preg_match('/class\s+Message\s+extends\s+XFCP_Message/', $ticketAlertSrc)
);

// =========================================================================
// the ticket firing extension (spec §2.4 / §2.5 / §2.6)
// =========================================================================
$src = (string) @file_get_contents("$root/NF/Tickets/Service/Message/Notifier.php");
check('Ticket Message Notifier source exists', $src !== '');

// Anchor the alert()-argument and sender assertions to the CODE, not the raw source:
// the notifier's comments spell out 'nf_tickets_message', $message->message_id and
// getAnonymousUser verbatim, so a bare str_contains($src, …) is false-green even when
// the real call is wrong. Strip comments first (issue #86, Finding 1).
$code = stripComments($src);

// The ticket notifier extends XF\Service\AbstractNotifier (like Post), so its parent
// notify() takes the optional $timeLimit — match it exactly, then fire. A mismatched
// signature would fatal when XF calls notify().
check(
    'notify() matches the parent notify($timeLimit) signature and fires after parent::notify($timeLimit)',
    (bool) preg_match('/function\s+notify\b.*?parent::notify\(\s*\$timeLimit\s*\).*?fireMilpacMentions\(/s', $code),
    'the ticket notifier fires through notify($timeLimit) (the AbstractNotifier shape, as Post), not a bespoke notify() (build-time check §8)'
);
// Same-instance invariant: the Message the Creator/Replier prepared (the preparer's
// getMessageEntity, stashed against) is the very $this->message the Notifier holds, so
// take() finds the stash. The stash key is the ticket message, not the ticket.
check(
    'firing reads the stashed recipients via the consuming MilpacStash::take($message)',
    str_contains($code, 'MilpacStash::take($message)'),
    'same-instance invariant: the notifier holds the very Message the detection hook prepared'
);
// The alert()'s arguments are the load-bearing wiring facts: sender attribution, the
// content type/id it deep-links to, and the action it raises. Pin them POSITIONALLY
// against the real ->alert(...) call in the comment-stripped code. A whole-source
// contains() for 'nf_tickets_message' / $message->message_id is false-green — the
// deep-link comment echoes both verbatim — and a positional match additionally catches
// a content-type<->action transposition and a misattributed sender. Arg slots:
// (1) recipient, (2) senderId $fromUser->user_id, (3) senderName $fromUser->username,
// (4) content type 'nf_tickets_message', (5) content id $message->message_id,
// (6) action 'milpac_mention'. The stock ticket mention alert keys on
// $message->message_id (the Message PK), so reusing NF\Tickets\Alert\Message resolves
// the deep-link to the right ticket message.
$alertPattern = '~->alert\(\s*'
    . '\$user\s*,\s*'                    // arg 1: recipient
    . '\$fromUser->user_id\s*,\s*'       // arg 2: senderId   (attribution — renders {name})
    . '\$fromUser->username\s*,\s*'      // arg 3: senderName (attribution — renders {name})
    . "'nf_tickets_message'" . '\s*,\s*' // arg 4: content type (reuses the stock ticket handler)
    . '\$message->message_id\s*,\s*'     // arg 5: content id   (the Message PK)
    . "'milpac_mention'" . '\s*,~s';     // arg 6: action
check(
    "the alert() wires sender \$fromUser->user_id/\$fromUser->username, content type "
        . "'nf_tickets_message', content id \$message->message_id and action 'milpac_mention' in their real slots",
    (bool) preg_match($alertPattern, $code),
    'anchored to the real ->alert(...) call (comment-stripped): a wrong content type, a wrong '
        . 'content id, a transposed content-type<->action, or a $message-based sender all FAIL here — '
        . 'a bare str_contains would pass on the deep-link comment alone (issue #86)'
);
// Sender attribution is the ticket surface's own wrinkle: tickets support anonymized
// authors, so the sender must be resolved through getAnonymousUser(true) under the
// recipient's own visitor — the exact call the stock NF\Tickets Message\Mention
// sendAlert makes — NOT read straight off $message->user_id/username as the other four
// surfaces do. Anchored to $code so the getAnonymousUser mentioned in comments cannot
// satisfy it.
check(
    'the sender is resolved via $message->getAnonymousUser(true) under asVisitor (anonymized-author parity)',
    (bool) preg_match('/\$fromUser\s*=\s*\\\\XF::asVisitor\(\s*\$user\s*,.*?\$message->getAnonymousUser\(\s*true\s*\)/s', $code),
    'a non-anonymized sender read off $message->user_id would leak the author of an anonymized ticket'
);
check(
    'self-links are suppressed at the firing edge ($user->user_id == $message->user_id) and the match continues',
    (bool) preg_match('/\$user->user_id\s*==\s*\$message->user_id\s*\)\s*\{\s*continue\s*;/s', $code),
    'the stock NF\\Tickets Mention::canNotify self-check does not run for the distinct action (rule §2.5.1); it gates on $message->user_id (the real author, not the anonymized sender) and skips via continue; anchored to $code, whitespace-tolerant'
);
// Dedup uses the AbstractNotifier shape ($this->alerted + setUserAsAlerted), as Post
// does — NOT the bespoke $this->usersAlerted the ProfilePost/Report surfaces use.
$dedupGuardPos = strpos($code, 'if (!empty($this->alerted[');
$dedupAlertPos = strpos($code, '->alert(');
check(
    'firing dedups against anyone the stock pass already alerted — the read guard precedes the alert() AND the write-back is conditioned on $sent (one alert per member)',
    $dedupGuardPos !== false
        && $dedupAlertPos !== false
        && $dedupGuardPos < $dedupAlertPos
        && (bool) preg_match('/if\s*\(\s*\$sent\s*\)\s*\{\s*\$this->setUserAsAlerted\(/s', $code),
    'the ticket notifier tracks alerted members in $this->alerted (the AbstractNotifier shape); pin that the read guard precedes the alert() and that setUserAsAlerted only records a member when $sent, so mere token presence cannot satisfy it (rules §2.5.2/5)'
);
// Gating parity (§2.6): the stock ticket notifier's canUserViewContent gates on
// \XF::asVisitor($user, fn() => $message->canView()); Message::canView() delegates to
// the Ticket's canView(), so a member who cannot view the ticket is filtered out. This
// is the visibility/PRIVACY gate — a rot here could alert members who cannot view an
// anonymized ticket — so pin it POSITIONALLY against the comment-stripped $code, NOT the
// raw $src. Notifier.php's own comment (~line 130) spells out both `asVisitor` and
// `$message->canView()` verbatim, so a bare str_contains($src, …) stays green even when
// the real gating block is deleted (a reviewer proved this by mutation; issue #87
// Finding 4). Anchor the RESOLVE — $canView = \XF::asVisitor($user, … $message->canView())
// — AND the `if (!$canView) continue;` SKIP, so both "resolves canView" and "actually
// gates on it" must be present.
check(
    'gating parity — the recipient must be able to view the ticket message: $canView = asVisitor($user, … $message->canView()), enforced by if (!$canView) continue',
    (bool) preg_match('/\$canView\s*=\s*\\\\XF::asVisitor\(\s*\$user\s*,.*?\$message->canView\(\s*\)/s', $code)
        && (bool) preg_match('/if\s*\(\s*!\s*\$canView\s*\)\s*\{\s*continue\s*;/s', $code),
    'anchored to comment-stripped $code and positional: deleting the real asVisitor/$message->canView() resolve OR its !$canView skip FAILS here — a bare str_contains($src) passes on the gating comment alone (issue #87, Finding 4)'
);

// Every alert() call carries depends_on_addon_id so uninstall clears alerts. Counted on
// the comment-stripped $code so a commented-out ->alert( example cannot skew the tally.
$alertCalls = preg_match_all('/->alert\(/', $code);
$dependsTags = preg_match_all("/'depends_on_addon_id'\s*=>\s*'Cav7\/MilpacMention'/", $code);
check(
    "every alert() call passes depends_on_addon_id => 'Cav7/MilpacMention'",
    $alertCalls > 0 && $alertCalls === $dependsTags,
    "alert() calls=$alertCalls tagged=$dependsTags — an untagged alert survives uninstall"
);

// Issue #95 — autoRead=false keeps the milpac alert unread when it is only surfaced in the
// alerts dropdown/list; it clears when the recipient views the linked content or explicitly
// reads the alert, exactly as XF writes its own mention alerts (the ticket surface inherits
// ['autoRead' => false] from XF\Notifier\AbstractNotifier). insertAlert() reads autoRead out
// of the $options array and defaults it to true when the array omits it, so a milpac_mention
// row without the flag saves auto_read=1 and is auto-marked read the moment it shows in the
// dropdown — a different schedule than the @-mention it mirrors.
// depends_on_addon_id lives in the $extra array; the two are separate slots. Pin the tail
// of the real ->alert(...) call POSITIONALLY on the comment-stripped $code: action, then
// the extra array carrying depends_on_addon_id, then ['autoRead' => false] as the last
// argument. Folding autoRead into the extra array, dropping it, or reordering the slots
// all FAIL here.
check(
    "the alert() passes ['autoRead' => false] as its \$options array, separate from the \$extra array carrying depends_on_addon_id, so the milpac alert clears like the @-mention it mirrors",
    (bool) preg_match(
        "~'milpac_mention'\s*,\s*\['depends_on_addon_id'\s*=>\s*'Cav7/MilpacMention'\]\s*,\s*\['autoRead'\s*=>\s*false\]\s*\)~",
        $code
    ),
    'a milpac_mention row written auto-read (auto_read=1) clears differently from the @-mention it tracks; XF passes autoRead=false, so mirror it'
);

// Containment IS INLINE-ONLY (the report-style layout, NOT Post). The ticket notifier
// is dispatched via notifyAndEnqueue() (Service\Ticket\Creator/Replier), whose FIRST
// notify() pass runs INLINE; milpac firing is stash-inline-only (a resumed job loads a
// fresh Message with an empty stash and fires nothing), so it never rides
// XF\Job\Notifier's net. So fireMilpacMentions carries an OUTER catch that forwards $e
// to logException($e, false, …) around the pre-loop lookups AND the loop, plus the
// per-recipient inner catch — and never rethrows. Anchored to the comment-stripped body
// ($fireCode) so a prose "throw"/"try" in a comment cannot satisfy or defeat it.
$fireCode = methodBody($code, 'fireMilpacMentions');
check(
    'firing is contained — fireMilpacMentions catches and forwards $e to logException($e, false, …), never rethrowing',
    $fireCode !== ''
        && (bool) preg_match('/catch\s*\(.*?logException\(\s*\$e,\s*false/s', $fireCode)
        && str_contains($fireCode, '[Cav7/MilpacMention] firing failed')
        && !str_contains($fireCode, 'throw'),
    'the ticket message is already saved+committed; an uncontained alert()/canView() failure would surface on the reply action'
);
// Report-style layout, positively pinned (couples with the outer guard added in issue
// #87 Finding 1): the OUTER try opens BEFORE the findByIds/repository lookups,
// findByIds precedes the recipient loop, and the outer catch (\Throwable) forwarding to
// logException follows the loop — so a transient DB fault in the pre-loop lookup is
// contained instead of 500-ing an already-committed ticket action. The per-recipient
// inner catch is still present (pinned above). This mirrors ReportWiringTest's
// containment pins and is the OPPOSITE of the old Post-style "no outer guard" layout.
// If a future edit removes the outer guard (or moves the lookup outside it), the
// ordering below FAILS. Anchored to the comment-stripped body so a prose "try" cannot
// register as a real one (issue #86/#87).
$outerTryPos = strpos($fireCode, 'try');
$findByIdsPos = strpos($fireCode, 'findByIds');
$foreachPos = strpos($fireCode, 'foreach');
$outerCatchPattern = '/catch\s*\(\s*\\\\Throwable\b.*?logException\(\s*\$e,\s*false/s';
$lastCatchPos = strrpos($fireCode, 'catch');
check(
    'containment is report-style: the outer try opens BEFORE findByIds, findByIds precedes the loop, and the outer catch(\\Throwable)->logException follows the loop',
    $outerTryPos !== false && $findByIdsPos !== false && $foreachPos !== false && $lastCatchPos !== false
        && $outerTryPos < $findByIdsPos   // the outer guard opens before the pre-loop lookup
        && $findByIdsPos < $foreachPos    // the lookup precedes the recipient loop
        && $foreachPos < $lastCatchPos    // the outer catch closes after the loop
        && (bool) preg_match($outerCatchPattern, $fireCode),
    'the ticket surface fires inline-only (a resumed job\'s stash is empty), so it must contain the pre-loop findByIds/repository lookups in an outer guard like Report — not the old Post-style no-outer-guard layout; removing the outer guard FAILS this'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
