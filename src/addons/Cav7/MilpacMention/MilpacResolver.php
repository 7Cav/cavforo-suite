<?php

namespace Cav7\MilpacMention;

/**
 * The shared reverse resolver for the milpac-mention engine: a milpac is one
 * NF\Rosters:RosterUser row whose profile URL is /rosters/profile/<relation_id>/,
 * and the row carries the member's user_id. This class turns a prepared message
 * into the set of members to alert, in three pure steps plus one database step:
 *
 *   extractRelationIds()  regex the relation_ids out of the message  (§2.2)
 *   resolveUserIds()      relation_id -> user_id via the roster finder (§2.3)
 *   userIdsFromMap()      shape the finder result into ordered user_ids (§2.3)
 *   milpacRecipients()    apply the firing rules to pick who is alerted (§2.5)
 *
 * The three pure methods carry no XenForo dependency, so the detection regex, the
 * mapping shape, and the cap/dedup rules are unit-tested in plain PHP. Only
 * resolveUserIds() touches \XF, so requiring this file in a test is safe as long
 * as that method is not called.
 */
class MilpacResolver
{
    /**
     * Every relation_id linked in a prepared message, de-duplicated and in
     * first-seen order. #/rosters/profile/(\d+)# matches one path segment, which
     * catches both a bare auto-linked URL and a [URL=...] hyperlink, relative or
     * canonical; an optional -slug after the int is ignored (the route is
     * profile/:int<relation_id,username>/). relation_id 0 does not exist, so a
     * /rosters/profile/0/ link resolves to nothing.
     *
     * @return list<int>
     */
    public static function extractRelationIds(string $message): array
    {
        if (!preg_match_all('#/rosters/profile/(\d+)#', $message, $matches)) {
            return [];
        }

        $ids = [];
        foreach ($matches[1] as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $ids[$id] = true; // keyed for de-dup; first-seen order preserved
            }
        }

        return array_keys($ids);
    }

    /**
     * relation_id -> user_id for a set of relation_ids, via the NF\Rosters
     * RosterUser finder run in reverse (the forward direction is what
     * Cav7\MilpacTooltip uses). relation_id is the primary key and user_id is a
     * column, so this is a straight lookup with no tiebreak: one user = one
     * milpac = one relation_id is a roster invariant (§2.3).
     *
     * @param list<int> $relationIds
     *
     * @return list<int> the resolved user_ids, ordered and de-duplicated
     */
    public static function resolveUserIds(array $relationIds): array
    {
        if (!$relationIds) {
            return [];
        }

        $map = \XF::finder('NF\Rosters:RosterUser')
            ->where('relation_id', $relationIds)
            ->fetch()
            ->pluckNamed('user_id', 'relation_id'); // [relation_id => user_id]

        return self::userIdsFromMap($relationIds, $map);
    }

    /**
     * Shape the finder's [relation_id => user_id] result into the ordered,
     * de-duplicated list of user_ids to consider. Order follows the input
     * relation_ids (first-seen), a relation_id with no row is skipped, and a
     * member reached by more than one relation_id appears once.
     *
     * @param list<int>         $relationIds
     * @param array<int, int>   $relationToUserId
     *
     * @return list<int>
     */
    public static function userIdsFromMap(array $relationIds, array $relationToUserId): array
    {
        $userIds = [];
        foreach ($relationIds as $relationId) {
            $relationId = (int) $relationId;
            if (!isset($relationToUserId[$relationId])) {
                continue;
            }
            $userId = (int) $relationToUserId[$relationId];
            if ($userId > 0) {
                $userIds[$userId] = true; // keyed for de-dup; first-seen order kept
            }
        }

        return array_keys($userIds);
    }

    /**
     * The milpac-only members to alert, after the six firing rules (§2.5) that the
     * distinct milpac_mention alert must enforce itself:
     *
     *   1. self-links suppressed  — the author's own user_id is dropped.
     *   2. one alert per member   — the milpac set is de-duplicated.
     *   5. dedup with @, prefer @ — any member already in the @-mention set is
     *      dropped, so a member both @-mentioned and milpac-linked gets only the
     *      native @ alert.
     *   6. shared author cap      — milpac links count against the same
     *      maxMentionedUsers budget as @-mentions, @ kept and milpac dropped first
     *      on overflow. The cap mirrors XF\Entity\User::getAllowedUserMentions:
     *      0 => none at all, < 0 => unlimited, else the first N of (@ ++ milpac).
     *      With @ counted first, milpac keeps only the budget the @ set leaves.
     *
     * (Rule 3 "every roster notifies" is inherent — resolution applies no
     * per-roster filter; rule 4 "no alert on edit" is structural — there is no
     * edit-time firing path.)
     *
     * @param list<int> $atUserIds     the content's @-mention set (author-capped upstream is irrelevant here; the raw set defines membership and budget)
     * @param list<int> $milpacUserIds the resolved milpac user_ids, in link order
     * @param int       $authorUserId  the message author
     * @param int       $cap           the author's maxMentionedUsers permission value
     *
     * @return list<int> the user_ids to raise milpac_mention for, in order
     */
    public static function milpacRecipients(
        array $atUserIds,
        array $milpacUserIds,
        int $authorUserId,
        int $cap
    ): array {
        $atSet = [];
        foreach ($atUserIds as $atUserId) {
            $atSet[(int) $atUserId] = true;
        }

        $milpac = [];
        foreach ($milpacUserIds as $userId) {
            $userId = (int) $userId;
            if ($userId === $authorUserId) {
                continue; // rule 1: never alert the author about their own link
            }
            if (isset($atSet[$userId])) {
                continue; // rule 5: the @ alert wins; drop the milpac duplicate
            }
            $milpac[$userId] = true; // rule 2: keyed for de-dup, first-seen order
        }
        $milpac = array_keys($milpac);

        // rule 6: the shared maxMentionedUsers cap.
        if ($cap === 0) {
            return []; // the author may mention nobody
        }
        if ($cap < 0) {
            return $milpac; // unlimited
        }

        // @-mentions hold their slots first; milpac fills only the remainder.
        $remaining = $cap - count($atSet);
        if ($remaining <= 0) {
            return [];
        }

        return array_slice($milpac, 0, $remaining);
    }
}
