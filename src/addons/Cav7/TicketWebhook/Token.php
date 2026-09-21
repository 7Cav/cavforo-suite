<?php

namespace Cav7\TicketWebhook;

/**
 * A hook's token: what lets a caller post to one hook. See CONTEXT.md.
 *
 * Pure PHP with no XenForo import, so tests/TokenTest.php runs it with bare
 * php. Three operations: generate a new token, hash one for storage, and
 * match a presented token against a stored hash.
 *
 * The alphabet is `[A-Za-z0-9_-]`, which a caller that validates Discord's
 * webhook URL pattern accepts and XenForo's `:str` route parameter matches;
 * base64 proper would put `+` and `/` in the path. 48 random bytes encode to
 * 64 characters with no padding, the floor the spec on #288 sets.
 */
final class Token
{
    private const RANDOM_BYTES = 48;

    /**
     * A new token, drawn from random_bytes.
     */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '=');
    }

    /**
     * The stored form of a token: a raw SHA-256 digest, 32 bytes, as
     * Cav7/ApiKeyManager stores its keys. The token itself is never stored.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token, true);
    }

    /**
     * Whether a presented token is the one a stored hash was made from.
     * Compared in constant time.
     */
    public static function matches(string $token, string $hash): bool
    {
        return hash_equals($hash, self::hash($token));
    }
}
