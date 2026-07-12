<?php

namespace Cav7\MilpacMention;

/**
 * The one-request hand-off between the shared detection hook and the per-surface
 * firing extension. Detection runs inside XF\Service\Message\PreparerService::prepare()
 * and computes the milpac-only recipients; firing runs later, in the surface's
 * NotifierService, on the same content entity. They are different objects with no
 * direct reference to each other, so the recipients are stashed here keyed by the
 * content entity's object id and taken back out when the notifier runs.
 *
 * Object identity is the key on purpose: the reply/creator flow prepares and then
 * notifies the very same Post instance in one request (XF\Service\Thread\ReplierService
 * holds one $this->post throughout), so the notifier finds the stash. A resumed
 * Notifier job runs on a freshly loaded Post with a new object id and an empty
 * stash, so it fires no milpac — which is exactly the once-only, no-edit-refire
 * behaviour the rules want (spec §2.5 rules 2 and 4). take() is consuming, so even
 * a double notify() on the same object cannot double-fire.
 */
class MilpacStash
{
    /** @var array<int, list<int>> object id => milpac recipient user_ids */
    private static $byObjectId = [];

    /**
     * @param list<int> $userIds
     */
    public static function stash(object $contentEntity, array $userIds): void
    {
        if (!$userIds) {
            return;
        }
        self::$byObjectId[spl_object_id($contentEntity)] = array_values($userIds);
    }

    /**
     * The stashed recipients for this entity, removed on read so they fire once.
     *
     * @return list<int>
     */
    public static function take(object $contentEntity): array
    {
        $id = spl_object_id($contentEntity);
        $userIds = self::$byObjectId[$id] ?? [];
        unset(self::$byObjectId[$id]);

        return $userIds;
    }
}
