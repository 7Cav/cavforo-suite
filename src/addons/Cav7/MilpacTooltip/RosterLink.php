<?php

namespace Cav7\MilpacTooltip;

/**
 * The in-post milpac hovercard's link helper (issue #83). A milpac is one
 * NF\Rosters:RosterUser row whose profile URL is /rosters/profile/<relation_id>/;
 * the row carries the member's user_id. This helper does the three things the
 * XF\BbCode\Renderer\Html::getRenderedLink extension needs at render:
 *
 *   relationIdFromUrl()   recognise a roster-profile link, capture its relation_id
 *   resolveUserId()       relation_id -> user_id via the roster finder
 *   stampAnchor()         mark the anchor so XF.MemberTooltip drives its hovercard
 *
 * The card itself is not built here: stamping the anchor with data-xf-init="member-tooltip"
 * and the resolved data-user-id hands the hover to XenForo's own XF.MemberTooltip handler,
 * which fetches the anchor's href (the roster URL) with tooltip=1 and caches by user_id —
 * no new JavaScript. The NF\Rosters\Pub\Controller\Roster::actionProfile extension answers
 * that tooltip=1 request by resolving to the member and handing off to
 * MemberController::actionTooltip, which returns the standard member_tooltip (ADR 0001).
 *
 * The recognition regex #/rosters/profile/(\d+)# is DUPLICATED from
 * Cav7\MilpacMention\MilpacResolver::extractRelationIds rather than shared: ADR 0002 keeps
 * MilpacTooltip and MilpacMention as independent bounded contexts, and the Cav7/Core stub is
 * not introduced as a home for one line of regex.
 *
 * relationIdFromUrl() and stampAnchor() are pure (no XenForo), so the recognition and the
 * stamping are unit-tested in plain PHP (tests/LinkStampTest.php). resolveUserId() is the
 * only \XF reference — a live NF\Rosters:RosterUser finder — so it is integration-verified
 * against the dev stack, not unit-pinned, exactly as MilpacResolver::resolveUserIds is left
 * out of the pure suite. Requiring this file and calling only the pure methods is safe with
 * no XenForo, as long as resolveUserId() is not called.
 */
class RosterLink
{
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
        if (preg_match('#/rosters/profile/(\d+)#', $url, $matches)) {
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
}
