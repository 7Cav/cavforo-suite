<?php

namespace Cav7\EnlistmentReminder\XF\Alert;

/**
 * Registers the enlistment-reminder alert as opt-out-able on the thread content
 * type. The reminder (issue #76, per ADR-0001) sends a custom `enlistment_reminder`
 * action on the core `thread` type; XenForo builds each member's alert-preference
 * toggle from the content type handler's getOptOutActions plus the
 * alert_opt_out.{type}_{action} phrase, so a clerk can only mute the reminder if
 * the thread handler lists the action here. Everything else — viewability, the
 * one-click through, rendering the alert_thread_enlistment_reminder template — is
 * the stock thread handler's job, so this is the only override needed.
 */
class ThreadHandler extends XFCP_ThreadHandler
{
    public function getOptOutActions()
    {
        return array_merge(parent::getOptOutActions(), ['enlistment_reminder']);
    }
}
