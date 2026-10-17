<?php

namespace Cav7\TicketWebhook;

use Cav7\TicketWebhook\Entity\Hook;
use NF\Tickets\Entity\Ticket;

/**
 * The XenForo-coupled half of a firing call whose alert key links an open
 * ticket: adds one reply to that ticket as the opener user, then records the
 * call on the hook. See GLOSSARY.md for call and alert key.
 *
 * Runs under `\XF::asVisitor($opener)`, which the public controller sets up;
 * that is what makes the opener the reply's author. The ordering matches
 * TicketOpener's: the hook's record is written right after the reply saves,
 * and the vendor's notifications go out after that. Each of those two steps
 * fails on its own, leaving the reply standing and one error naming the hook
 * and the ticket, and the caller still hears success.
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
     * Add the call's reply to the ticket as the current visitor, record the
     * call on the hook, and send the vendor's notifications.
     *
     * @throws \RuntimeException when the Replier refuses the reply
     */
    public function reply(Hook $hook, Ticket $ticket, string $message, string $keyHash): void
    {
        /** @var \NF\Tickets\Service\Ticket\Replier $replier */
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

        try {
            $hook->recordCall($ticket->ticket_id, $keyHash);
        } catch (\Throwable $e) {
            \XF::logException($e, true, sprintf(
                '[Cav7/TicketWebhook] Hook %d "%s" replied to ticket %d but could not record it; the reply stands: ',
                $hook->hook_id,
                $hook->name,
                $ticket->ticket_id
            ));
        }

        try {
            $this->sendNotifications($replier);
        } catch (\Throwable $e) {
            \XF::logException($e, true, sprintf(
                '[Cav7/TicketWebhook] Hook %d "%s" replied to ticket %d but its notifications failed; the reply stands: ',
                $hook->hook_id,
                $hook->name,
                $ticket->ticket_id
            ));
        }
    }

    /**
     * The vendor's own notifications for a reply. A method of its own so a
     * failure here is caught as "notifications failed", never as "could not
     * reply".
     */
    protected function sendNotifications(\NF\Tickets\Service\Ticket\Replier $replier): void
    {
        $replier->sendNotifications();
    }
}
