<?php

/**
 * Exercises Token, the helper behind a hook's token: generate, hash, match.
 *
 * Two rows from the spec on issue #288. The alphabet is the caller-observed
 * contract: a caller that validates Discord's webhook URL pattern refuses `+`
 * and `/`, and XenForo's `:str` route parameter accepts exactly
 * `[A-Za-z0-9_-]`. The length floor of 64 is the spec's number, pinned as
 * written. Nothing here asserts the hash algorithm or that hash() is
 * deterministic; nothing looks a token up by its hash.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/TokenTest.php
 */

namespace Cav7\TicketWebhook\Tests;

require __DIR__ . '/../Token.php';

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

// ---------------------------------------------------------------------------
// generate(): the alphabet a Discord-shaped URL accepts, at least 64 long,
// and never the same twice.
// ---------------------------------------------------------------------------
$first = Token::generate();
$second = Token::generate();

check(
    'a generated token is at least 64 characters of [A-Za-z0-9_-]',
    preg_match('/^[A-Za-z0-9_-]{64,}$/', $first) === 1,
    'got ' . var_export($first, true)
);

check(
    'two generated tokens differ',
    $first !== $second
);

// ---------------------------------------------------------------------------
// hash() and matches(): the stored form never carries the token, the token
// that made the hash matches it, and another token does not.
// ---------------------------------------------------------------------------
$token = Token::generate();
$other = Token::generate();
$hash = Token::hash($token);

check(
    'the hash of a token does not contain the token',
    !str_contains($hash, $token)
);

check(
    'the token that made a hash matches it',
    Token::matches($token, $hash)
);

check(
    'another token does not match that hash',
    !Token::matches($other, $hash)
);

exit($failures === 0 ? 0 : 1);
