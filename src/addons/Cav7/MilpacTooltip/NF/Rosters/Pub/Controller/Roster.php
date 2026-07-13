<?php

namespace Cav7\MilpacTooltip\NF\Rosters\Pub\Controller;

use Cav7\MilpacTooltip\RosterLink;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\View;
use XF\Pub\Controller\MemberController;

/**
 * Class extension of the vendor roster controller that serves the member hovercard
 * from the roster URL (issue #83).
 *
 * The in-post roster anchor is stamped so XenForo's XF.MemberTooltip fetches the anchor's
 * own href — the roster URL /rosters/profile/<relation_id>/ — with tooltip=1. That URL is
 * owned by NF/Rosters, so this extension answers the tooltip=1 request: it resolves the
 * relation_id to the member's user_id and hands off to XenForo's own
 * MemberController::actionTooltip, which returns the standard member_tooltip (the card that
 * already carries this add-on's milpac chip). A normal request (no tooltip=1) is passed
 * straight through to the vendor action, so a click still renders the full roster profile
 * (ADR 0001).
 *
 * The handoff is a direct invocation rather than a reroute because actionProfile is typed
 * `: View` and a Reroute reply is not a View; MemberController::actionTooltip returns a
 * View, so the direct call keeps the override return-type compatible. A relation_id that
 * does not resolve to a viewable member makes actionTooltip throw its own not-found /
 * no-permission reply, which the member-tooltip client treats as "no card" — the quiet
 * failure the spec asks for (user stories 17 and 19).
 */
class Roster extends XFCP_Roster
{
    public function actionProfile(ParameterBag $params): View
    {
        if ($this->filter('tooltip', 'bool'))
        {
            return $this->getMilpacMemberTooltip($params->relation_id);
        }

        return parent::actionProfile($params);
    }

    /**
     * Resolve the roster link's relation_id to its member and return that member's stock
     * member_tooltip, by handing off to XenForo's own MemberController::actionTooltip. The
     * resolved user_id is the tooltip's identity and cache key, shared with the member's
     * username hovercard, so the card is identical however it is reached (spec user story
     * 3). An unresolved relation_id resolves to user_id 0, which actionTooltip rejects as
     * not-found — surfaced to the client as no card (spec user story 17).
     */
    protected function getMilpacMemberTooltip(int $relationId): View
    {
        $userId = RosterLink::resolveUserId($relationId);

        /** @var MemberController $memberController */
        $memberController = $this->app->controller(MemberController::class, $this->request);
        $memberController->setResponseType($this->responseType);

        return $memberController->actionTooltip(new ParameterBag(['user_id' => $userId]));
    }
}
