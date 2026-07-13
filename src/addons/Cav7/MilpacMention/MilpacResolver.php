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
 *   isFindQueryLongEnough()     the two-character q guard before any query  (§4.3)
 *   findMilpacOwningUsers()     join the milpac owners inside the query      (§4.3)
 *   milpacDisplayText()         shape "Rank Name" for the dropdown/anchor    (§4.2/§4.5)
 *   milpacLinkHtml()            wrap that text in an html-escaped anchor     (§4.2)
 *   logMilpacOwnerDuplicates()  log a shown member owning >1 roster row      (§4.4)
 *
 * The pure methods carry no XenForo dependency, so the detection regex, the mapping
 * shape, the cap/dedup rules, and the completer's q-guard and row shaping are all
 * unit-tested in plain PHP. The only \XF references are resolveUserIds(),
 * findMilpacOwningUsers() and logMilpacOwnerDuplicates() (each runs a live finder) and
 * the PCRE-failure branch of extractRelationIds() — none reached by the happy path, so
 * requiring this file and exercising detection/mapping/firing, the pure find builders
 * and the dedupeMilpacOwners reducer in a test is safe with no XenForo, as long as the
 * three finder methods are not called and no regex actually fails.
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
     * Cav7\MilpacTooltip uses). relation_id is the primary key, so each
     * relation_id maps to exactly one user_id — a straight PK lookup with no
     * tiebreak. (A user may own more than one relation_id; the de-dup below
     * collapses them.)
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
     * One milpac per user is the intended rule, but the roster schema does NOT
     * enforce it (xf_nf_rosters_user carries a non-unique user_id index; live data
     * has a member with two rows). So a message that links two roster rows of the
     * same member is possible: the two relation_ids collapse to a single alert
     * target, and collapseMilpacsByUser logs the conflict as a data error so it is
     * visible rather than silently absorbed (#112).
     *
     * @param list<int>         $relationIds
     * @param array<int, int>   $relationToUserId
     * @param callable|null     $logger fn(string): void for the duplicate data-error;
     *                                  null defaults to \XF::logError in production
     *
     * @return list<int>
     */
    public static function userIdsFromMap(array $relationIds, array $relationToUserId, ?callable $logger = null): array
    {
        $pairs = [];
        foreach ($relationIds as $relationId) {
            $relationId = (int) $relationId;
            if (!isset($relationToUserId[$relationId])) {
                continue; // no row for this link (deleted roster row / bad link)
            }
            $pairs[] = [(int) $relationToUserId[$relationId], $relationId];
        }

        return self::collapseMilpacsByUser($pairs, $logger);
    }

    /**
     * The shared "one milpac per user" reducer for both directions of the relation.
     * Collapses ordered (user_id, relation_id) associations to one user_id per
     * member, first-seen order, and reports any member owning more than one milpac
     * as a data error through $logger. One milpac per user is the intended rule but
     * xf_nf_rosters_user does not enforce it, so a member CAN own two roster rows;
     * the lowest relation_id is kept as canonical — the same deterministic pick #96
     * gave the lazy $user->Milpac — and the extras are logged (#112). A non-positive
     * user_id (a row with no real member) is dropped.
     *
     * Pure and logger-injected so both userIdsFromMap (the reverse/alerting path) and
     * dedupeMilpacOwners (the $name completer) are exercised without XenForo: a
     * standalone test passes a capturing callable, production passes null and the
     * duplicate branch falls back to \XF::logError.
     *
     * @param list<array{0:int, 1:int}> $pairs (user_id, relation_id), in order
     * @param callable|null             $logger fn(string): void; null => \XF::logError
     *
     * @return list<int> the de-duplicated user_ids, first-seen order
     */
    private static function collapseMilpacsByUser(array $pairs, ?callable $logger): array
    {
        $relationsByUser = []; // user_id => list<int> every relation_id seen for them
        $order = [];           // first-seen user order
        foreach ($pairs as $pair) {
            $userId = (int) $pair[0];
            $relationId = (int) $pair[1];
            if ($userId <= 0) {
                continue; // a 0 user_id is not a real recipient
            }
            if (!isset($relationsByUser[$userId])) {
                $relationsByUser[$userId] = [];
                $order[] = $userId;
            }
            if ($relationId > 0) {
                $relationsByUser[$userId][] = $relationId;
            }
        }

        foreach ($order as $userId) {
            $relationIds = array_values(array_unique($relationsByUser[$userId]));
            if (count($relationIds) > 1) {
                self::logMilpacDataError($userId, $relationIds, $logger);
            }
        }

        return $order;
    }

    /**
     * Log a "member owns more than one milpac" data error, naming the member and
     * every conflicting relation_id, and stating which one is kept (the lowest). The
     * message makes the unenforced one-milpac-per-user rule and the offending rows
     * visible in the error log. $logger is the injectable seam (a test captures it);
     * null falls back to the real \XF::logError, the same fail-loud pattern
     * extractRelationIds uses — reached only on the duplicate branch, never by the
     * pure happy-path tests.
     *
     * @param list<int>     $relationIds the member's conflicting relation_ids
     * @param callable|null $logger
     */
    private static function logMilpacDataError(int $userId, array $relationIds, ?callable $logger): void
    {
        sort($relationIds); // lowest first: the kept relation_id and a stable message
        $message = '[Cav7/MilpacMention] data error: member user_id ' . $userId
            . ' owns more than one milpac (relation_id ' . implode(', ', $relationIds)
            . '); one milpac per user is the intended rule but xf_nf_rosters_user does'
            . ' not enforce it, so keeping the lowest (relation_id ' . $relationIds[0]
            . ') and treating the rest as bad data';

        if ($logger !== null) {
            $logger($message);
        } else {
            \XF::logError($message);
        }
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
        return $q !== '' && mb_strlen($q, 'UTF-8') >= 2;
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
     *
     * Assumes the caller supplies a framework-built absolute URL — the view passes
     * buildLink('canonical:rosters/profile', …). The htmlspecialchars() here escapes
     * for attribute/text safety; it does NOT scheme-validate the href.
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
     * The $name completer's shared reducer over (user_id, relation_id) roster rows
     * (spec §4.4). One milpac per user is the intended rule, but xf_nf_rosters_user
     * does not enforce it (non-unique user_id index; live data has a member with two
     * rows), so a member can own more than one row. Collapse to one user_id per member,
     * keep the LOWEST relation_id — the same deterministic pick #96 gave the lazy
     * $user->Milpac — and log any member owning more than one as a data error so the
     * bad data is visible.
     *
     * logMilpacOwnerDuplicates feeds this the RAW roster rows read straight from the
     * table, where a member's duplicate rows are still both present — the completer's
     * own hydrated collection cannot be used, because XF's identity map has already
     * collapsed the join to one entity per user_id before the view sees it, hiding the
     * duplicate. Pure and logger-injected so the collapse + duplicate logging is
     * unit-tested without XenForo: production passes no logger (the duplicate branch
     * falls back to \XF::logError via collapseMilpacsByUser), a standalone test passes
     * a capturing callable. Shares the reducer (and its data-error log) with the reverse
     * alerting path.
     *
     * @param list<array{user_id:int, relation_id:int}> $rows raw roster rows, in query order
     * @param callable|null $logger fn(string): void; null => \XF::logError
     *
     * @return list<int> the user_ids, one per member, first-seen order
     */
    public static function dedupeMilpacOwners(array $rows, ?callable $logger = null): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            $pairs[] = [(int) ($row['user_id'] ?? 0), (int) ($row['relation_id'] ?? 0)];
        }

        return self::collapseMilpacsByUser($pairs, $logger);
    }

    /**
     * Log the completer's "member owns more than one milpac" data error, detected from
     * the RAW roster rows of the members the dropdown is about to show (§4.4, #112).
     *
     * The completer view cannot see the duplicate on its own: findMilpacOwningUsers
     * INNER-joins the roster and orders by relation_id, and XF's identity map keys the
     * fetched collection by user_id, so two roster rows for one member collapse to a
     * single hydrated User entity (carrying the lowest relation_id) BEFORE the view
     * iterates. The functional output is already correct — one dropdown entry, lowest
     * milpac kept — but the extra row is invisible to that collection, so a plain
     * collapse of the hydrated rows can never log a real duplicate. To keep this
     * persistent data error visible, re-read the roster rows themselves: one indexed
     * query over the shown user_ids returns every (user_id, relation_id) pair, including
     * the second row the identity map hid, and the shared reducer logs any member with
     * more than one (naming the user and every relation_id, keeping the lowest).
     *
     * The reducer's collapsed return is discarded — this method exists only for the
     * data-error log. The dropdown's shown set, order, and kept milpac are already
     * decided upstream. Scoped to the matched user_ids and skipped when nothing is
     * shown, so it is one cheap query, not per row. It logs each time the completer
     * surfaces the member, which is intended: the error is persistent and the visibility
     * mirrors the alerting path. The only \XF reference is the finder here.
     *
     * @param list<int>     $userIds the user_ids the dropdown is showing
     * @param callable|null $logger  fn(string): void; null => \XF::logError (via the reducer)
     */
    public static function logMilpacOwnerDuplicates(array $userIds, ?callable $logger = null): void
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        )));
        if (!$userIds) {
            return; // nothing shown, so no query and nothing to check
        }

        $rows = \XF::finder('NF\Rosters:RosterUser')
            ->where('user_id', $userIds)
            ->order('user_id')
            ->order('relation_id')
            ->fetchColumns('user_id', 'relation_id'); // raw [user_id, relation_id] rows, pre-hydration

        // Route the raw rows through the shared reducer purely for its data-error log;
        // a member with two roster rows appears twice here (unlike the hydrated
        // collection), so collapseMilpacsByUser/logMilpacDataError finally fire (#112).
        self::dedupeMilpacOwners($rows, $logger);
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
     *                                 TO_ONE to model the EXPECTED one-milpac-per-user
     *                                 shape — a convention the schema does NOT enforce
     *                                 (non-unique user_id index; live data has a user
     *                                 with two rows), so the join is not guaranteed to
     *                                 be one row per member: a member with multiple
     *                                 roster rows can match more than once. Listener.php
     *                                 orders the relation by relation_id for a
     *                                 deterministic lazy $user->Milpac pick.
     *
     * Ordered by username, then Milpac.relation_id: the relation's 'order' rides only
     * the lazy $user->Milpac fetch, not this with('Milpac', true) INNER join, so the
     * finder sets its own ORDER BY. username gives the dropdown a stable, human order;
     * the relation_id tiebreak makes a member with two roster rows deterministic: XF's
     * identity map keys the fetched collection by user_id and keeps the first row, so
     * relation_id ASC hands this the LOWEST milpac, the same pick #96 gave the lazy
     * relation (§4.4). That makes the surviving row's milpac canonical rather than
     * arbitrary; the completer view renders that collapsed entity directly, and the raw
     * duplicate is detected and logged separately by logMilpacOwnerDuplicates (§4.4, #112).
     *
     * Rank and Roster are joined for the dropdown row (§4.5). The 'Milpac' relation
     * this leans on is the inverse of NF\Rosters:RosterUser's own 'User' relation,
     * declared once on XF:User by Cav7\MilpacMention\Listener::userEntityStructure
     * (an entity_structure listener) — a first-class, greppable relation, not a
     * query-time mutation of the request-shared structure. with('Milpac', true)
     * issues the INNER join this needs, where Finder::withEntity() would only LEFT
     * join. See that listener's docblock for why the relation deliberately omits
     * 'primary' (its join key user_id is not RosterUser's PK relation_id, so
     * 'primary' would misresolve a lazy $user->Milpac to a PK lookup).
     *
     * @param \XF\Finder\UserFinder $userFinder
     *
     * @return \XF\Mvc\Entity\AbstractCollection
     */
    public static function findMilpacOwningUsers($userFinder, string $q, int $limit = 10)
    {
        return $userFinder
            ->where('username', 'like', $userFinder->escapeLike($q, '?%'))
            ->isValidUser(true)
            ->with('Milpac', true)   // INNER JOIN: only milpac owners survive, before the limit
            ->with('Milpac.Rank')    // rank title for the dropdown row (§4.5)
            ->with('Milpac.Roster')  // roster title for the dropdown row (§4.5)
            ->order('username')            // stable, human dropdown order
            ->order('Milpac.relation_id')  // tiebreak: a duplicate member keeps its LOWEST milpac (§4.4, #96)
            ->fetch($limit);
    }
}
