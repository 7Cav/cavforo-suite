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
 * preview). The parent produces the stock anchor; this only recognises a roster-profile
 * URL, resolves its relation_id to the member's user_id, and stamps the anchor so
 * XenForo's own XF.MemberTooltip drives the card. The href is untouched, so a click still
 * opens the roster profile, and the link looks the same at rest (ADR 0001).
 */
class Html extends XFCP_Html
{
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
     * containment the sibling MilpacMention uses on its shared save path.
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

        try
        {
            $relationId = RosterLink::relationIdFromUrl((string) $url);
            // Recognise the roster path, but stamp only when the link points at THIS
            // board: a relative link, or an absolute one whose host matches the board's
            // canonical URL. A cross-board /rosters/profile/<n>/ link is left as the
            // parent rendered it — otherwise its local relation_id would be resolved to
            // a local member and XF.MemberTooltip would show the wrong card on hover,
            // even though the foreign href still clicks through correctly (issue #126).
            if ($relationId > 0 && RosterLink::isSameOriginLink((string) $url, (string) \XF::options()->boardUrl))
            {
                $userId = RosterLink::resolveUserId($relationId);
                if ($userId > 0)
                {
                    $rendered = RosterLink::stampAnchor($rendered, $userId);
                }
            }
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, '[Cav7/MilpacTooltip] hovercard stamping failed: ');
        }

        return $rendered;
    }
}
