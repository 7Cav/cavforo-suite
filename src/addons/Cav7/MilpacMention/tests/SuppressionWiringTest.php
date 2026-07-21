<?php

/**
 * Issue #147 — pins the wiring of the suppressed-area gate, which is the part the
 * pure SuppressionRulesTest cannot reach: which surfaces consult the predicate,
 * which id each one hands it, which option each one reads, and which surfaces are
 * deliberately left alone.
 *
 * XenForo's class extension system forces one class per surface, so every notifier
 * carries its own copy of the firing-rule pattern and a gate has to be repeated in
 * each relevant one. That repetition is exactly what rots silently, so it is pinned
 * here per surface rather than assumed from one shared place.
 *
 * Only two surfaces gate. Posts sit in a forum node and ticket messages sit in a
 * ticket category, and those are the two places the deny-lists name. Profile posts,
 * profile-post comments and report comments have no such place and are untouched by
 * both options — pinned negatively below, because "we forgot to gate it" and "we
 * decided not to gate it" look identical in a diff otherwise.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/SuppressionWiringTest.php
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

/**
 * The source with every PHP comment removed, reconstructed through the PHP
 * tokenizer so a comment marker inside a string literal survives. Every assertion
 * below is anchored to comment-stripped code: these notifiers document the gate at
 * length in prose, so a bare str_contains on the raw source would stay green with
 * the real gate deleted (the mutation that caught out issues #86 and #87).
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

/**
 * The source of one method's body, from its `function <name>` declaration up to the
 * next method's docblock/declaration or end-of-file (copied from WiringTest.php).
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
// the two gating surfaces
// =========================================================================

/**
 * The surfaces that gate, each with the option it reads and the area id it hands
 * the predicate. The area expression is the load-bearing half: a gate that reads
 * the right option but asks about the wrong id suppresses nothing (or everything),
 * and both failures are invisible without NF/Tickets or a live forum.
 */
$gatedSurfaces = [
    'XF/Service/Post/NotifierService.php' => [
        'option' => 'cav7MMSuppressedNodeIds',
        // A post's place is its thread's forum node, one hop out via the Thread relation.
        'area' => '$post->Thread',
        'areaColumn' => 'node_id',
    ],
    'NF/Tickets/Service/Message/Notifier.php' => [
        'option' => 'cav7MMSuppressedTicketCategoryIds',
        // A ticket message's place is its ticket's category, one hop out via Ticket.
        'area' => '$message->Ticket',
        'areaColumn' => 'ticket_category_id',
    ],
];

foreach ($gatedSurfaces as $file => $expected) {
    $src = (string) @file_get_contents("$root/$file");
    check("$file source exists", $src !== '');

    $code = stripComments($src);
    $fireCode = methodBody($code, 'fireMilpacMentions');
    check("$file has a fireMilpacMentions body to gate", $fireCode !== '');

    // The gate is the shared pure predicate, not a re-implemented in_array: every
    // other firing rule lives in MilpacResolver and this one is not the exception.
    check(
        "$file consults MilpacResolver::isSuppressedArea",
        str_contains($fireCode, 'MilpacResolver::isSuppressedArea('),
        'a surface that hand-rolls the deny-list check drifts from the tested rule'
    );

    // The option this surface reads. A transposition — the post surface reading the
    // ticket-category list — would suppress nothing, since node ids and category ids
    // are different id spaces that happen to overlap numerically.
    check(
        "$file reads its own deny-list option $expected[option]",
        (bool) preg_match('/\\\\XF::options\(\)->' . preg_quote($expected['option'], '/') . '\b/', $fireCode),
        'the two deny-lists are different id spaces; reading the other one silently suppresses the wrong areas'
    );
    check(
        "$file does NOT read the other surface's deny-list option",
        !str_contains($fireCode, $expected['option'] === 'cav7MMSuppressedNodeIds'
            ? 'cav7MMSuppressedTicketCategoryIds'
            : 'cav7MMSuppressedNodeIds')
    );

    // The area id itself: the relation hop and the column, so a gate asking about the
    // thread_id, the post_id or the ticket_id instead fails here.
    check(
        "$file resolves its area as $expected[area]->$expected[areaColumn]",
        (bool) preg_match(
            '/' . preg_quote($expected['area'], '/') . '\s*(?:\?|\)|-)/',
            $fireCode
        ) && str_contains($fireCode, $expected['areaColumn']),
        'the deny-list names places; asking about the wrong id gates the wrong thing'
    );

    // The gate must SKIP firing, and it must do so before the alert loop rather than
    // filtering recipients afterwards — suppression is about the place, so it decides
    // once for the whole message.
    check(
        "$file returns without alerting when the area is suppressed",
        (bool) preg_match(
            '/if\s*\(\s*MilpacResolver::isSuppressedArea\(.*?\)\s*\)\s*\{\s*return\s*;/s',
            $fireCode
        ),
        'the gate has to stop the pass, not merely compute a boolean'
    );

    $gatePos = strpos($fireCode, 'MilpacResolver::isSuppressedArea(');
    $alertPos = strpos($fireCode, '->alert(');
    check(
        "$file gates before any alert is raised",
        $gatePos !== false && $alertPos !== false && $gatePos < $alertPos
    );

    // Containment: the gate reads a relation and the options, either of which can
    // fault on a saved-and-committed action. It sits inside the surface's existing
    // outer try, so a fault is logged and the pass abandoned rather than 500-ing the
    // member's reply — and abandoning the pass withholds alerts, which is the safe
    // side of a disclosure gate.
    $outerTryPos = preg_match('/\btry\s*\{/', $fireCode, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
    check(
        "$file gates INSIDE the outer try, so a relation fault cannot surface on the member's action",
        $outerTryPos !== false && $gatePos !== false && $outerTryPos < $gatePos,
        'the content is already committed when firing runs; an uncaught throw here would 500 it'
    );

    // The resolver has to be in scope for the call to work at all. Both surfaces
    // already import MilpacStash from the same namespace, so the import is the tell.
    check(
        "$file imports Cav7\\MilpacMention\\MilpacResolver",
        str_contains($code, 'use Cav7\MilpacMention\MilpacResolver;')
    );
}

// =========================================================================
// the surfaces deliberately left alone
// =========================================================================

// Profile posts, profile-post comments and report comments carry no forum node and
// no ticket category, so neither deny-list can name them. They are untouched on
// purpose, and the negative pin is what keeps "decided not to" from decaying into
// "forgot to" the next time a surface is added.
$ungatedSurfaces = [
    'XF/Service/ProfilePost/NotifierService.php',
    'XF/Service/ProfilePostComment/NotifierService.php',
    'XF/Service/Report/NotifierService.php',
];
foreach ($ungatedSurfaces as $file) {
    $src = (string) @file_get_contents("$root/$file");
    check("$file source exists", $src !== '');

    $code = stripComments($src);
    check(
        "$file is NOT gated — it has no place either deny-list can name",
        !str_contains($code, 'isSuppressedArea')
            && !str_contains($code, 'cav7MMSuppressedNodeIds')
            && !str_contains($code, 'cav7MMSuppressedTicketCategoryIds'),
        'both options are scoped to forum nodes and ticket categories; this surface has neither'
    );
}

// Detection is untouched too. Suppression withholds the alert and nothing else: in a
// suppressed area the milpac link still renders, still resolves, and the $name
// completer still works. Gating the shared detection hook would break all three, so
// pin that the gate never migrated there.
$preparerCode = stripComments((string) @file_get_contents("$root/XF/Service/Message/PreparerService.php"));
check(
    'the shared detection hook is NOT gated — the link must still render and resolve in a suppressed area',
    $preparerCode !== '' && !str_contains($preparerCode, 'isSuppressedArea'),
    'suppression withholds the notification only; detection, resolution and the $name completer are untouched'
);
$formatterCode = stripComments((string) @file_get_contents("$root/XF/Str/MentionFormatter.php"));
check(
    'the typed-$name resolver is NOT gated — the completer is unaffected by suppression',
    $formatterCode !== '' && !str_contains($formatterCode, 'isSuppressedArea')
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
