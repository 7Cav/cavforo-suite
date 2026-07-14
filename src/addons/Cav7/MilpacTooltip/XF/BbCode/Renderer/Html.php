<?php

namespace Cav7\MilpacTooltip\XF\BbCode\Renderer;

use Cav7\MilpacTooltip\RosterLink;

/**
 * Class extension of the HTML BBCode renderer that gives an in-post roster-profile
 * link the member hovercard on hover (issue #83).
 *
 * getRenderedLink is the single choke point every [URL] anchor passes through, so
 * stamping here covers named links, bare auto-linked URLs, $name-inserted links, and
 * hand-typed links uniformly, wherever BBCode renders (including quotes and live
 * preview). The parent produces the stock anchor; this recognises a roster-profile URL
 * and, only when that link points at THIS board (the same-origin gate, issue #126),
 * resolves its relation_id to the member's user_id and stamps the anchor so XenForo's
 * own XF.MemberTooltip drives the card. A cross-board /rosters/profile/<n>/ link is
 * recognised but left unstamped, so its local relation_id is never resolved to a local
 * member and the hover cannot show the wrong card. The href is untouched, so a click
 * still opens the roster profile, and the link looks the same at rest (ADR 0001).
 *
 * getRenderedLink has no message-level boundary — XenForo calls it once per link — so
 * resolving each link's relation_id there would run one NF\Rosters:RosterUser finder per
 * link. setupRender IS that boundary: renderAst calls it once per message with the whole
 * parsed tree before any link is rendered. So this extension pre-scans the message there,
 * resolves every roster-profile relation_id in ONE batched query, and memoises the
 * [relation_id => user_id] map on the instance; getRenderedLink then reads the map instead
 * of querying per link (issue #129). Repeated links to the same milpac cost nothing extra.
 * The memo is reset each message (mirroring the vendor setupRender's own per-message reset
 * of trimAfter/anchorOccurrences), and getRenderedLink falls back to the un-primed per-link
 * finder when a link is not in the map, so nothing depends on the pre-scan having run.
 */
class Html extends XFCP_Html
{
    /**
     * The request-scoped [relation_id => user_id] memo for the message currently being
     * rendered, filled once by setupRender's batched lookup and read per link by
     * getRenderedLink. Reset at the start of every setupRender so it never carries a
     * previous message's map. A relation_id present as a key (even at 0) was resolved in
     * the one batch; a relation_id absent from it was never primed and is resolved on its
     * own by the un-primed finder.
     *
     * @var array<int, int>
     */
    protected $milpacUserIds = [];

    /**
     * The message-level render boundary (issue #129). renderAst calls this once per
     * message, before any link is rendered, so it is where the whole message's
     * roster-profile relation_ids are collected and resolved in ONE batched query rather
     * than one finder per link in getRenderedLink.
     *
     * The parent call runs first so the vendor's own per-message reset (trimAfter,
     * anchorOccurrences) still happens. The memo is then cleared unconditionally so a
     * previous message's map can never leak in, and the batched build is wrapped in the
     * same fail-open \Throwable containment the per-link path uses: a finder or DB fault
     * while batching is logged non-fatal and leaves the memo empty, so getRenderedLink
     * simply falls back to per-link resolution and the render never breaks.
     *
     * @param array $ast
     * @param array $options
     */
    protected function setupRender(array $ast, array $options)
    {
        parent::setupRender($ast, $options);

        $this->milpacUserIds = [];

        try
        {
            // Reconstruct the message text from the parsed tree and collect every
            // /rosters/profile/<id>/ occurrence in it — a deliberately coarse superset of
            // the message's real roster links, not the exact set (an id inside a
            // [CODE]/[PLAIN] block or plain prose is collected too; harmless, since
            // stamping stays per-link gated — see RosterLink::relationIdsFromText),
            // de-duplicated. renderSubTreePlain stitches each tag's original delimiters (so
            // a named [URL=...] href is covered) and its plain-string runs (so a bare
            // auto-linked URL is covered), matching the shapes getRenderedLink later hands
            // us link by link.
            $relationIds = RosterLink::relationIdsFromText($this->renderSubTreePlain($ast));
            if ($relationIds)
            {
                // One WHERE relation_id IN (...) query for the whole message.
                $resolved = RosterLink::resolveUserIds($relationIds);
                $this->milpacUserIds = RosterLink::primeUserIdMap($relationIds, $resolved);
            }
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, '[Cav7/MilpacTooltip] hovercard batch resolve failed: ');
        }
    }

    /**
     * Render the stock anchor, then — for a roster-profile link that points at this
     * board and resolves to a member — stamp it with data-xf-init="member-tooltip" and
     * the resolved data-user-id. A non-roster link, a cross-board roster link (its host
     * is not this board's, issue #126), or a milpac that does not resolve to a member
     * (deleted roster row, relation_id 0), is returned exactly as the parent rendered
     * it, so it stays a plain link with no hovercard (spec user stories 17 and 18).
     *
     * Contained: getRenderedLink runs on every link of every render, so a finder or DB
     * fault while resolving must not break the whole post render. A \Throwable is logged
     * (non-fatal) and the un-stamped stock anchor is returned, mirroring the fail-open
     * containment the sibling MilpacMention uses on its shared save path. The log folds in
     * the link url and the resolved relation_id so a production fail-open is traceable to
     * the link and milpac that triggered it (issue #131); both are seeded before the try so
     * the log still names them even when the fault lands before they are assigned.
     *
     * @param string $text
     * @param string $url
     * @param array  $options
     *
     * @return string
     */
    protected function getRenderedLink($text, $url, array $options)
    {
        $rendered = parent::getRenderedLink($text, $url, $options);

        // Seed the two identifiers the fail-open log reports BEFORE the try, so the catch can
        // always name the link and milpac even if the throw lands before the try assigns them
        // (issue #131). The risky cast stays inside the try, so an un-stringable $url still
        // fails open; here they hold safe defaults ('' url, 0 = "none resolved").
        $urlString = '';
        $relationId = 0;

        try
        {
            // Cast once (defensive: $url may be untyped or a nullable option) and reuse.
            $urlString = (string) $url;

            $relationId = RosterLink::relationIdFromUrl($urlString);
            // Recognise the roster path, but stamp only when the link points at THIS
            // board: a relative link, or an absolute one whose host matches the board's
            // canonical URL. A cross-board /rosters/profile/<n>/ link is left as the
            // parent rendered it — otherwise its local relation_id would be resolved to
            // a local member and XF.MemberTooltip would show the wrong card on hover,
            // even though the foreign href still clicks through correctly (issue #126).
            if ($relationId > 0 && RosterLink::isSameBoardLink($urlString, (string) \XF::options()->boardUrl))
            {
                $userId = $this->resolveMilpacUserId($relationId);
                if ($userId > 0)
                {
                    $rendered = RosterLink::stampAnchor($rendered, $userId);
                }
            }
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, sprintf(
                '[Cav7/MilpacTooltip] hovercard stamping failed (url: %s, relation_id: %d): ',
                $urlString,
                $relationId
            ));
        }

        return $rendered;
    }

    /**
     * The resolved user_id for a link's relation_id, read from the batched memo when the
     * message was pre-scanned (issue #129), or resolved on its own otherwise. A
     * relation_id keyed in the memo — including at 0 for a milpac with no row — was looked
     * up in the one batched query, so it is returned without touching the database; that
     * is what keeps a post with N distinct links (and any repeats) at one query. A
     * relation_id absent from the memo was never primed — setupRender did not run for this
     * render, or its batch failed open and left the memo empty — so it falls back to the
     * un-primed per-link finder, preserving resolveUserId's standalone fail-open contract.
     * Both the memo read and the fallback finder run inside getRenderedLink's \Throwable
     * guard, so a fault still fails open to a plain un-stamped anchor per link.
     */
    protected function resolveMilpacUserId(int $relationId): int
    {
        if (array_key_exists($relationId, $this->milpacUserIds))
        {
            return $this->milpacUserIds[$relationId];
        }

        return RosterLink::resolveUserId($relationId);
    }
}
