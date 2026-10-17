<?php

namespace Cav7\TicketWebhook;

use NF\Tickets\Entity\Ticket;
use NF\Tickets\Service\Ticket\Replier;

/**
 * The XenForo-coupled half of a call whose alert key links a ticket it
 * replies to: adds one reply to that ticket as the opener user, and hands
 * back the saved Replier. What follows the save, the record, the status
 * change and the notifications, is the public controller's afterSave(), as
 * it is for TicketOpener. See GLOSSARY.md for call, alert key and link.
 *
 * Runs under `\XF::asVisitor($opener)`, which the public controller sets up;
 * that is what makes the opener the reply's author.
 *
 * What this assumes about NF/Tickets 2.12.2, read from its source. None of it
 * is checked by a test here; the dev-stack pass before a release is where a
 * vendor move shows.
 *
 * - `Service\Ticket\Replier` stamps `\XF::visitor()` as the author in its
 *   constructor and has no public setter for a member author, so it is built
 *   here, inside the opener's visitor scope.
 * - The Replier checks no permission: not `canView()`, not `canReply()`, and
 *   not the lock, which `canReply()` is where the vendor refuses. None is
 *   checked here either, as the vendor's own email-to-ticket processor checks
 *   none. That is what lets a ticket staff moved into a category the opener
 *   cannot post in keep its key's replies. The controller has already ruled
 *   out a locked ticket, which HookCall answers with a new ticket instead.
 * - A visible reply moves the ticket's status in `Ticket::messageAdded()`
 *   through the status's on-action setting, unless the ticket's
 *   `skip_status_on_action` option is set. It is set here, on the same object
 *   the new message's `Ticket` relation resolves to (the entity manager's
 *   identity map), so a hook's reply moves no status. `messageAdded()` still
 *   moves the last message date, which is what lifts the ticket to the top of
 *   its list.
 * - `logIp(false)` is the email processor's choice too. `setIsAutomated()`
 *   is not used, because it also turns validations off, and with them the
 *   message date the Replier stamps, the vendor's length limit and its other
 *   message checks. No spam check runs, as none runs on the open path.
 * - `validate()` before `save()` gives the refusal's reasons for the error
 *   log; `save()` alone throws with the first one.
 * - `sendNotifications()` notifies whoever the vendor notifies for any reply:
 *   ticket watchers, and category and queue watchers set to hear messages.
 *   Category watchers set to new tickets only, and the category's and the
 *   priority's notify addresses, hear only of a new ticket.
 * - The board's ticket workflows still run after the reply, as they do after
 *   the open path's ticket, so a workflow can still change the status.
 */
class TicketReplier
{
    /**
     * Add the call's reply to the ticket as the current visitor. The vendor's
     * notifications are left to the caller, which sends them through the
     * Replier returned once the call is recorded.
     *
     * @throws \RuntimeException when the Replier refuses the reply
     */
    public function reply(Ticket $ticket, string $message): Replier
    {
        /** @var Replier $replier */
        $replier = \XF::service('NF\Tickets:Ticket\Replier', $ticket);
        $replier->logIp(false);
        $replier->setMessage($message);

        if (!$replier->validate($errors)) {
            throw new \RuntimeException(
                'the ticket replier refused it: ' . implode('; ', array_map('strval', $errors))
            );
        }

        $ticket->setOption('skip_status_on_action', true);
        $replier->save();

        return $replier;
    }
}
