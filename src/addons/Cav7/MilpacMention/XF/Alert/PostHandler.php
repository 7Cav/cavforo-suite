<?php

namespace Cav7\MilpacMention\XF\Alert;

/**
 * Registers milpac_mention as opt-out-able on the post content type (spec §3.1).
 * XenForo builds each member's alert-preference toggle from the content handler's
 * getOptOutActions() plus the alert_opt_out.{type}_{action} phrase, so a member
 * can only mute the milpac alert if the post handler lists the action here.
 *
 * The action is array_merge-d onto the parent list, never returned alone:
 * replacing it would silently drop every other core post opt-out (mention, quote,
 * reaction, forumwatch_insert, insert). Everything else — viewability,
 * click-through, rendering alert_post_milpac_mention — is the stock post handler's
 * job, so this override is all that is needed.
 */
class PostHandler extends XFCP_PostHandler
{
    public function getOptOutActions()
    {
        return array_merge(parent::getOptOutActions(), ['milpac_mention']);
    }
}
