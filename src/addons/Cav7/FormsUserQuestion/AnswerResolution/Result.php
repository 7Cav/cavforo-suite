<?php

namespace Cav7\FormsUserQuestion\AnswerResolution;

/**
 * What AnswerResolution::resolve() decided about one answer: accepted or
 * refused, and for an accepted answer, the forum users it names. Pure PHP with
 * no XenForo dependency.
 *
 * A refused answer names nobody. The resolver stops at the first name that
 * refuses it, so any users found before that point are not a complete list and
 * are not kept.
 */
final class Result
{
    /** @var bool */
    private $accepted;

    /** @var list<array{id: int, username: string}> */
    private $users;

    /**
     * @param list<array{id: int, username: string}> $users
     */
    private function __construct(bool $accepted, array $users)
    {
        $this->accepted = $accepted;
        $this->users = $users;
    }

    /**
     * @param list<array{id: int, username: string}> $users distinct, in the
     *        order the filer first named them
     */
    public static function accepted(array $users): self
    {
        return new self(true, $users);
    }

    public static function refused(): self
    {
        return new self(false, []);
    }

    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    /**
     * @return list<array{id: int, username: string}> each forum user once, in
     *         the order the filer first named them; empty for a refused answer
     */
    public function users(): array
    {
        return $this->users;
    }

    /**
     * The answer as a thread post or conversation writes it: a [USER=id] link
     * to each forum user's profile, spelled the way the account spells it. The
     * id is what lasts, since the name inside the tag is only how the account
     * was spelled at submission. Empty when the answer names nobody.
     *
     * XenForo stores a mention in the same tag, but the link alerts nobody.
     * XenForo alerts only on typed `@Name` text, its mention parser skips
     * [USER] tags, and nothing here writes an `@`.
     */
    public function postText(): string
    {
        $links = [];
        foreach ($this->users as $user) {
            $links[] = '[USER=' . $user['id'] . ']' . $user['username'] . '[/USER]';
        }

        return implode(', ', $links);
    }

    /**
     * The answer as a title, the form log or an email writes it: each forum
     * user's username as it stood at submission, with no BB code. Empty when
     * the answer names nobody.
     */
    public function plainText(): string
    {
        return implode(', ', array_column($this->users, 'username'));
    }
}
