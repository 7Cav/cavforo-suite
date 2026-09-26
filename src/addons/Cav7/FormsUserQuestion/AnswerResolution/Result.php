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
}
