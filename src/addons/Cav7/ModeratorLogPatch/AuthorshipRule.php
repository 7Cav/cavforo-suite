<?php

namespace Cav7\ModeratorLogPatch;

use function in_array;

/**
 * Issue #187 — decides whether this addon withholds a moderator log entry.
 *
 * XenForo's per-action check answers "should this be logged?". This addon adds one
 * rule in front of it and nothing else, so the only answer produced here is
 * "withheld". Every other case defers to the handler underneath, which is what
 * leaves a vendor handler's own rule — and, for a member who holds a moderator
 * record, XenForo's entire existing behaviour — in force. See
 * docs/adr/0001-log-by-authorship-not-by-permission.md.
 *
 * Pure: no XenForo, no database, no I/O. The trait that sits on each handler reads
 * the actor and the content and calls this; every decision lives here.
 */
class AuthorshipRule
{
    /**
     * The **author-reachable action**s: the logged actions a member can produce on
     * their own content holding only own-content permissions. For these, and only
     * these, the action name alone does not say whether moderation happened.
     *
     * Every other logged action — stick, unstick, lock, unlock, move, discussion
     * type and search indexing changes, approve, unapprove, undelete, hard delete,
     * spam clean, user approve and reject, warnings, reassign, merge, feature —
     * needs authority over somebody else's content before it can be reached, so it
     * is moderation whoever wrote the content.
     *
     * These are the resolved action names XenForo writes to the log, not entity
     * field names: a prefix change is logged as `prefix` (the field is `prefix_id`)
     * and a custom-field change as `custom_fields_edit` (the field is
     * `custom_fields`).
     *
     * `attachment_deleted` and `poll_reset` were added after the first eight were
     * settled; docs/adr/0004-two-more-author-reachable-actions.md records the
     * evidence for each.
     *
     * @var list<string>
     */
    public const AUTHOR_REACHABLE_ACTIONS = [
        'edit',
        'attachment_deleted',
        'title',
        'prefix',
        'custom_fields_edit',
        'delete_soft',
        'status',
        'priority',
        'poll_create',
        'poll_edit',
        'poll_delete',
        'poll_reset',
    ];

    /**
     * True when this addon withholds the entry. False means "defer" — hand the
     * question to the handler underneath and let its answer stand.
     *
     * @param string   $action                     The resolved action name, as it
     *                                             would be written to the log.
     * @param int      $actorUserId                The acting member. 0 for a guest.
     * @param bool     $actorHoldsModeratorRecord  Whether the actor holds a
     *                                             **moderator record** (`is_moderator`).
     * @param int|null $contentUserId              The content's author, or null when
     *                                             the entity has no readable author.
     */
    public static function withholdsEntry(
        string $action,
        int $actorUserId,
        bool $actorHoldsModeratorRecord,
        ?int $contentUserId
    ): bool
    {
        // Rule 1. A member who holds a moderator record is logged exactly as
        // XenForo logs them today, because this rule steps aside entirely and the
        // handler underneath decides. No existing moderator can regress.
        if ($actorHoldsModeratorRecord) {
            return false;
        }

        // Rule 3, taken early because it is the common case: an action outside the
        // author-reachable set is moderation by definition and always logs.
        if (!self::isAuthorReachable($action)) {
            return false;
        }

        // Rule 2. Author-reachable, so the entry is written only when somebody
        // acted on content they did not write.
        //
        // Both ids have to name a real member. Guest-authored content carries
        // user_id 0 and the log's actor column falls back to 0 for an actor with no
        // id, so a bare equality test would read "nobody" as a match and withhold
        // every entry about guest-written content.
        return $actorUserId > 0
            && $contentUserId !== null
            && $actorUserId === $contentUserId;
    }

    /**
     * Whether $action is one a member can produce on their own content with
     * own-content permissions alone. Matched exactly: a loose match would swallow
     * an action nobody has reviewed, and swallowing is the fault this addon removes.
     */
    public static function isAuthorReachable(string $action): bool
    {
        return in_array($action, self::AUTHOR_REACHABLE_ACTIONS, true);
    }
}
