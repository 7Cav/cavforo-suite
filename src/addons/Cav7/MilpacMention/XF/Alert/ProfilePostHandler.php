<?php

namespace Cav7\MilpacMention\XF\Alert;

/**
 * Registers milpac_mention as opt-out-able on the profile_post content type
 * (spec §3.1). XenForo builds each member's alert-preference toggle from the
 * content handler's getOptOutActions() plus the alert_opt_out.{type}_{action}
 * phrase, so a member can only mute the milpac alert if this handler lists the
 * action here.
 *
 * This ONE row governs BOTH profile posts and their comments, mirroring XF's own
 * mention family: core XF registers the mention opt-out only on
 * XF\Alert\ProfilePostHandler (labelled "Mentions you in a profile post or
 * comment") and NOT on XF\Alert\ProfilePostCommentHandler. milpac_mention follows
 * that exactly — there is deliberately no ProfilePostCommentHandler extension, so
 * the single alert_opt_out.profile_post_milpac_mention row covers both surfaces.
 *
 * The action is array_merge-d onto the parent list, never returned alone:
 * replacing it would silently drop the core profile_post opt-outs (insert,
 * mention, reaction). Everything else — viewability, click-through, rendering
 * alert_profile_post_milpac_mention — is the stock handler's job, so this override
 * is all that is needed.
 */
class ProfilePostHandler extends XFCP_ProfilePostHandler
{
    public function getOptOutActions()
    {
        return array_merge(parent::getOptOutActions(), ['milpac_mention']);
    }
}
