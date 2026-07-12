<?php

namespace Cav7\MilpacMention;

/**
 * The shared resolver for the milpac-mention engine, serving both directions of the
 * milpac/member relation. A milpac is one NF\Rosters:RosterUser row whose profile
 * URL is /rosters/profile/<relation_id>/, and the row carries the member's user_id.
 *
 * The reverse-resolution path (phase 1) turns a prepared message into the set of
 * members to alert, in three pure steps plus one database step:
 *
 *   extractRelationIds()  regex the relation_ids out of the message  (§2.2)
 *   resolveUserIds()      relation_id -> user_id via the roster finder (§2.3)
 *   userIdsFromMap()      shape the finder result into ordered user_ids (§2.3)
 *   milpacRecipients()    apply the firing rules to pick who is alerted (§2.5)
 *
 * The find-endpoint path (phase 2) drives the $name completer the other way, from a
 * typed query to the milpac-owning members it may insert as named profile links:
 *
 *   isFindQueryLongEnough()  the two-character q guard before any query  (§4.3)
 *   findMilpacOwningUsers()  join the milpac owners inside the query      (§4.3)
 *   milpacDisplayText()      shape "Rank Name" for the dropdown/anchor    (§4.2/§4.5)
 *   milpacLinkHtml()         wrap that text in an html-escaped anchor     (§4.2)
 *
 * The pure methods carry no XenForo dependency, so the detection regex, the mapping
 * shape, the cap/dedup rules, and the completer's q-guard and row shaping are all
 * unit-tested in plain PHP. The only \XF references are resolveUserIds() and
 * findMilpacOwningUsers() (each runs a live finder) and the PCRE-failure branch of
 * extractRelationIds() — none reached by the happy path, so requiring this file and
 * exercising detection/mapping/firing and the pure find builders in a test is safe
 * with no XenForo, as long as the two finder methods are not called and no regex
 * actually fails.
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
        $count = preg_match_all('#/rosters/profile/(\d+)#', $message, $matches);
        if ($count === false) {
            // A PCRE engine failure (backtrack/recursion limit, bad UTF-8) returns
            // false, which is distinct from 0 "no matches". Left as `!preg_match_all`
            // it would collapse into the same `return []` and silently drop
            // detection for the whole message. Log it so the drop is observable,
            // then fail closed. This \XF:: reference sits only on this error branch,
            // which the pure unit tests never reach — the happy path still loads and
            // runs under bare php with no XenForo (mirrors SteamChecker's fail-loud
            // preg guards).
            \XF::logError('[Cav7/MilpacMention] relation_id extraction failed (PCRE: ' . preg_last_error_msg() . ')');
            return [];
        }
        if (!$count) {
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

    // =====================================================================
    // The $name completer's find endpoint (phase 2, spec §4.3). The endpoint
    // shares this resolver so the join lives in one place; the two pure builders
    // below shape each result row and are unit-tested in plain PHP.
    // =====================================================================

    /**
     * The q-length guard for the find endpoint, mirroring
     * XF\Pub\Controller\MemberController::actionFind's
     * `$q !== '' && Str::strlen($q) >= 2`: a query shorter than two characters
     * returns an empty result set rather than a query. Multibyte-aware, so two
     * accented characters count as two, not their byte length.
     */
    public static function isFindQueryLongEnough(string $q): bool
    {
        return $q !== '' && mb_strlen($q) >= 2;
    }

    /**
     * The dropdown's primary line and the inserted anchor's text (§4.2/§4.5):
     * "Rank Name" (e.g. "Corporal Banfield.H"), or the name alone for an unranked
     * member. Pure so the shape is unit-tested without XenForo.
     */
    public static function milpacDisplayText(string $rankTitle, string $username): string
    {
        $rankTitle = trim($rankTitle);

        return $rankTitle !== '' ? $rankTitle . ' ' . $username : $username;
    }

    /**
     * The value the completer inserts (§4.2): a NAMED anchor, never a bare URL.
     * The rich editor inserts this HTML and Froala serialises it back to
     * [URL='…/rosters/profile/<relation_id>/']Rank Name[/URL] on save — the exact
     * artifact the phase-1 engine already detects (§2.2). Both the href and the
     * text are html-escaped so a quote or ampersand cannot break out of the
     * attribute or the tag. Pure so the shape is unit-tested without XenForo.
     */
    public static function milpacLinkHtml(string $displayText, string $profileUrl): string
    {
        return '<a href="'
            . htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8')
            . '">'
            . htmlspecialchars($displayText, ENT_QUOTES, 'UTF-8')
            . '</a>';
    }

    /**
     * The milpac-owning members whose username matches $q, for the $name completer
     * (§4.3). Models XF\Pub\Controller\MemberController::actionFind and adds the
     * roster join INSIDE the query, before the fetch limit, so the result is
     * $limit milpac-owning actives — not $limit actives then filtered:
     *
     *   - username LIKE "q%"          the completer's prefix match (escapeLike '?%')
     *   - isValidUser(true)           unbanned, user_state=valid, active within 180
     *                                 days — banned/dormant/memorial members fall out
     *   - INNER JOIN NF\Rosters:RosterUser on user_id (with('Milpac', true), the
     *                                 mustExist flag), so a member with no milpac is
     *                                 dropped by the join the way a non-mentionable
     *                                 user is absent from @ results. RosterUser is
     *                                 TO_ONE here — the one-user-one-milpac invariant
     *                                 (§4.4) keeps the join to one row per member.
     *
     * Rank and Roster are joined for the dropdown row (§4.5). The 'Milpac' relation
     * is hand-registered as a TO_ONE — the inverse of NF\Rosters:RosterUser's own
     * 'User' relation — directly on the User entity structure, not through
     * Finder::withEntity(): withEntity() would register a LEFT join, and this needs
     * the INNER join that with('Milpac', true) issues. getStructure() hands back the
     * request-shared, cached XF:User Structure object, so the mutation persists for
     * the rest of the request exactly as a withEntity() registration would; the
     * if (!isset(...)) guard makes re-registration on a later finder a no-op.
     *
     * @param \XF\Finder\UserFinder $userFinder
     *
     * @return \XF\Mvc\Entity\AbstractCollection
     */
    public static function findMilpacOwningUsers($userFinder, string $q, int $limit = 10)
    {
        $structure = $userFinder->getStructure();
        if (!isset($structure->relations['Milpac'])) {
            $structure->relations['Milpac'] = [
                'entity' => 'NF\Rosters:RosterUser',
                'type' => \XF\Mvc\Entity\Entity::TO_ONE,
                'conditions' => 'user_id',
                'primary' => true,
            ];
        }

        return $userFinder
            ->where('username', 'like', $userFinder->escapeLike($q, '?%'))
            ->isValidUser(true)
            ->with('Milpac', true)   // INNER JOIN: only milpac owners survive, before the limit
            ->with('Milpac.Rank')    // rank title for the dropdown row (§4.5)
            ->with('Milpac.Roster')  // roster title for the dropdown row (§4.5)
            ->fetch($limit);
    }
}
