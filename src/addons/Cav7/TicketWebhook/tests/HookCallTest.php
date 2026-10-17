<?php

/**
 * Exercises HookCall, the decision behind a call to a hook: not found, bad
 * request, or accepted with a title, a message and an alert key, and what an
 * accepted call does to its key's ticket: open one, reply to it, and close or
 * reopen it.
 *
 * Every row is an explicit input and a literal expected outcome from the spec
 * on issue #288, or for alert keys the spec on issue #321. The hook facts and
 * the tokens are fixtures; the hash comes from Token's public API, which is
 * the seam HookCall matches through. Nothing here recomputes a title, a
 * message or a key hash the way the code does.
 *
 * What a test cannot reach is verified by hand on the dev stack: the key
 * table, the vendor's Replier and its notifications, the ticket option that
 * keeps a reply from moving the status, the vendor's Editor closing and
 * reopening a ticket, and reducing a real ticket to the closed, locked and
 * deleted facts these rows hand HookCall.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/HookCallTest.php
 */

namespace Cav7\TicketWebhook\Tests;

require __DIR__ . '/../Token.php';
require __DIR__ . '/../HookCall.php';

use Cav7\TicketWebhook\HookCall;
use Cav7\TicketWebhook\Token;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}

// The hook every row calls unless it says otherwise, and the two tokens.
$hook = [
    'active' => true,
    'token_hash' => Token::hash('correct-token'),
    'name' => 'Hook name',
];
$inactiveHook = ['active' => false] + $hook;

// A body that yields text, for the rows about credentials.
$bodyWithContent = ['content' => 'alpha'];

// ---------------------------------------------------------------------------
// Credentials. A missing hook, an inactive hook and a wrong token all answer
// the same, so a probe learns nothing from the difference.
// ---------------------------------------------------------------------------
check(
    'no hook: not found',
    HookCall::decide(null, 'correct-token', null, $bodyWithContent)->outcome() === HookCall::NOT_FOUND
);

check(
    'an inactive hook and the correct path token: not found',
    HookCall::decide($inactiveHook, 'correct-token', null, $bodyWithContent)->outcome() === HookCall::NOT_FOUND
);

check(
    'a wrong path token and no header: not found',
    HookCall::decide($hook, 'wrong-token', null, $bodyWithContent)->outcome() === HookCall::NOT_FOUND
);

check(
    'a wrong path token beside a correct bearer header: accepted',
    HookCall::decide($hook, 'wrong-token', 'Bearer correct-token', $bodyWithContent)->outcome() === HookCall::ACCEPTED
);

// Credentials are checked before the body, so a probe with a wrong token gets
// the same answer whatever it sends. This row is green from birth and pinned
// against a rewrite that reads the body first, which answers bad request.
check(
    'a wrong path token and an empty body: not found, not bad request',
    HookCall::decide($hook, 'wrong-token', null, [])->outcome() === HookCall::NOT_FOUND
);

check(
    'a correct path token beside a wrong bearer header: accepted',
    HookCall::decide($hook, 'correct-token', 'Bearer wrong-token', $bodyWithContent)->outcome() === HookCall::ACCEPTED,
    'a proxy may add a header of its own; a correct URL still works'
);

// ---------------------------------------------------------------------------
// The title and the message. Rows call with the correct path token and no
// header, so only the body decides.
// ---------------------------------------------------------------------------
function call(array $hook, $body): HookCall
{
    return HookCall::decide($hook, 'correct-token', null, $body);
}

$contentOnly = call($hook, ['content' => "first line\nsecond line"]);

check(
    'content alone: accepted',
    $contentOnly->outcome() === HookCall::ACCEPTED
);

check(
    'content alone: the title is the first line of the content',
    $contentOnly->title() === 'first line',
    'got ' . var_export($contentOnly->title(), true)
);

check(
    'content alone: the message is the content as typed',
    $contentOnly->message() === "first line\nsecond line",
    'got ' . var_export($contentOnly->message(), true)
);

// ---------------------------------------------------------------------------
// A body that yields no text is a bad request. Two fixtures: an implementation
// that tests the key's presence (isset) rather than its emptiness accepts the
// second with an empty message and goes red on it alone.
// ---------------------------------------------------------------------------
check(
    'an empty object: bad request',
    call($hook, [])->outcome() === HookCall::BAD_REQUEST
);

check(
    'empty content and no embeds: bad request',
    call($hook, ['content' => '', 'embeds' => []])->outcome() === HookCall::BAD_REQUEST
);

// Whitespace is not text either. Accepting it hands the vendor an empty
// message, which it refuses as a 500 that blames the hook.
check(
    'whitespace-only content and no embeds: bad request',
    call($hook, ['content' => '   '])->outcome() === HookCall::BAD_REQUEST
);

// ---------------------------------------------------------------------------
// The title of last resort is the hook's name.
// ---------------------------------------------------------------------------
$descriptionOnly = call($hook, ['embeds' => [['description' => 'D']]]);

check(
    'an embed with a description and no title, no content: accepted',
    $descriptionOnly->outcome() === HookCall::ACCEPTED
);

check(
    'an embed with a description and no title, no content: the title is the hook name',
    $descriptionOnly->title() === 'Hook name',
    'got ' . var_export($descriptionOnly->title(), true)
);

// ---------------------------------------------------------------------------
// A title-only embed is text: the title is the ticket's title, and the
// message carries it too rather than arriving empty for the vendor to refuse.
// ---------------------------------------------------------------------------
$titleOnly = call($hook, ['embeds' => [['title' => 'Tango']]]);

check(
    'a title-only embed: accepted',
    $titleOnly->outcome() === HookCall::ACCEPTED
);

check(
    'a title-only embed: the title is the embed title',
    $titleOnly->title() === 'Tango',
    'got ' . var_export($titleOnly->title(), true)
);

check(
    'a title-only embed: the message carries the title',
    str_contains($titleOnly->message(), 'Tango'),
    'got ' . var_export($titleOnly->message(), true)
);

// ---------------------------------------------------------------------------
// The title is cut to 150 characters, the width of the vendor's title column.
// The fixture is a two-byte character so a byte cut, which returns 75
// characters, goes red.
// ---------------------------------------------------------------------------
$longTitle = call($hook, ['embeds' => [['title' => str_repeat('é', 200)]]]);

check(
    'an embed title of 200 characters is cut to its first 150 characters',
    $longTitle->title() === str_repeat('é', 150),
    'got ' . strlen($longTitle->title()) . ' bytes'
);

// ---------------------------------------------------------------------------
// Everything the caller said lands, in the order it was sent: content, then
// each embed's description, url and fields, name before value. No fixture
// word is a substring of another or of any separator, so an assembly in the
// wrong order cannot pass. Each word is checked present before its offset is
// compared, because strpos's false would otherwise compare as 0.
// ---------------------------------------------------------------------------
$ordered = call($hook, [
    'content' => 'alpha',
    'embeds' => [[
        'title' => 'bravo',
        'description' => 'charlie',
        'url' => 'https://example.test/delta',
        'fields' => [
            ['name' => 'echo', 'value' => 'foxtrot'],
            ['name' => 'golf', 'value' => 'hotel'],
        ],
    ]],
]);

check(
    'content and one full embed: the title is the embed title',
    $ordered->title() === 'bravo',
    'got ' . var_export($ordered->title(), true)
);

$words = ['alpha', 'charlie', 'delta', 'echo', 'foxtrot', 'golf', 'hotel'];
$offsets = [];
$allPresent = true;
foreach ($words as $word) {
    $at = strpos($ordered->message(), $word);
    if ($at === false) {
        $allPresent = false;
        check("content and one full embed: '$word' lands in the message", false, 'got ' . var_export($ordered->message(), true));
    } else {
        $offsets[] = $at;
    }
}
check(
    'content and one full embed: every part lands in the message',
    $allPresent
);

$inOrder = $allPresent;
for ($i = 1; $inOrder && $i < count($offsets); $i++) {
    $inOrder = $offsets[$i - 1] < $offsets[$i];
}
check(
    'content and one full embed: the parts land in the order sent, name before value',
    $inOrder,
    'got ' . var_export($ordered->message(), true)
);

// ---------------------------------------------------------------------------
// WUD's Discord trigger, as it calls by default: username, avatar_url, and one
// embed with a title, a colour and one field whose name is empty and whose
// value is the rendered body. The value is the whole message, byte for byte:
// an empty field name contributes nothing, not even a separator, and the
// username cannot leak in.
// ---------------------------------------------------------------------------
$wud = call($hook, [
    'username' => 'WUD',
    'avatar_url' => 'https://example.test/a.png',
    'embeds' => [[
        'title' => 'T',
        'color' => 123,
        'fields' => [['name' => '', 'value' => 'V']],
    ]],
]);

check(
    'a WUD call: accepted',
    $wud->outcome() === HookCall::ACCEPTED
);

check(
    'a WUD call: the title is the embed title',
    $wud->title() === 'T',
    'got ' . var_export($wud->title(), true)
);

check(
    'a WUD call: the message is the one field value alone',
    $wud->message() === 'V',
    'got ' . var_export($wud->message(), true)
);

// ---------------------------------------------------------------------------
// The alert key. A key line is `Alert key: <key>` on a line of its own, in
// the content, an embed's description or a field's value. Rows compare one
// answer's key hash with another's and never compute a digest, so the hash
// algorithm stays free.
// ---------------------------------------------------------------------------
$keyed = call($hook, ['content' => "Disk full\nAlert key: k1"]);

check(
    'a key line in the content gives the call a key',
    $keyed->keyHash() !== null
);

// The key line is for the hook, not for staff: it leaves the message, and the
// lines either side of it stay.
$keyLineBetween = call($hook, ['content' => "before\nAlert key: k1\nafter"]);

check(
    'a key line is left out of the message',
    !str_contains($keyLineBetween->message(), 'Alert key'),
    'got ' . var_export($keyLineBetween->message(), true)
);

check(
    'the lines either side of a key line stay in the message',
    str_contains($keyLineBetween->message(), 'before') && str_contains($keyLineBetween->message(), 'after'),
    'got ' . var_export($keyLineBetween->message(), true)
);

// A call whose only text is its key line still says something happened, so it
// is accepted, and with nothing else to go on its title and message are the
// hook's name.
$keyLineOnly = call($hook, ['content' => 'Alert key: k1']);

check(
    'a call whose only text is its key line: accepted',
    $keyLineOnly->outcome() === HookCall::ACCEPTED,
    'got ' . $keyLineOnly->outcome()
);

check(
    'a call whose only text is its key line: the title is the hook name',
    $keyLineOnly->title() === 'Hook name',
    'got ' . var_export($keyLineOnly->title(), true)
);

check(
    'a call whose only text is its key line: the message is the hook name',
    $keyLineOnly->message() === 'Hook name',
    'got ' . var_export($keyLineOnly->message(), true)
);

// The key line is found where callers can put it: Grafana sends no fields, and
// WUD's one field, with an empty name, holds its whole body.
$keyedDescription = call($hook, ['embeds' => [['title' => 'Disk full', 'description' => 'Alert key: k1']]]);

check(
    'a key line in an embed description names the same key as one in the content',
    $keyedDescription->keyHash() !== null && $keyedDescription->keyHash() === $keyed->keyHash()
);

$keyedField = call($hook, ['embeds' => [[
    'title' => 'Disk full',
    'fields' => [['name' => '', 'value' => "Disk full on host1\nAlert key: k1"]],
]]]);

check(
    'a key line in a field value with an empty name names the same key as one in the content',
    $keyedField->keyHash() !== null && $keyedField->keyHash() === $keyed->keyHash()
);

// A key line is a line of its own. Text that quotes the label mid-sentence
// must not merge unrelated calls onto one ticket.
check(
    'the label inside a sentence gives no key',
    call($hook, ['content' => 'see Alert key: k1 here'])->keyHash() === null
);

// The label matches whatever its case and the spaces around it, and the key
// is trimmed. The line sits mid-content so its trailing space is followed by a
// line break rather than by the end of the value.
$looseLabel = call($hook, ['content' => "Disk full\nalert KEY :  k1 \nmore"]);

check(
    'the label in another case and with spaces around it names the same key',
    $looseLabel->keyHash() !== null && $looseLabel->keyHash() === $keyed->keyHash()
);

// The key itself is exact: two containers whose names differ only in case
// stay on separate tickets.
$upperKey = call($hook, ['content' => "Disk full\nAlert key: K1"]);

check(
    'a key that differs only in case names a different key',
    $upperKey->keyHash() !== null && $upperKey->keyHash() !== $keyed->keyHash()
);

// A key is the whole rest of its line, whatever script it is written in. The
// two keys share their first letter, whose last UTF-8 byte (0x85) a byte-wise
// line break rule takes for a line break of its own.
$keyAsa = call($hook, ['content' => "Disk full\nAlert key: Åsa"]);
$keyAbo = call($hook, ['content' => "Disk full\nAlert key: Åbo"]);

check(
    'two keys that differ after a non-ASCII first letter name different keys',
    $keyAsa->keyHash() !== null && $keyAbo->keyHash() !== null && $keyAsa->keyHash() !== $keyAbo->keyHash()
);

// The answer carries a hash, never the key: a healthchecks.io check's UUID is
// also the secret that pings it, and the hash is what gets stored.
$uuidKey = call($hook, ['content' => "nightly-backup is down\nAlert key: d1665499-e827-441f-bf13-8f15e8f4c0b9"]);

check(
    'the key hash does not contain the key',
    $uuidKey->keyHash() !== null && !str_contains($uuidKey->keyHash(), 'd1665499-e827-441f-bf13-8f15e8f4c0b9')
);

// A template variable that rendered empty must not merge every call onto one
// ticket, so an empty key is no key.
check(
    'a key line with nothing after the label gives no key',
    call($hook, ['content' => "Disk full\nAlert key:"])->keyHash() === null
);

// WUD's batch mode writes one key line per container in a single call. Key
// lines that disagree leave the call without a key, so no container's ticket
// collects the others.
check(
    'two key lines naming different keys give no key',
    call($hook, ['content' => "nginx\nAlert key: k1\nhomepage\nAlert key: k2"])->keyHash() === null
);

$repeatedKey = call($hook, ['content' => "Disk full\nAlert key: k1\nstill full\nAlert key: k1"]);

check(
    'two identical key lines name the same key as one',
    $repeatedKey->keyHash() !== null && $repeatedKey->keyHash() === $keyed->keyHash()
);

// ---------------------------------------------------------------------------
// The all-clear. `All-clear: <key>` stands where `Alert key: <key>` would, and
// says the problem the key names is fine again.
// ---------------------------------------------------------------------------

// A minimal all-clear body is a complete call, and its key line leaves the
// text like any other, so the hook's name stands in for title and message.
$allClearOnly = call($hook, ['content' => 'All-clear: k1']);

check(
    'a call whose only text is its all-clear line: accepted',
    $allClearOnly->outcome() === HookCall::ACCEPTED,
    'got ' . $allClearOnly->outcome()
);

check(
    'a call whose only text is its all-clear line: the title is the hook name',
    $allClearOnly->title() === 'Hook name',
    'got ' . var_export($allClearOnly->title(), true)
);

check(
    'a call whose only text is its all-clear line: the message is the hook name',
    $allClearOnly->message() === 'Hook name',
    'got ' . var_export($allClearOnly->message(), true)
);

// The all-clear label matches as the alert key label does, whatever its case
// and the spaces around it, and it names the same key, so the all-clear
// reaches the ticket its firing call opened. The line sits mid-content so its
// trailing space is followed by a line break.
$allClear = call($hook, ['content' => "Disk back\nall-CLEAR :  k1 \nmore"]);

check(
    'an all-clear line in another case and with spaces around it names the same key as an alert key line',
    $allClear->keyHash() !== null && $allClear->keyHash() === $keyed->keyHash()
);

// A call that says a key is firing and clear at once disagrees with itself,
// like two different keys, so it has no key and opens a ticket.
check(
    'an alert key line and an all-clear line for the same key give no key',
    call($hook, ['content' => "Disk full\nAlert key: k1\nAll-clear: k1"])->keyHash() === null
);

// ---------------------------------------------------------------------------
// What a firing call does, given the facts of its key's linked ticket: null
// when there is none, else whether it is closed, locked and deleted. Staff
// close a ticket to mean done for now, so a firing call reopens it, and lock
// or delete it to mean start a new ticket next time.
// ---------------------------------------------------------------------------
check(
    'a firing call whose key has no linked ticket opens one',
    $keyed->action(null) === HookCall::OPEN,
    'got ' . var_export($keyed->action(null), true)
);

$openTicket = ['closed' => false, 'locked' => false, 'deleted' => false];

check(
    'a firing call whose key links an open ticket replies to it',
    $keyed->action($openTicket) === HookCall::REPLY,
    'got ' . var_export($keyed->action($openTicket), true)
);

$closedTicket = ['closed' => true] + $openTicket;

check(
    'a firing call whose key links a closed ticket replies and reopens it',
    $keyed->action($closedTicket) === HookCall::REPLY_AND_REOPEN,
    'got ' . var_export($keyed->action($closedTicket), true)
);

$lockedTicket = ['locked' => true] + $openTicket;

check(
    'a firing call whose key links a locked ticket opens a new one',
    $keyed->action($lockedTicket) === HookCall::OPEN,
    'got ' . var_export($keyed->action($lockedTicket), true)
);

$deletedTicket = ['deleted' => true] + $openTicket;

check(
    'a firing call whose key links a deleted ticket opens a new one',
    $keyed->action($deletedTicket) === HookCall::OPEN,
    'got ' . var_export($keyed->action($deletedTicket), true)
);

// A lock is staff saying start a new ticket next time, and it wins over the
// ticket being closed too.
$lockedClosedTicket = ['locked' => true, 'closed' => true] + $openTicket;

check(
    'a firing call whose key links a locked and closed ticket opens a new one',
    $keyed->action($lockedClosedTicket) === HookCall::OPEN,
    'got ' . var_export($keyed->action($lockedClosedTicket), true)
);

// ---------------------------------------------------------------------------
// What an all-clear does, given the same facts. It closes the key's ticket,
// and when there is no ticket it can reach, it opens one and closes it at
// once, so an outage whose firing call was lost still leaves a record.
// ---------------------------------------------------------------------------
check(
    'an all-clear whose key has no linked ticket opens one and closes it',
    $allClear->action(null) === HookCall::OPEN_AND_CLOSE,
    'got ' . var_export($allClear->action(null), true)
);

check(
    'an all-clear whose key links an open ticket replies and closes it',
    $allClear->action($openTicket) === HookCall::REPLY_AND_CLOSE,
    'got ' . var_export($allClear->action($openTicket), true)
);

// An all-clear for a ticket staff already closed adds its reply, so the
// history shows the recovery. The ticket keeps the closed status staff gave
// it, because the close that follows leaves a ticket whose status already
// closes it alone (ADR-0002's note). This row asserts only the answer.
check(
    'an all-clear whose key links a closed ticket answers reply and close, as for an open ticket',
    $allClear->action($closedTicket) === HookCall::REPLY_AND_CLOSE,
    'got ' . var_export($allClear->action($closedTicket), true)
);

check(
    'an all-clear whose key links a locked ticket opens a new one and closes it',
    $allClear->action($lockedTicket) === HookCall::OPEN_AND_CLOSE,
    'got ' . var_export($allClear->action($lockedTicket), true)
);

check(
    'an all-clear whose key links a deleted ticket opens a new one and closes it',
    $allClear->action($deletedTicket) === HookCall::OPEN_AND_CLOSE,
    'got ' . var_export($allClear->action($deletedTicket), true)
);

// ---------------------------------------------------------------------------
// A reply's text. The ticket keeps its own title, so a reply starts with the
// call's title, such as Grafana's [FIRING:2], then carries its message.
// ---------------------------------------------------------------------------

/**
 * The first non-blank line of a text, and everything after it.
 */
function splitFirstLine(string $text): array
{
    $lines = preg_split('/\R/', ltrim($text));

    return [$lines[0], implode("\n", array_slice($lines, 1))];
}

$titled = call($hook, ['embeds' => [['title' => 'T-title', 'description' => 'M-body']]]);
[$replyFirstLine, $replyRest] = splitFirstLine($titled->replyMessage());

check(
    'a reply starts with the call\'s title',
    str_contains($replyFirstLine, 'T-title'),
    'got ' . var_export($titled->replyMessage(), true)
);

check(
    'a reply carries the call\'s message after its title',
    str_contains($replyRest, 'M-body'),
    'got ' . var_export($titled->replyMessage(), true)
);

check(
    'a reply to a call whose only text is its title carries the title once',
    substr_count($titleOnly->replyMessage(), 'Tango') === 1,
    'got ' . var_export($titleOnly->replyMessage(), true)
);

// However long the title is. The ticket's title is cut to 150 characters, and
// the reply still says the whole title, once: its first 150 characters occur
// one time, not once for the cut title and again for the message.
$longTitleReply = $longTitle->replyMessage();

check(
    'a reply to a call whose only text is a 200-character title carries the whole title once',
    str_contains($longTitleReply, str_repeat('é', 200)) && substr_count($longTitleReply, str_repeat('é', 150)) === 1,
    'got ' . strlen($longTitleReply) . ' bytes'
);

exit($failures === 0 ? 0 : 1);
