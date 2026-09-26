<?php

namespace Cav7\FormsUserQuestion;

use Cav7\FormsUserQuestion\AnswerResolution\Result;

/**
 * Decides whether a forum user question's answer is accepted, and which forum
 * users it names. Pure PHP with no XenForo dependency, so the rule runs for real
 * in tests/AnswerResolutionTest.php. The entity extension
 * (Snog\Forms\Entity\Question) supplies the account lookup, a user finder query
 * per name, and turns a refusal into the question's error.
 *
 * Names are compared only by that lookup, never in PHP here. Two names are the
 * same forum user when they find the same account id.
 *
 * Splitting on commas is safe because XenForo refuses a comma in a username.
 */
final class AnswerResolution
{
    /**
     * @param string $raw the answer as the form posted it
     * @param bool $takesSeveral false for a "Forum user" question, which
     *        accepts one forum user at most
     * @param callable(string): (array{id: int, username: string}|null) $findAccount
     *        maps one trimmed, non-empty name to the account it belongs to, or
     *        null when it belongs to none
     */
    public static function resolve(string $raw, bool $takesSeveral, callable $findAccount): Result
    {
        $users = [];

        foreach (explode(',', $raw) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $account = $findAccount($name);
            if ($account === null) {
                return Result::refused();
            }

            $id = (int) $account['id'];
            if (!isset($users[$id])) {
                $users[$id] = ['id' => $id, 'username' => (string) $account['username']];
            }
        }

        if (!$takesSeveral && count($users) > 1) {
            return Result::refused();
        }

        return Result::accepted(array_values($users));
    }
}
