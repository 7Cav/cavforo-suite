<?php

namespace Cav7\TicketWebhook;

use NF\Tickets\Entity\Ticket;

/**
 * The status change a call makes after its ticket or reply has saved: close
 * the ticket on an all-clear, or reopen it on a firing call. HookCall decides
 * which; TicketOpener and TicketReplier apply it between recording the call
 * and sending the notifications. See GLOSSARY.md for call and all-clear.
 *
 * The hook moves a status only to close an open ticket or to reopen a closed
 * one. A ticket whose status already closes it is not closed again, so a
 * ticket staff closed with a status of their choosing keeps it when its
 * all-clear arrives, as the vendor's own Resolve action refuses a ticket that
 * is already closed. A ticket that is not closed is not reopened.
 *
 * Runs under `\XF::asVisitor($opener)`, which the public controller sets up,
 * so the opener is the activity log's actor. What this assumes about
 * NF/Tickets 2.12.2, read from its source. None of it is checked by a test
 * here; the dev-stack pass before a release is where a vendor move shows.
 *
 * - The `nftResolvedStatus` and `nftOpenStatus` options name the statuses,
 *   and a status's `is_close` says whether it closes a ticket. Both are read
 *   as the vendor reads them. An option that names no active status is a
 *   failure here, as the vendor's Resolve action refuses one, rather than a
 *   ticket left with a status that does not exist.
 * - `Service\Ticket\Editor` takes `\XF::visitor()` in its constructor, so it
 *   is built here, inside the opener's visitor scope.
 * - With the Editor's validations on, its spam check can moderate the
 *   ticket, and its prefix rule refuses a ticket with no prefix in a category
 *   that requires one. Either would refuse or hide an alert's ticket for
 *   reasons that have nothing to do with its status, so they are turned off.
 *   The ticket entity's own checks still run.
 * - `setStatus()` compares the new status with `!==`, so the id is an int.
 *   Its second argument is the status alert to the ticket's user, which is
 *   always off: the opener is that user, and closing an alert's ticket is
 *   news to nobody it would reach.
 * - The ticket entity writes the activity log's `status_changed` row on save.
 */
final class TicketStatusChange
{
    private bool $close;

    private function __construct(bool $close)
    {
        $this->close = $close;
    }

    /**
     * Close the ticket with the board's resolved status.
     */
    public static function close(): self
    {
        return new self(true);
    }

    /**
     * Reopen the ticket with the board's open status.
     */
    public static function reopen(): self
    {
        return new self(false);
    }

    /**
     * What this change does to a ticket, for the error log.
     */
    public function verb(): string
    {
        return $this->close ? 'close' : 'reopen';
    }

    /**
     * Apply the change to the ticket as the current visitor.
     *
     * @throws \RuntimeException when the option names no active status, or
     *                           the Editor refuses the change
     */
    public function apply(Ticket $ticket): void
    {
        $closed = $ticket->Status ? (bool) $ticket->Status->is_close : false;
        if ($closed === $this->close) {
            return;
        }

        $option = $this->close ? 'nftResolvedStatus' : 'nftOpenStatus';
        $statusId = (int) (\XF::options()->{$option} ?? 0);

        /** @var \NF\Tickets\Repository\Status $statusRepo */
        $statusRepo = \XF::repository('NF\Tickets:Status');
        if (!$statusRepo->getActiveStatus($statusId)) {
            throw new \RuntimeException(sprintf('the %s option names no active status (id %d)', $option, $statusId));
        }

        /** @var \NF\Tickets\Service\Ticket\Editor $editor */
        $editor = \XF::service('NF\Tickets:Ticket\Editor', $ticket);
        $editor->setPerformValidations(false);
        $editor->setStatus($statusId, false);

        if (!$editor->validate($errors)) {
            throw new \RuntimeException(
                'the ticket editor refused it: ' . implode('; ', array_map('strval', $errors))
            );
        }

        $editor->save();
    }
}
