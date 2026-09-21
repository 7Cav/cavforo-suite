<?php

namespace Cav7\TicketWebhook;

/**
 * What one post to a hook yields: not found, bad request, or accepted with
 * the title and message of the ticket to open. See CONTEXT.md for hook,
 * caller and token.
 *
 * Pure PHP with no XenForo import, so tests/HookPostTest.php runs it with
 * bare php. The public controller supplies the facts (the hook row, the path
 * token, the Authorization header, the decoded body) and acts on the answer.
 *
 * Not found when there is no hook, the hook is inactive, or neither the path
 * token nor an `Authorization: Bearer` header matches its hash; either
 * credential is enough on its own. Credentials are checked before the body,
 * so a probe with a wrong token learns nothing from what it sends.
 *
 * The body is Discord's webhook request. The title is the first embed title
 * the body carries, else the first non-blank line of `content`, else the
 * hook's name, cut to TITLE_MAX_LENGTH characters. The message is `content`,
 * then for each embed its `description`, its `url`, and its fields, name
 * before value; a field with an empty name is its value alone, which is how
 * WUD's Discord trigger sends its body by default. Text passes through as
 * typed, with no Markdown conversion and no escaping. `username`,
 * `avatar_url`, `tts`, `allowed_mentions`, `components` and `attachments`
 * are ignored. A body that yields no title and no message is a bad request;
 * a body that yields a title and nothing else carries the title as its
 * message, so the vendor is never handed an empty one.
 */
final class HookPost
{
    public const NOT_FOUND = 'not_found';
    public const BAD_REQUEST = 'bad_request';
    public const ACCEPTED = 'accepted';

    /**
     * The width of xf_nf_tickets_ticket.title in characters. The vendor's
     * Creator refuses a longer title rather than trimming it, so the cut is
     * made here.
     */
    public const TITLE_MAX_LENGTH = 150;

    private string $outcome;

    private string $title;

    private string $message;

    private function __construct(string $outcome, string $title = '', string $message = '')
    {
        $this->outcome = $outcome;
        $this->title = $title;
        $this->message = $message;
    }

    /**
     * @param array|null  $hook          the hook's facts as ['active' => bool,
     *                                   'token_hash' => string, 'name' => string],
     *                                   or null when no hook has that id
     * @param string|null $pathToken     the token path part, or null when absent
     * @param string|null $authorization the Authorization header, or null when absent
     * @param mixed       $body          whatever json_decode($raw, true) returned
     */
    public static function decide(?array $hook, ?string $pathToken, ?string $authorization, $body): self
    {
        if ($hook === null || !$hook['active']) {
            return new self(self::NOT_FOUND);
        }

        if (!self::pathTokenMatches($pathToken, $hook['token_hash'])
            && !self::bearerMatches($authorization, $hook['token_hash'])
        ) {
            return new self(self::NOT_FOUND);
        }

        $content = self::text($body['content'] ?? null);
        $embeds = is_array($body['embeds'] ?? null) ? $body['embeds'] : [];

        $embedTitle = '';
        $parts = [];
        if ($content !== '') {
            $parts[] = $content;
        }
        foreach ($embeds as $embed) {
            if (!is_array($embed)) {
                continue;
            }
            if ($embedTitle === '') {
                $embedTitle = trim(self::text($embed['title'] ?? null));
            }
            foreach (['description', 'url'] as $key) {
                $value = self::text($embed[$key] ?? null);
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
            $fields = is_array($embed['fields'] ?? null) ? $embed['fields'] : [];
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                // WUD's Discord trigger sends an empty field name by default,
                // so a field with no name is its value alone.
                $name = self::text($field['name'] ?? null);
                $value = self::text($field['value'] ?? null);
                if ($name !== '' && $value !== '') {
                    $parts[] = $name . "\n" . $value;
                } elseif ($name !== '' || $value !== '') {
                    $parts[] = $name . $value;
                }
            }
        }

        // The title the body itself supplies, before the hook's name stands in.
        $bodyTitle = $embedTitle !== '' ? $embedTitle : self::firstLine($content);

        if ($parts === [] && $bodyTitle === '') {
            return new self(self::BAD_REQUEST);
        }
        if ($parts === []) {
            $parts[] = $bodyTitle;
        }

        return new self(
            self::ACCEPTED,
            mb_substr($bodyTitle !== '' ? $bodyTitle : $hook['name'], 0, self::TITLE_MAX_LENGTH, 'UTF-8'),
            implode("\n\n", $parts)
        );
    }

    /**
     * A body value as text: the string itself, or '' for anything that is not
     * a string. Discord's fields are strings or absent; nothing else counts.
     */
    private static function text($value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * The first non-blank line of a text, trimmed. '' when there is none.
     */
    private static function firstLine(string $text): string
    {
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }

    /**
     * Whether the token path part matches the hash. Either credential is
     * enough on its own: a proxy may add an Authorization header of its own,
     * and a correct URL still has to work behind it.
     */
    private static function pathTokenMatches(?string $pathToken, string $tokenHash): bool
    {
        return $pathToken !== null && Token::matches($pathToken, $tokenHash);
    }

    /**
     * Whether an Authorization header carries a bearer token matching the hash.
     * The scheme name is compared without regard to case, as RFC 7235 reads it.
     */
    private static function bearerMatches(?string $authorization, string $tokenHash): bool
    {
        if ($authorization === null || !preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $m)) {
            return false;
        }

        return Token::matches($m[1], $tokenHash);
    }

    /**
     * One of NOT_FOUND, BAD_REQUEST or ACCEPTED.
     */
    public function outcome(): string
    {
        return $this->outcome;
    }

    /**
     * The ticket's title. Empty unless accepted.
     */
    public function title(): string
    {
        return $this->title;
    }

    /**
     * The ticket's first message. Empty unless accepted.
     */
    public function message(): string
    {
        return $this->message;
    }
}
