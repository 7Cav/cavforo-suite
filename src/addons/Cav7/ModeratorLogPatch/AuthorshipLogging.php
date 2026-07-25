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
 * extension nothing ever loads.
 *
 * Both overrides are thin. The decision is in {@see AuthorshipRule}, which the
 * ordinary test run covers because it needs nothing from XenForo, and who wrote the
 * content comes from {@see ContentAuthor}, which the verification command reads
 * through as well. What lives here is the actor, and deferring.
 */
trait AuthorshipLogging
{
    /**
     * The user-level gate, relaxed from "holds a moderator record" to "is logged
     * in". This is the gate the issue is about: each of the logger's three entry
     * points consults it once, on the way in and before anything else, and a false
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
     * tidiness: seven of the eight registered handlers already override this method
     * with rules of their own — XenForo's thread, post and both profile-post
     * handlers, both vendor ticket handlers, and NF/Calendar's event handler; only
     * XenForo's user handler does not — and returning true instead of delegating
     * would silently undo every one of them.
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
            ContentAuthor::userId($content)
        );

        if ($withheld) {
            return false;
        }

        return (bool) parent::isLoggable($content, $action, $actor);
    }
}
