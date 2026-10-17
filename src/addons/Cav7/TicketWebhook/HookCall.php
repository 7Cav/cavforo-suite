<?php

namespace Cav7\TicketWebhook;

/**
 * What one call to a hook yields: not found, bad request, or accepted with
 * the title and message of the ticket to open, the hash of its alert key,
 * and what to do given the facts of the ticket that key links. See
 * GLOSSARY.md for hook, caller, call, alert key and token, and ADR-0002 for
 * why a key is a line in the call's text.
 *
 * Pure PHP with no XenForo import, so tests/HookCallTest.php runs it with
 * bare php. The public controller supplies the facts (the hook row, the path
 * token, the Authorization header, the decoded body) and acts on the answer.
 * It asks again with the facts of the key's linked ticket, and acts on that
 * answer too.
 *
 * Not found when there is no hook, the hook is inactive, or neither the path
 * token nor an `Authorization: Bearer` header matches its hash; either
 * credential is enough on its own. Credentials are checked before the body,
 * so a probe with a wrong token learns nothing from what it sends.
 *
 * The body is Discord's webhook request. The title is the title of the first
 * embed that has one (a wider reading than "the first embed's title", so a
 * leading embed with no title does not hide a titled one behind it), else the
 * first non-blank line of `content`, else the hook's name, cut to
 * TITLE_MAX_LENGTH characters. The message is `content`,
 * then for each embed its `description`, its `url`, and its fields, name
 * before value; a field with an empty name is its value alone, which is how
 * WUD's Discord trigger sends its body by default. Text passes through as
 * typed, with no Markdown conversion and no escaping. `username`,
 * `avatar_url`, `tts`, `allowed_mentions`, `components` and `attachments`
 * are ignored. A body that yields no title and no message is a bad request;
 * a body that yields a title and nothing else carries the title as its
 * message, so the vendor is never handed an empty one.
 *
 * A key line is `Alert key: <key>` on a line of its own, in `content`, in an
 * embed's `description` or in a field's `value`. The label matches in any
 * case and with spaces or tabs before it and around its colon. The key is
 * the rest of the line, trimmed and compared exactly. An embed's title and
 * url and a field's name are never read for one. Every key line leaves the
 * text before the title and message are built, so staff never read it and a
 * content that opens with one does not make it the title. The call has a key
 * only when its key lines all name the same non-empty key. An empty key, or
 * lines that disagree, as WUD's batch mode writes, leave it without one. A
 * call whose only text was key lines is accepted with the hook's name as its
 * title and message.
 *
 * A firing call replies to its key's ticket while that ticket is open, and
 * opens a new ticket when there is none or it is closed, locked or deleted.
 */
final class HookCall
{
    public const NOT_FOUND = 'not_found';
    public const BAD_REQUEST = 'bad_request';
    public const ACCEPTED = 'accepted';

    public const OPEN = 'open';
    public const REPLY = 'reply';

    /**
     * The width of xf_nf_tickets_ticket.title in characters. The vendor's
     * Creator refuses a longer title rather than trimming it, so the cut is
     * made here.
     */
    public const TITLE_MAX_LENGTH = 150;

    private string $outcome;

    private string $title;

    private string $message;

    private ?string $keyHash;

    private function __construct(string $outcome, string $title = '', string $message = '', ?string $keyHash = null)
    {
        $this->outcome = $outcome;
        $this->title = $title;
        $this->message = $message;
        $this->keyHash = $keyHash;
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

        $keys = [];
        $content = self::text(self::withoutKeyLines($body['content'] ?? null, $keys));
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
            $description = self::text(self::withoutKeyLines($embed['description'] ?? null, $keys));
            if ($description !== '') {
                $parts[] = $description;
            }
            $url = self::text($embed['url'] ?? null);
            if ($url !== '') {
                $parts[] = $url;
            }
            $fields = is_array($embed['fields'] ?? null) ? $embed['fields'] : [];
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }
                // WUD's Discord trigger sends an empty field name by default,
                // so a field with no name is its value alone.
                $name = self::text($field['name'] ?? null);
                $value = self::text(self::withoutKeyLines($field['value'] ?? null, $keys));
                if ($name !== '' && $value !== '') {
                    $parts[] = $name . "\n" . $value;
                } elseif ($name !== '' || $value !== '') {
                    $parts[] = $name . $value;
                }
            }
        }

        // The title the body itself supplies, before the hook's name stands in.
        $bodyTitle = $embedTitle !== '' ? $embedTitle : self::firstLine($content);

        if ($parts === [] && $bodyTitle === '' && $keys === []) {
            return new self(self::BAD_REQUEST);
        }
        if ($parts === []) {
            $parts[] = $bodyTitle !== '' ? $bodyTitle : $hook['name'];
        }

        return new self(
            self::ACCEPTED,
            mb_substr($bodyTitle !== '' ? $bodyTitle : $hook['name'], 0, self::TITLE_MAX_LENGTH, 'UTF-8'),
            implode("\n\n", $parts),
            self::keyHashOf($keys)
        );
    }

    /**
     * The hash of the one key the call's key lines name, or null when they
     * name none.
     */
    private static function keyHashOf(array $keys): ?string
    {
        $keys = array_values(array_unique($keys));
        if (count($keys) !== 1 || $keys[0] === '') {
            return null;
        }

        return hash('sha256', $keys[0], true);
    }

    /**
     * A body value with its key lines taken out, each key it named added to
     * $keys. Anything that is not a string comes back as it was.
     */
    private static function withoutKeyLines($value, array &$keys)
    {
        if (!is_string($value)) {
            return $value;
        }
        // Lines and the breaks between them, alternating, so the text that
        // stays keeps the breaks it was sent with.
        $pieces = preg_split('/(\R)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $kept = [];
        for ($i = 0; $i < count($pieces); $i += 2) {
            if (preg_match('/^[ \t]*alert key[ \t]*:(.*)$/i', $pieces[$i], $m)) {
                $keys[] = trim($m[1], " \t");
                continue;
            }
            $kept[] = $pieces[$i] . ($pieces[$i + 1] ?? '');
        }

        return implode('', $kept);
    }

    /**
     * A body value as text: the string itself, as typed, or '' for anything
     * that is not a string or is only whitespace. Discord's fields are strings
     * or absent; nothing else counts, and a blank one is no text at all.
     */
    private static function text($value): string
    {
        return is_string($value) && trim($value) !== '' ? $value : '';
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

    /**
     * The text of a reply to the key's ticket: the call's title on the first
     * line, then its message. The ticket keeps its own title, so this is
     * where a later call's title, such as Grafana's [FIRING:2], shows. A call
     * whose message is nothing but its title says it once.
     */
    public function replyMessage(): string
    {
        if (trim($this->message) === $this->title) {
            return $this->message;
        }

        return $this->title . "\n\n" . $this->message;
    }

    /**
     * A hash of the call's alert key, or null when it has none. The key itself
     * never leaves this class.
     */
    public function keyHash(): ?string
    {
        return $this->keyHash;
    }

    /**
     * What an accepted call does: OPEN a ticket, or REPLY to its key's linked
     * ticket.
     *
     * @param array|null $ticket the linked ticket's facts as ['closed' => bool,
     *                           'locked' => bool, 'deleted' => bool], or null
     *                           when the key links no ticket
     */
    public function action(?array $ticket): string
    {
        if ($ticket === null || $ticket['deleted'] || $ticket['locked'] || $ticket['closed']) {
            return self::OPEN;
        }

        return self::REPLY;
    }
}
