<?php

namespace Cav7\TicketWebhook;

use Cav7\TicketWebhook\Entity\Hook;
use NF\Tickets\Service\Ticket\Creator;

/**
 * The XenForo-coupled half of a call that opens a ticket: opens one
 * NF/Tickets ticket for an accepted call, as the opener user, and hands back
 * the saved Creator. TicketReplier is the other half, for a call that
 * replies. What follows the save, the record, the status change and the
 * notifications, is the public controller's afterSave().
 *
 * Runs under `\XF::asVisitor($opener)`, which the public controller sets up;
 * that is what makes the opener the ticket's user.
 *
 * What this assumes about NF/Tickets 2.12.2, read from its source and first
 * recorded on Cav7/TicketSchedule's opener. None of it is checked by a test
 * here; the dev-stack pass before a release is where a vendor move shows.
 *
 * - `Entity\Category::canCreateTicket()` checks the category's `allow_opening`
 *   flag and the visitor's `create` permission there. The Creator refuses
 *   neither on its own, so it is checked here first, as the opener.
 * - `Service\Ticket\Creator` takes the category entity and, in its
 *   constructor, stamps the visitor on both the ticket and the first message
 *   and applies the vendor's default priority and status.
 * - `logIp(false)` is the vendor's own automated path (its email-to-ticket
 *   processor does the same). The Creator's `setIsAutomated()` is not used:
 *   it also turns validations off, and with them the step that stamps the
 *   ticket's first and last message dates, so a ticket opened that way sorts
 *   as if it had no messages.
 * - `setContent()` with validations on trims nothing: a title over 150
 *   characters is an error, which is why HookCall cuts the title first.
 * - The Creator does not apply the category's `default_prefix_id`; the public
 *   form does, as a preselected input. It is set here so a category that
 *   requires a prefix accepts the ticket.
 */
class TicketOpener
{
    /**
     * Open the ticket for one accepted call as the current visitor. The
     * vendor's notifications are left to the caller, which sends them through
     * the Creator returned once the call is recorded.
     *
     * @throws NotPermitted      when the opener may not open in the hook's category
     * @throws \RuntimeException when the Creator refuses the ticket
     */
    public function open(Hook $hook, string $title, string $message): Creator
    {
        $category = $hook->Category;
        if (!$category) {
            throw new NotPermitted('ticket category ' . $hook->ticket_category_id . ' no longer exists');
        }

        $error = null;
        if (!$category->canCreateTicket($error)) {
            throw new NotPermitted(sprintf(
                'the opener may not open a ticket in category %d "%s": %s',
                $category->ticket_category_id,
                $category->title,
                $error ? (string) $error : 'no create permission there'
            ));
        }

        /** @var Creator $creator */
        $creator = \XF::service('NF\Tickets:Ticket\Creator', $category);
        $creator->logIp(false);
        if ($category->default_prefix_id) {
            $creator->setPrefix($category->default_prefix_id);
        }
        $creator->setContent($title, $message);

        if (!$creator->validate($errors)) {
            throw new \RuntimeException(
                'the ticket creator refused it: ' . implode('; ', array_map('strval', $errors))
            );
        }

        $creator->save();

        return $creator;
    }
}
