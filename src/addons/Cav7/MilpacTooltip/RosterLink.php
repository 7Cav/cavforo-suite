<?php

namespace Cav7\MilpacTooltip;

/**
 * The in-post milpac hovercard's link helper (issue #83). A milpac is one
 * NF\Rosters:RosterUser row whose profile URL is /rosters/profile/<relation_id>/;
 * the row carries the member's user_id. This helper does the three things the
 * XF\BbCode\Renderer\Html::getRenderedLink extension needs at render:
 *
 *   relationIdFromUrl()   recognise a roster-profile link, capture its relation_id
 *   isSameOriginLink()    gate: does that link point at THIS board (issue #126)?
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
 * relationIdFromUrl(), isSameOriginLink() and stampAnchor() are pure (no XenForo), so the
 * recognition, the same-origin gate and the stamping are unit-tested in plain PHP
 * (tests/LinkStampTest.php). The board's canonical URL that the gate needs is read on the
 * render path (Html::getRenderedLink, the standard `boardUrl` option) and passed in, so
 * the gate itself stays \XF-free. resolveUserId() is the only \XF reference — a live
 * NF\Rosters:RosterUser finder — so it is integration-verified
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
     *     are). Scheme and port are not part of the decision — a canonical link and a
     *     hand-typed http/https variant on the same host are both local.
     *
     * An absolute link whose host cannot be confirmed against the board host — a
     * foreign host, or a missing/misconfigured (hostless) $boardUrl — is NOT same
     * origin, so it is left unstamped rather than stamped on a guess.
     *
     * Pure and total: it reads no \XF state and never throws (the render path passes
     * the board URL in), so it is unit-tested next to relationIdFromUrl and stampAnchor
     * with no XenForo loaded, and a malformed href cannot break a post render.
     */
    public static function isSameOriginLink(string $url, string $boardUrl): bool
    {
        $linkHost = self::hostOf($url);

        // A relative link carries no host, so it can only point at this board.
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
     * URL, and never throws, so this stays total for isSameOriginLink.
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
}
