<?php

namespace Cav7\MilpacMention\NF\Tickets\Alert;

/**
 * Registers milpac_mention as opt-out-able on the nf_tickets_message content type
 * (spec §3.1). XenForo builds each member's alert-preference toggle from the content
 * handler's getOptOutActions() plus the alert_opt_out.{type}_{action} phrase, so a
 * member can only mute the ticket milpac alert if this handler lists the action here.
 * Unlike reports (which cannot be muted in XF), the ticket surface IS toggleable, so
 * it carries this opt-out row, mirroring the ticket surface's own `mention` opt-out.
 *
 * The action is array_merge-d onto the parent list, never returned alone: replacing
 * it would silently drop the core ticket opt-outs (insert, quote, mention, reaction).
 * Everything else — viewability, click-through, rendering
 * alert_nf_tickets_message_milpac_mention — is the stock NF\Tickets\Alert\Message
 * handler's job, so this override is all that is needed.
 *
 * SOFT DEPENDENCY (spec §1, §2.1). The from_class NF\Tickets\Alert\Message only exists
 * when NF/Tickets is installed; without it, XF never builds the XFCP proxy and this
 * class never loads, so the addon still installs and the ticket opt-out row simply
 * never appears. There is no per-row addon guard in XF's class_extension schema — the
 * dormant from_class is the mechanism.
 */
class Message extends XFCP_Message
{
    public function getOptOutActions()
    {
        return array_merge(parent::getOptOutActions(), ['milpac_mention']);
    }
}
