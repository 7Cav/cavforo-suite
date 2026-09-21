<?php

/**
 * Exercises HookPost, the decision behind a post to a hook: not found, bad
 * request, or accepted with a title and a message.
 *
 * Every row is an explicit input and a literal expected outcome from the spec
 * on issue #288. The hook facts and the tokens are fixtures; the hash comes
 * from Token's public API, which is the seam HookPost matches through. Nothing
 * here recomputes a title or a message the way the code does.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/HookPostTest.php
 */

namespace Cav7\TicketWebhook\Tests;

require __DIR__ . '/../Token.php';
require __DIR__ . '/../HookPost.php';

use Cav7\TicketWebhook\HookPost;
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

// The hook every row posts to unless it says otherwise, and the two tokens.
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
    HookPost::decide(null, 'correct-token', null, $bodyWithContent)->outcome() === HookPost::NOT_FOUND
);

check(
    'an inactive hook and the correct path token: not found',
    HookPost::decide($inactiveHook, 'correct-token', null, $bodyWithContent)->outcome() === HookPost::NOT_FOUND
);

check(
    'a wrong path token and no header: not found',
    HookPost::decide($hook, 'wrong-token', null, $bodyWithContent)->outcome() === HookPost::NOT_FOUND
);

check(
    'a wrong path token beside a correct bearer header: accepted',
    HookPost::decide($hook, 'wrong-token', 'Bearer correct-token', $bodyWithContent)->outcome() === HookPost::ACCEPTED
);

// Credentials are checked before the body, so a probe with a wrong token gets
// the same answer whatever it sends. This row is green from birth and pinned
// against a rewrite that reads the body first, which answers bad request.
check(
    'a wrong path token and an empty body: not found, not bad request',
    HookPost::decide($hook, 'wrong-token', null, [])->outcome() === HookPost::NOT_FOUND
);

check(
    'a correct path token beside a wrong bearer header: accepted',
    HookPost::decide($hook, 'correct-token', 'Bearer wrong-token', $bodyWithContent)->outcome() === HookPost::ACCEPTED,
    'a proxy may add a header of its own; a correct URL still works'
);

// ---------------------------------------------------------------------------
// The title and the message. Rows post with the correct path token and no
// header, so only the body decides.
// ---------------------------------------------------------------------------
function post(array $hook, $body): HookPost
{
    return HookPost::decide($hook, 'correct-token', null, $body);
}

$contentOnly = post($hook, ['content' => "first line\nsecond line"]);

check(
    'content alone: accepted',
    $contentOnly->outcome() === HookPost::ACCEPTED
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
    post($hook, [])->outcome() === HookPost::BAD_REQUEST
);

check(
    'empty content and no embeds: bad request',
    post($hook, ['content' => '', 'embeds' => []])->outcome() === HookPost::BAD_REQUEST
);

// ---------------------------------------------------------------------------
// The title of last resort is the hook's name.
// ---------------------------------------------------------------------------
$descriptionOnly = post($hook, ['embeds' => [['description' => 'D']]]);

check(
    'an embed with a description and no title, no content: accepted',
    $descriptionOnly->outcome() === HookPost::ACCEPTED
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
$titleOnly = post($hook, ['embeds' => [['title' => 'Tango']]]);

check(
    'a title-only embed: accepted',
    $titleOnly->outcome() === HookPost::ACCEPTED
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
$longTitle = post($hook, ['embeds' => [['title' => str_repeat('é', 200)]]]);

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
$ordered = post($hook, [
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
// WUD's Discord trigger, as it posts by default: username, avatar_url, and one
// embed with a title, a colour and one field whose name is empty and whose
// value is the rendered body. The value is the whole message, byte for byte:
// an empty field name contributes nothing, not even a separator, and the
// username cannot leak in.
// ---------------------------------------------------------------------------
$wud = post($hook, [
    'username' => 'WUD',
    'avatar_url' => 'https://example.test/a.png',
    'embeds' => [[
        'title' => 'T',
        'color' => 123,
        'fields' => [['name' => '', 'value' => 'V']],
    ]],
]);

check(
    'a WUD post: accepted',
    $wud->outcome() === HookPost::ACCEPTED
);

check(
    'a WUD post: the title is the embed title',
    $wud->title() === 'T',
    'got ' . var_export($wud->title(), true)
);

check(
    'a WUD post: the message is the one field value alone',
    $wud->message() === 'V',
    'got ' . var_export($wud->message(), true)
);

exit($failures === 0 ? 0 : 1);
