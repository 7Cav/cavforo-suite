<?php

namespace Cav7\TicketSchedule;

use Cav7\TicketSchedule\Entity\Schedule;

/**
 * The XenForo-coupled half of the addon: opens one NF/Tickets ticket for each
 * ticket schedule that is due, as the opener user, then moves the schedule's
 * due date past today.
 *
 * Ordering inside a run is deliberate. The due date advances and the ticket
 * is recorded right after the ticket has saved, and the vendor's
 * notifications go out after that. A crash between the save and the advance
 * can open a duplicate on the next run; the reverse order would lose a ticket
 * on every failed open, which contradicts the retry rule below. The duplicate
 * is the cheaper failure. Notifications come last and fail on their own: a
 * notifier that throws (a mailer, a third-party notifier extension) leaves the
 * ticket standing, the due date moved on, and one error naming both. Sending
 * them before the advance would open a ticket every hour for as long as the
 * notifier kept failing.
 *
 * A schedule that cannot open its ticket leaves one error in the XenForo error
 * log naming it, keeps its due date, and is retried on the next hourly run. It
 * stays active and nobody else is told, which is the same failure handling
 * Cav7/EnlistmentReminder and Cav7/EnlistmentDefaults already use.
 *
 * What this assumes about NF/Tickets 2.11.1, read from its source. None of it
 * is checked by a test here; the dev-stack pass before a release is where a
 * vendor move shows.
 *
 * - `Entity\Category::canCreateTicket()` checks the category's `allow_opening`
 *   flag and the visitor's `create` permission there. The Creator refuses
 *   neither on its own, so it is checked here first, as the opener.
 * - `Service\Ticket\Creator` takes the category entity and, in its
 *   constructor, stamps the visitor on both the ticket and the first message
 *   and applies the vendor's default priority and status. Running under
 *   `\XF::asVisitor($opener)` is what makes the opener the ticket's user.
 * - `logIp(false)` is the vendor's own automated path (its email-to-ticket
 *   processor does the same). The Creator's `setIsAutomated()` is not used:
 *   it also turns validations off, and with them the step that stamps the
 *   ticket's first and last message dates, so a ticket opened that way sorts
 *   as if it had no messages.
 * - `setContent()` with validations on trims nothing: a title over 150
 *   characters is an error. The schedule's own title column is 150 wide, so
 *   it cannot get that far.
 * - The Creator does not apply the category's `default_prefix_id`; the public
 *   form does, as a preselected input. It is set here so a category that
 *   requires a prefix accepts the ticket. The public form's `isPrefixUsable()`
 *   check is not repeated: the default is the category's own choice, not the
 *   opener's, and `Ticket::_preSave` still drops a prefix that is not the
 *   category's.
 */
class TicketOpener
{
    /**
     * Open a ticket for every active schedule due on or before today.
     */
    public function openDue(): void
    {
        /** @var \Cav7\TicketSchedule\Repository\Schedule $repo */
        $repo = \XF::repository('Cav7\TicketSchedule:Schedule');
        $today = $repo->today();

        $opener = $repo->findOpener();
        if (!$opener) {
            \XF::logError(sprintf(
                '[Cav7/TicketSchedule] Opener user id %d names no user; no scheduled ticket can open until the option is corrected.',
                $repo->openerUserId()
            ));
            return;
        }

        foreach ($repo->findDueSchedules($today)->fetch() as $schedule) {
            try {
                \XF::asVisitor($opener, function () use ($schedule, $today) {
                    $this->open($schedule, $today);
                });
            } catch (\Throwable $e) {
                \XF::logException($e, false, sprintf(
                    '[Cav7/TicketSchedule] Schedule %d "%s" could not open its ticket; its due date %s is unchanged and the next run retries: ',
                    $schedule->schedule_id,
                    $schedule->title,
                    $schedule->due_date
                ));
            }
        }
    }

    /**
     * Open one schedule's ticket as the current visitor, then record it and
     * move the due date past today. Throws on anything that stops the ticket
     * being opened; the caller logs it.
     */
    protected function open(Schedule $schedule, string $today): void
    {
        $category = $schedule->Category;
        if (!$category) {
            throw new \RuntimeException('ticket category ' . $schedule->ticket_category_id . ' no longer exists');
        }

        $error = null;
        if (!$category->canCreateTicket($error)) {
            throw new \RuntimeException(sprintf(
                'the opener may not open a ticket in category %d "%s": %s',
                $category->ticket_category_id,
                $category->title,
                $error ? (string) $error : 'no create permission there'
            ));
        }

        /** @var \NF\Tickets\Service\Ticket\Creator $creator */
        $creator = \XF::service('NF\Tickets:Ticket\Creator', $category);
        $creator->logIp(false);
        if ($category->default_prefix_id) {
            $creator->setPrefix($category->default_prefix_id);
        }
        $creator->setContent($schedule->title, $schedule->message);

        if (!$creator->validate($errors)) {
            throw new \RuntimeException(
                'the ticket creator refused it: ' . implode('; ', array_map('strval', $errors))
            );
        }

        $ticket = $creator->save();

        $schedule->due_date = $schedule->getCadence()->advancePast($schedule->due_date, $today);
        $schedule->last_ticket_id = $ticket->ticket_id;
        $schedule->last_ticket_date = \XF::$time;
        $schedule->save();

        try {
            $this->sendNotifications($creator);
        } catch (\Throwable $e) {
            \XF::logException($e, false, sprintf(
                '[Cav7/TicketSchedule] Schedule %d "%s" opened ticket %d but its notifications failed; the ticket stands and the due date has moved to %s: ',
                $schedule->schedule_id,
                $schedule->title,
                $ticket->ticket_id,
                $schedule->due_date
            ));
        }
    }

    /**
     * The vendor's own notifications for a new ticket: the category's
     * watchers, its notify addresses, and whatever notifier extensions other
     * addons hang on it. A method of its own so a failure here is caught as
     * "notifications failed", never as "the ticket could not open".
     */
    protected function sendNotifications(\NF\Tickets\Service\Ticket\Creator $creator): void
    {
        $creator->sendNotifications();
    }
}
