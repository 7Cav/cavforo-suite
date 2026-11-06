<?php

namespace Cav7\Citations\Grant;

/**
 * Which grant pages open for whom, and the one table every action answers to, GET or POST.
 * An action not listed here refuses.
 */
final class PageAccess
{
    /**
     * @param string $action The controller's action, lower case with no separators.
     * @param bool|null $regiment The grant's kind. Null when none is known yet, which asks
     *     whether any kind could open it, as a blank grant form does.
     */
    public static function allows(string $action, Actor $actor, ?bool $regiment): bool
    {
        return match ($action) {
            'index' => $regiment === null ? $actor->canOpenGrantList() : $actor->canOpenGrant($regiment),
            'issue', 'form', 'preview', 'correct', 'move' => $regiment === null
                ? $actor->canIssue(false) || $actor->canIssue(true)
                : $actor->canIssue($regiment),
            'addmember' => $regiment !== true && $actor->canIssue(false),
            'removemember' => $regiment !== true && $actor->canRemoveMembers(),
            'withdraw' => $regiment !== false && $actor->canIssue(true),
            default => false,
        };
    }
}
