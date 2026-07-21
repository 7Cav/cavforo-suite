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

// =========================================================================
// the ticket-category option renderer, and the add-on's first ACTIVE
// soft-dependency check
// =========================================================================

// Forum nodes need no renderer of our own: XF\Option\Forum::renderSelectMultiple is
// core, and is the same renderer EWR/Porta's article-forums option uses. Ticket
// categories have none — NF/Tickets ships three option renderers and all three are
// single-select, none for categories — so this one is ours.
//
// Being ours is what makes it the first place NF/Tickets' absence has to be handled
// actively. Everywhere else the add-on's soft dependency is PASSIVE: without
// NF/Tickets the from_class never loads, XF never builds the XFCP proxy, and the two
// ticket extensions simply never run. An option renderer is a class of ours that the
// ACP loads whether or not NF/Tickets is there, so it has to guard itself.
$rendererFile = "$root/Option/TicketCategory.php";
$rendererSrc = (string) @file_get_contents($rendererFile);
check('Option/TicketCategory.php source exists', $rendererSrc !== '');

$rendererCode = stripComments($rendererSrc);

check(
    'the renderer is built on the XF\\Option\\AbstractOption shape',
    (bool) preg_match('/class\s+TicketCategory\s+extends\s+AbstractOption\b/', $rendererCode)
        && str_contains($rendererCode, 'use XF\Option\AbstractOption;'),
    'options are rendered by a static callback on that shape; anything else is not called'
);

// The option is a multi-select, so the callback the option names has to be the
// multiple variant. A single-select renderer would let an admin suppress exactly one
// category.
check(
    'the renderer exposes renderSelectMultiple as a static callback',
    (bool) preg_match(
        '/public\s+static\s+function\s+renderSelectMultiple\s*\(\s*Option\s+\$option\s*,\s*array\s+\$htmlParams\s*\)/',
        $rendererCode
    ),
    'a deny-list needs to name several categories at once'
);

// Real names, not id textboxes: the choices come from the NF/Tickets category
// records themselves.
check(
    'the renderer lists real NF/Tickets categories rather than asking for ids',
    str_contains($rendererCode, 'NF\Tickets:Category'),
    'the picker has to show category names an admin recognises'
);

$renderBody = methodBody($rendererCode, 'renderSelectMultiple');
check('the renderer has a renderSelectMultiple body', $renderBody !== '');

// The active guard. Without it the first line of the render would reach for an
// NF/Tickets class that is not there and take the whole options page down with it.
check(
    'the renderer checks NF/Tickets is active before touching anything of theirs',
    str_contains($rendererCode, "isAddOnActive('NF/Tickets')"),
    'the ACP loads this class whether or not NF/Tickets is installed'
);

$guardPos = strpos($rendererCode, "isAddOnActive('NF/Tickets')");
$categoryPos = strpos($rendererCode, 'NF\Tickets:Category');
check(
    'the guard runs BEFORE the first NF/Tickets lookup',
    $guardPos !== false && $categoryPos !== false && $guardPos < $categoryPos,
    'a guard after the lookup guards nothing'
);

// What the absent case renders is the whole point of the guard. A vanished row
// leaves an admin with no explanation for the absence, and an empty but enabled
// picker states something false, namely that no categories exist. So: disabled, with
// an explanation.
check(
    'with NF/Tickets absent the row renders DISABLED',
    (bool) preg_match("/\['disabled'\]\s*=\s*true|'disabled'\s*=>\s*true/", $rendererCode),
    'an enabled empty picker claims there are no categories'
);
check(
    'with NF/Tickets absent the row carries an explanation phrase',
    str_contains($rendererCode, 'cav7_mm_option_ticket_categories_no_tickets'),
    'a disabled control with no reason given is just a broken control'
);
check(
    'the absent case still returns a rendered row rather than nothing',
    (bool) preg_match('/return\s+(?:static|self)::getSelectRow\(/', $renderBody)
        || (bool) preg_match('/return\s+static::getTemplater\(\)->formSelectRow\(/', $renderBody),
    'returning an empty string would make the row disappear, which is the failure mode being avoided'
);

// =========================================================================
// the two options, and the seeded defaults they ship with
// =========================================================================

$addon = json_decode((string) @file_get_contents("$root/addon.json"), true);
$versionId = is_array($addon) ? ($addon['version_id'] ?? null) : null;
check('addon.json is valid JSON with a positive version_id', is_int($versionId) && $versionId > 0);

// This add-on had no admin option before #147, so the group is new too.
$groupsXml = @simplexml_load_file("$root/_data/option_groups.xml");
check('_data/option_groups.xml could be read', $groupsXml !== false);

$groupIds = [];
if ($groupsXml !== false) {
    foreach ($groupsXml->group as $group) {
        $groupIds[] = (string) $group['group_id'];
    }
}
check(
    'the cav7MilpacMention option group is declared',
    in_array('cav7MilpacMention', $groupIds, true)
);

$optionsXml = @simplexml_load_file("$root/_data/options.xml");
check('_data/options.xml could be read', $optionsXml !== false);

$options = [];
if ($optionsXml !== false) {
    foreach ($optionsXml->option as $optionEl) {
        $groupId = '';
        $relation = $optionEl->relation;
        if ($relation !== null && isset($relation[0])) {
            $groupId = (string) $relation[0]['group_id'];
        }
        $options[(string) $optionEl['option_id']] = [
            'edit_format' => (string) $optionEl['edit_format'],
            'data_type' => (string) $optionEl['data_type'],
            'edit_format_params' => trim((string) $optionEl->edit_format_params),
            'default_value' => trim((string) $optionEl->default_value),
            'group_id' => $groupId,
        ];
    }
}

/**
 * Both options are deny-lists of ids, so both are data_type=array behind a callback
 * edit format: an array option is what stores a multi-select, and a callback is what
 * lets the control be a picker of real names instead of a textbox of ids.
 */
$expectedOptions = [
    'cav7MMSuppressedNodeIds' => [
        // Forum nodes need no renderer of ours; this is the core one.
        'renderer' => 'XF\Option\Forum::renderSelectMultiple',
        // Ships EMPTY. There is no known forum node with the award-queue shape, and
        // an option that suppresses something on a fresh install without anyone
        // choosing it would be the opposite of an explicit act.
        'default' => [],
    ],
    'cav7MMSuppressedTicketCategoryIds' => [
        'renderer' => 'Cav7\MilpacMention\Option\TicketCategory::renderSelectMultiple',
        // Ships PRE-CONFIGURED with the four award queues: S1 Citations (17), Medal
        // Recommendations (18), Medal Approvals (20) and Medal posting (21). The leak
        // is firing today, so installing the version has to BE the remediation rather
        // than the prerequisite for it. The four came from measurement, not from
        // reading category titles: they are where 88-95% of milpac links point at
        // someone other than the member who opened the ticket. Every other queue on
        // the board sits between 38% and 80%.
        //
        // Two near misses stay off the list on purpose. Military Service Awards (25)
        // reads like an award queue but members open those tickets about their own
        // awards, and S1 Personnel Administration (5) is mixed enough that suppressing
        // it would cost legitimate notifications.
        'default' => [17, 18, 20, 21],
    ],
];

foreach ($expectedOptions as $optionId => $expected) {
    check("$optionId is declared in _data/options.xml", isset($options[$optionId]));
    if (!isset($options[$optionId])) {
        continue;
    }
    $declared = $options[$optionId];

    check(
        "$optionId is a data_type=array option behind a callback edit format",
        $declared['data_type'] === 'array' && $declared['edit_format'] === 'callback',
        'a multi-select saves an array, and only a callback can render a picker of real names'
    );
    check(
        "$optionId renders through $expected[renderer]",
        $declared['edit_format_params'] === $expected['renderer'],
        'the wrong callback renders the wrong id space, or an id textbox'
    );
    check(
        "$optionId belongs to the cav7MilpacMention group",
        $declared['group_id'] === 'cav7MilpacMention'
    );

    $default = json_decode($declared['default_value'], true);
    check(
        "$optionId ships a JSON array default",
        is_array($default),
        'XF stores an array option default as JSON; anything else installs as garbage'
    );
    check(
        "$optionId ships the seeded default " . json_encode($expected['default']),
        is_array($default) && array_map('intval', $default) === $expected['default'],
        'a fresh install has to arrive already suppressing the award queues, and suppressing no forum node'
    );

    // _output is the other half of the same export, and CI validates one against the
    // other. Pin the default there too, since a hand-edit of one side is exactly how
    // the two drift.
    $outputJson = json_decode((string) @file_get_contents("$root/_output/options/$optionId.json"), true);
    // Both sides store the default as a JSON string, so decode before comparing:
    // "[]" and "[\"17\",…]" are strings inside the record, not arrays.
    $outputDefault = is_array($outputJson) ? json_decode((string) ($outputJson['default_value'] ?? ''), true) : null;
    check(
        "$optionId has an _output record agreeing on the default and the callback",
        is_array($outputDefault)
            && array_map('intval', $outputDefault) === $expected['default']
            && trim((string) ($outputJson['edit_format_params'] ?? '')) === $expected['renderer']
    );
}

// =========================================================================
// the phrases the options are read through
// =========================================================================

$phrasesXml = @simplexml_load_file("$root/_data/phrases.xml");
check('_data/phrases.xml could be read', $phrasesXml !== false);

$phraseText = [];
if ($phrasesXml !== false) {
    foreach ($phrasesXml->phrase as $phrase) {
        $phraseText[(string) $phrase['title']] = (string) $phrase;
    }
}

$requiredPhrases = [
    'option_group.cav7MilpacMention',
    'option_group_description.cav7MilpacMention',
    'option.cav7MMSuppressedNodeIds',
    'option_explain.cav7MMSuppressedNodeIds',
    'option.cav7MMSuppressedTicketCategoryIds',
    'option_explain.cav7MMSuppressedTicketCategoryIds',
    // The reason the disabled ticket row gives for being disabled.
    'cav7_mm_option_ticket_categories_no_tickets',
];
foreach ($requiredPhrases as $title) {
    check(
        "phrase $title is declared and non-empty",
        ($phraseText[$title] ?? '') !== ''
    );
    check(
        "phrase $title has an _output file matching _data byte-for-byte",
        @file_get_contents("$root/_output/phrases/$title.txt") === ($phraseText[$title] ?? null)
    );
}

// The seeded ids are the one thing an admin cannot read off the picker, since a
// suppressed category shows as selected but not as "shipped that way". Say so in the
// explain text, the way EnlistmentReminder names its own seeded defaults.
foreach ([17, 18, 20, 21] as $seeded) {
    check(
        "the ticket-category explain phrase names the seeded category $seeded",
        str_contains($phraseText['option_explain.cav7MMSuppressedTicketCategoryIds'] ?? '', (string) $seeded),
        'an admin reading the option should be able to tell which selections came shipped'
    );
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
