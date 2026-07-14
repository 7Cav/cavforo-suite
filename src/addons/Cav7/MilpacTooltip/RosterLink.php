<?php

namespace Cav7\MilpacTooltip;

/**
 * The in-post milpac hovercard's link helper (issue #83). A milpac is one
 * NF\Rosters:RosterUser row whose profile URL is /rosters/profile/<relation_id>/;
 * the row carries the member's user_id. This helper does what the
 * XF\BbCode\Renderer\Html renderer extension needs to hovercard a milpac link:
 *
 *   relationIdFromUrl()   recognise a roster-profile link, capture its relation_id
 *   relationIdsFromText() collect a whole message's relation_ids for one batch (#129)
 *   isSameBoardLink()     gate: does that link point at THIS board (issue #126)?
 *   resolveUserId()       relation_id -> user_id via the roster finder (one link)
 *   resolveUserIds()      relation_ids -> [relation_id => user_id] in ONE query (#129)
 *   primeUserIdMap()      widen that result into the per-link memo, 0 for no-row (#129)
 *   stampAnchor()         mark the anchor so XF.MemberTooltip drives its hovercard
 *
 * The render-time lookup is batched (issue #129): rather than a finder per link,
 * the renderer collects a message's relation_ids once at its message-level boundary
 * (setupRender) and resolves them together, so a post costs one query however many
 * roster links it carries. relationIdsFromText + primeUserIdMap are the pure halves
 * of that; resolveUserIds is the batched finder. See the renderer extension for the seam.
 *
 * The card itself is not built here: stamping the anchor with data-xf-init="member-tooltip"
 * and the resolved data-user-id hands the hover to XenForo's own XF.MemberTooltip handler,
 * which fetches the anchor's href (the roster URL) with tooltip=1 and caches by user_id —
 * no new JavaScript. The NF\Rosters\Pub\Controller\Roster::actionProfile extension answers
 * that tooltip=1 request by resolving to the member and handing off to
 * MemberController::actionTooltip, which returns the standard member_tooltip (ADR 0001).
 *
 * The recognition regex #/rosters/profile/(\d+)# is DUPLICATED from
 * Cav7\MilpacMention\MilpacResolver::extractRelationIds rather than shared, and the batched
 * resolveUserIds() mirrors the SHAPE of MilpacResolver::resolveUserIds (a WHERE relation_id
 * IN (...) finder plucked to [relation_id => user_id]) as a reference only: ADR 0002 keeps
 * MilpacTooltip and MilpacMention as independent bounded contexts, and the Cav7/Core stub is
 * not introduced as a home for a line of regex or a copied query shape.
 *
 * relationIdFromUrl(), relationIdsFromText(), isSameBoardLink(), primeUserIdMap() and
 * stampAnchor() are pure (no XenForo), so the recognition, the batch pre-scan and priming,
 * the same-origin gate and the stamping are unit-tested in plain PHP (tests/LinkStampTest.php).
 * The board's canonical URL that the gate needs is read on the render path
 * (Html::getRenderedLink, the standard `boardUrl` option) and passed in, so the gate itself
 * stays \XF-free. resolveUserId() and resolveUserIds() are the only \XF references — live
 * NF\Rosters:RosterUser finders — so they are integration-verified against the dev stack, not
 * unit-pinned, exactly as MilpacResolver::resolveUserIds is left out of the pure suite.
 * Requiring this file and calling only the pure methods is safe with no XenForo, as long as
 * the two finder methods are not called.
 */
class RosterLink
{
    /**
     * The roster-profile recognition pattern, shared by the two live matchers in this
     * class — relationIdFromUrl (one link URL) and relationIdsFromText (a whole message) —
     * so a future roster-route change edits one place and the two stay byte-identical. One
     * path segment, host-agnostic and slug-tolerant; see the callers for what that catches.
     * Intra-class DRY only: ADR 0002's deliberate cross-addon duplication with
     * Cav7\MilpacMention is unchanged, so this is NOT shared with that addon.
     */
    private const ROSTER_PROFILE_REGEX = '#/rosters/profile/(\d+)#';

    /**
     * The relation_id a rendered link points at, or 0 when the URL is not a
     * roster-profile link. #/rosters/profile/(\d+)# matches one path segment, so it
     * catches a relative link, a canonical absolute URL with an optional -slug after
     * the int, a named [URL=...] href, and a bare auto-linked URL uniformly, wherever
     * BBCode renders. It is host-agnostic and slug-tolerant, mirroring the shape the
     * $name completer inserts and the shape a hand-typed link takes. relation_id 0 does
     * not exist, so a /rosters/profile/0/ link returns 0 (not a milpac). A non-profile
     * roster URL (a roster listing, positions, ...) does not match the /profile/ segment,
     * so it returns 0 and stays a plain link.
     */
    public static function relationIdFromUrl(string $url): int
    {
        if (preg_match(self::ROSTER_PROFILE_REGEX, $url, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Whether a rendered link URL points at a milpac profile (a positive relation_id).
     */
    public static function isRosterProfileLink(string $url): bool
    {
        return self::relationIdFromUrl($url) > 0;
    }

    /**
     * Every `/rosters/profile/<id>/` occurrence in the reconstructed message text,
     * de-duplicated and in first-seen order — the set the render path resolves in ONE
     * batched query before any per-link stamping (issue #129), instead of a finder per
     * link. This is a deliberately COARSE superset of the message's real roster links, not
     * the exact set of them: the same #/rosters/profile/(\d+)# recognition relationIdFromUrl
     * uses is run over the whole reconstructed text, so it catches every link shape (named
     * [URL=...], bare auto-linked, $name-inserted, hand-typed, in quotes) uniformly — but
     * also any /rosters/profile/<id>/ substring that never renders as a link at all (inside
     * a [CODE]/[PLAIN] block, or in plain prose). That over-collection is deliberate and
     * harmless: an extra id only widens the batch's WHERE relation_id IN (...) by one row
     * that getRenderedLink never reads, because stamping stays per-link gated (it re-runs
     * relationIdFromUrl on the real anchor URL, then isSameBoardLink) — so a spurious id
     * can never become a wrong stamp, only a slightly wider IN clause. relation_id 0 does
     * not exist, so /rosters/profile/0/ contributes nothing. Repeated links to the same
     * milpac collapse to a single relation_id, so a post that links one member many times
     * still costs one row in the batch.
     *
     * Pure and host-agnostic, mirroring the SHAPE of the sibling
     * Cav7\MilpacMention\MilpacResolver::extractRelationIds (ADR 0002 keeps the two
     * addons independent: the shape is copied, the code is not shared). The same-origin
     * decision is NOT made here — isSameBoardLink gates each link at stamp time (issue
     * #126), so a foreign link collected into the batch is simply never stamped. A
     * PCRE-level failure (preg_match_all returns false — a backtrack/recursion limit hit,
     * NOT bad UTF-8: the pattern carries no /u modifier, so byte input can never make it
     * fail here) yields the empty set: no batch, and the per-link path still resolves and
     * fails open on its own. Unlike that sibling extractRelationIds, which \XF::logError's
     * its PCRE-false branch, this one drops SILENTLY by design and does NOT log: the drop is
     * signal-only (no user impact — the per-link path still runs and fails open), the branch
     * is effectively unreachable without /u, and this is one of the class's pure,
     * unit-tested methods that must stay callable with no XenForo — keeping it \XF-free is
     * worth more than the log line the sibling can afford.
     *
     * @return list<int>
     */
    public static function relationIdsFromText(string $text): array
    {
        if (preg_match_all(self::ROSTER_PROFILE_REGEX, $text, $matches) === false) {
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
     * Widen the batched finder's result into the full request-scoped memo the per-link
     * path reads (issue #129). resolveUserIds returns [relation_id => user_id] only for
     * relation_ids that have a roster row; this fills every pre-scanned relation_id,
     * defaulting a missing one (a deleted roster row / an invalid link) to user_id 0.
     * The map is keyed by exactly what was scanned, so getRenderedLink can tell "looked
     * up in the batch, no member -> 0, leave unstamped" from "never primed -> fall back
     * to the un-primed finder" with a single array_key_exists — which is what keeps a
     * post with a deleted milpac link at one query, not one plus a per-link fallback.
     *
     * Pure: it shapes two arrays and touches no \XF state, so it is unit-tested next to
     * the recognition helpers.
     *
     * @param list<int>       $relationIds the pre-scanned set (relationIdsFromText)
     * @param array<int, int> $resolved    [relation_id => user_id] from resolveUserIds
     *
     * @return array<int, int> [relation_id => user_id], one entry per scanned id
     */
    public static function primeUserIdMap(array $relationIds, array $resolved): array
    {
        $map = [];
        foreach ($relationIds as $relationId) {
            $relationId = (int) $relationId;
            $map[$relationId] = isset($resolved[$relationId]) ? (int) $resolved[$relationId] : 0;
        }

        return $map;
    }

    /**
     * Whether a recognised roster-profile link points at THIS board, and so may be
     * stamped with a local member's hovercard (issue #126). relationIdFromUrl stays
     * host-agnostic — it recognises the /rosters/profile/<n>/ shape wherever BBCode
     * renders — so the same path on a foreign board matches too. Stamping such a
     * foreign link with the LOCAL relation_id's member is the bug: XF.MemberTooltip
     * caches by data-user-id, so hovering the foreign link would show the wrong
     * (local) card while the href still (correctly) clicks through off-board. This
     * gate is the separate same-origin decision that keeps that from happening.
     *
     * A link is on this board when:
     *   - it is relative (carries no host) — it can only resolve against this board; or
     *   - its host matches the host of the board's canonical URL ($boardUrl, the
     *     standard XenForo `boardUrl` option), compared case-insensitively (hostnames
     *     are). For a URL that parses, scheme and port are not part of the decision —
     *     a canonical link and a hand-typed http/https variant on the same host are
     *     both local.
     *
     * An absolute link whose host cannot be confirmed against the board host — a
     * foreign host, a malformed URL parse_url() cannot parse at all, or a
     * missing/misconfigured (hostless) $boardUrl — is NOT same origin, so it is left
     * unstamped rather than stamped on a guess. A malformed URL fails CLOSED and must
     * not be mistaken for a relative link: a foreign absolute link that parse_url()
     * chokes on (e.g. an out-of-range or non-numeric port, an empty authority) still
     * clicks through off-board, so stamping the local relation_id on it would show the
     * WRONG (local) member's card (issue #126). A URL that parses and carries no host
     * is treated as local — the genuinely relative link, and also a scheme-only/opaque
     * URI (e.g. foo:/rosters/...): with no host, neither can name a foreign board.
     *
     * Pure and total: it reads no \XF state and never throws (the render path passes
     * the board URL in), so it is unit-tested next to relationIdFromUrl and stampAnchor
     * with no XenForo loaded, and a malformed href cannot break a post render.
     */
    public static function isSameBoardLink(string $url, string $boardUrl): bool
    {
        $linkParts = parse_url($url);

        // A malformed URL cannot be parsed at all (parse_url returns false). We cannot
        // confirm it points at this board, so fail CLOSED — leave it unstamped rather
        // than treat it as relative and stamp the local relation_id on a guess. A
        // genuinely relative link parses fine (with no host) and is handled below.
        if ($linkParts === false) {
            return false;
        }

        // A relative link carries no host, so it can only point at this board.
        $linkHost = $linkParts['host'] ?? '';
        if ($linkHost === '') {
            return true;
        }

        $boardHost = self::hostOf($boardUrl);

        // An absolute link is local only when we can confirm its host matches the
        // board's. An empty board host (misconfigured option) confirms nothing.
        return $boardHost !== '' && strcasecmp($linkHost, $boardHost) === 0;
    }

    /**
     * The host of a URL, or '' when it carries none (a relative link) or cannot be
     * parsed. parse_url() returns null for a missing host and false on a malformed
     * URL, and never throws, so this stays total for isSameBoardLink.
     *
     * CAUTION — board URL only. Do NOT use this to derive the LINK url's host: it
     * collapses a malformed URL (parse_url false) and a genuinely relative one (no
     * host) both to '', so the link side inspects parse_url($url) === false itself to
     * fail CLOSED. Routing the link through here would reintroduce the fail-open bug.
     */
    private static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    /**
     * Stamp a rendered roster anchor so its hover shows the member hovercard. The two
     * data attributes are injected into the opening <a> tag and nothing else changes:
     *
     *   data-xf-init="member-tooltip"  activates XenForo's XF.MemberTooltip handler
     *   data-user-id="<user_id>"       the resolved member — the tooltip's cache key,
     *                                  shared with that member's username hovercard
     *
     * The href is left untouched (a click still opens the roster profile), no username
     * class is added, and no styling is applied, so the link looks the same at rest and
     * only the hover is enhanced (spec user stories 4 and 5). $userId <= 0 means the
     * milpac did not resolve to a member, so the anchor is returned unstamped and stays a
     * plain link with no hovercard (spec user story 17: a deleted/invalid milpac shows no
     * card). A string carrying no anchor is returned unchanged.
     *
     * @param string $anchorHtml the rendered <a ...>text</a> from getRenderedLink
     * @param int    $userId     the resolved member user_id
     */
    public static function stampAnchor(string $anchorHtml, int $userId): string
    {
        if ($userId <= 0) {
            return $anchorHtml;
        }

        $stamped = 'data-xf-init="member-tooltip" data-user-id="' . $userId . '" ';

        // Inject the attributes into the first opening <a> tag only; getRenderedLink
        // emits exactly one anchor, so the single replacement is precise.
        //
        // preg_replace returns null on a PCRE-level failure (backtrack/recursion limit)
        // instead of throwing, so coalesce back to the input: a stamping hiccup fails
        // open to the unstamped stock anchor rather than dropping the link by returning
        // null. The \Throwable guard in the Html renderer does not see a preg failure.
        $out = preg_replace('/<a\s+/', '<a ' . $stamped, $anchorHtml, 1);

        return $out ?? $anchorHtml;
    }

    /**
     * relation_id -> user_id via the NF\Rosters:RosterUser finder, run in the forward
     * direction (MilpacMention runs the same relation in reverse). relation_id is the
     * primary key, so a single row maps to exactly one member; 0 when the row is missing
     * (a deleted roster row / an invalid link), which stampAnchor then leaves unstamped.
     *
     * The only \XF reference in this class. It runs on the render path, so callers wrap it
     * in a \Throwable guard: a vendor schema drift or a transient DB error must not break
     * the post render. Integration-verified against the dev stack, not unit-pinned.
     */
    public static function resolveUserId(int $relationId): int
    {
        if ($relationId <= 0) {
            return 0;
        }

        $rosterUser = \XF::finder('NF\Rosters:RosterUser')
            ->where('relation_id', $relationId)
            ->fetchOne();

        return $rosterUser ? (int) $rosterUser->user_id : 0;
    }

    /**
     * relation_id -> user_id for a whole SET of relation_ids in one query (issue #129),
     * so a message's roster-profile links resolve in a single WHERE relation_id IN (...)
     * lookup instead of one finder per link. relation_id is the primary key, so each maps
     * to exactly one member; a relation_id with no row is simply absent from the result
     * (primeUserIdMap then fills it to 0). Callers pass the de-duplicated set from
     * relationIdsFromText, so a milpac linked many times is looked up once.
     *
     * Mirrors the query SHAPE of Cav7\MilpacMention\MilpacResolver::resolveUserIds — a
     * single ->where('relation_id', $ids) finder plucked to [relation_id => user_id] — as
     * a reference only; ADR 0002 keeps the two addons independent, so the shape is copied,
     * not shared. Like resolveUserId this is the class's only other \XF reference, runs on
     * the render path under the caller's \Throwable guard, and is integration-verified
     * against the dev stack rather than unit-pinned.
     *
     * @param list<int> $relationIds
     *
     * @return array<int, int> [relation_id => user_id] for the rows that exist
     */
    public static function resolveUserIds(array $relationIds): array
    {
        if (!$relationIds) {
            return [];
        }

        return \XF::finder('NF\Rosters:RosterUser')
            ->where('relation_id', $relationIds)
            ->fetch()
            ->pluckNamed('user_id', 'relation_id'); // [relation_id => user_id]
    }
}
