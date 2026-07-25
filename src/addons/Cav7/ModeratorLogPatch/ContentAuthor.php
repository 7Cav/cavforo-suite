<?php

namespace Cav7\ModeratorLogPatch;

use XF\Mvc\Entity\Entity;

/**
 * Issue #187 — the one reading of who wrote a piece of content.
 *
 * Both the rule on the handler and the verification command need the author, and
 * both have to agree on what "no author" is: null, never 0. The rule compares the
 * author against the acting member, so 0 would let "nobody" match an actor with no
 * id and withhold every entry about guest-written content. Kept in one place because
 * the two copies of this walk had already drifted on exactly that value.
 *
 * One registered content type reads "author" as something else. XenForo's member
 * handler logs actions taken against a member and fills `content_user_id` from that
 * member's own id, so for `user` this returns the subject of the moderation rather
 * than somebody who wrote something, and the rule above reads "the actor is the
 * subject" where it says "the actor is the author". It is inert: no action logged
 * against a member is author-reachable. See
 * docs/adr/0006-two-cases-the-authorship-axis-cannot-express.md.
 */
final class ContentAuthor
{
    /**
     * The content's author, or null when the entity does not carry one.
     *
     * Every handler registered today logs content with a `user_id` column, and each
     * one already reads that column as the author when it fills the log row. Asked
     * for rather than assumed, because a handler registered by a later addon is
     * under no obligation to have it, and reading a column an entity does not
     * declare is a fatal error inside a save.
     */
    public static function userId(Entity $content): ?int
    {
        if (!$content->isValidColumn('user_id')) {
            return null;
        }

        $userId = (int) $content->get('user_id');

        return $userId > 0 ? $userId : null;
    }
}
