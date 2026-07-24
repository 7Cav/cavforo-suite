<?php

namespace Cav7\ModeratorLogPatch;

use XF\Entity\User;
use XF\Mvc\Entity\Entity;

/**
 * Issue #187 — the two overrides this addon puts on a moderator log handler.
 *
 * One copy of the behaviour, composed into one subclass per registered handler
 * class. It has to be a trait rather than a shared base class: XenForo's
 * class-extension chain fixes each subclass's parent to the XFCP proxy of the
 * handler it extends, and the abstract handler those handlers share is never
 * resolved through the extension system, so extending it would register an
 * extension nothing ever loads. See
 * docs/adr/0002-one-extension-per-registered-handler.md.
 *
 * Both overrides are thin. The decision is in {@see AuthorshipRule}, which the
 * ordinary test run covers because it needs nothing from XenForo. What lives here
 * is reading the actor and the content, and deferring.
 *
 * @method bool isLoggable(Entity $content, string $action, User $actor)
 */
trait AuthorshipLogging
{
    /**
     * The user-level gate, relaxed from "holds a moderator record" to "is logged
     * in". This is the gate the issue is about: the handler's own answer is
     * consulted once, before all three of the logger's entry points, and a false
     * throws the entry away with no exception and no warning.
     *
     * Not deferred, because the parent's answer is the thing being replaced —
     * `user_id && is_moderator` is exactly the identity gate this addon removes, and
     * chaining it could only ever re-impose it. Everything the parent still gets to
     * decide moved to the per-action check below, which does defer.
     *
     * Guests keep failing it, on the same `user_id` test XenForo already applied.
     * That covers the cron runner and the system actors that run as a guest, so
     * scheduled work still writes nothing.
     */
    public function isLoggableUser(User $actor): bool
    {
        return (bool) $actor->user_id;
    }

    /**
     * The per-action check, where the authorship rule belongs. It receives the
     * resolved action name — `prefix`, not the `prefix_id` field it came from — so
     * the rule keys on actions.
     *
     * Withholding is the only answer this override produces on its own; every other
     * case is handed straight to the handler underneath. That matters beyond
     * tidiness: XenForo's own thread, post and profile-post handlers and both
     * vendor ticket handlers already override this method with rules of their own,
     * and returning true instead of delegating would silently undo every one of
     * them.
     *
     * @param Entity $content
     * @param string $action
     */
    public function isLoggable(Entity $content, $action, User $actor): bool
    {
        $withheld = AuthorshipRule::withholdsEntry(
            (string) $action,
            (int) $actor->user_id,
            (bool) $actor->is_moderator,
            $this->getContentAuthorUserId($content)
        );

        if ($withheld) {
            return false;
        }

        return (bool) parent::isLoggable($content, $action, $actor);
    }

    /**
     * The content's author, or null when the entity does not carry one.
     *
     * Every handler registered today logs content with a `user_id` column, and each
     * one already reads that column as the author when it fills the log row. Asked
     * for rather than assumed, because a handler registered by a later addon is
     * under no obligation to have it, and reading a column an entity does not
     * declare is a fatal error inside a save.
     *
     * Null and 0 both mean "no author", and neither may match an actor: see the
     * guard in {@see AuthorshipRule::withholdsEntry()}.
     */
    protected function getContentAuthorUserId(Entity $content): ?int
    {
        if (!$content->isValidColumn('user_id')) {
            return null;
        }

        $userId = (int) $content->get('user_id');

        return $userId > 0 ? $userId : null;
    }
}
