<?php

namespace Cav7\TicketWebhook\Pub\Controller;

use Cav7\TicketWebhook\Entity\Hook as HookEntity;
use Cav7\TicketWebhook\HookCall;
use Cav7\TicketWebhook\NotPermitted;
use Cav7\TicketWebhook\Repository\AlertKey as AlertKeys;
use Cav7\TicketWebhook\TicketOpener;
use Cav7\TicketWebhook\TicketReplier;
use Cav7\TicketWebhook\TicketStatusChange;
use NF\Tickets\Entity\Ticket;
use NF\Tickets\Service\Ticket\Creator;
use NF\Tickets\Service\Ticket\Replier;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

/**
 * The one public route, `ticket-webhooks/{hook_id}/{token}`. A caller sends
 * Discord's webhook request here, and the call opens a ticket or, when its
 * alert key links a ticket it can still reach, replies to that one. An
 * all-clear then closes the ticket, and a firing call reopens a closed one.
 * HookCall decides which. See GLOSSARY.md for hook, caller, call, alert key,
 * firing call, all-clear and link, ADR-0001 for why the request is Discord's,
 * and ADR-0002 for alert keys.
 *
 * Nothing of a browser session applies to a caller, so the stock pre-dispatch
 * checks are all skipped: CSRF, two-step, policy acceptance, the canonical
 * URL redirect, viewing permission for guests, the IP ban list, and the
 * board-closed page. The last two are a choice, not an oversight. An IP ban
 * is aimed at a person with a browser, and a hook's stop is its active
 * toggle. A board closed for maintenance still takes an alert, because that
 * is when a monitor is most likely to have something to say. The request
 * runs as a guest until the opener takes over, and every answer is one of
 * the status codes below with an empty body, so a strict caller and a probe
 * both get exactly what the spec on #288 lists.
 *
 *   204  the call opened a ticket or replied to one
 *   200  the same, and the query carried wait=true; the body is
 *        {"id": "<ticket id>"}, the ticket the call reached
 *   400  the body is not a JSON object, or yields no text
 *   403  the opener may not open a ticket in the hook's category
 *   404  no such hook, an inactive hook, or no matching token
 *   405  a method other than POST
 *   500  the Creator refused the ticket, the Replier refused the reply, the
 *        alert key could not be looked up, another call held the key's lock
 *        past Repository\AlertKey::LOCK_WAIT, or the opener names no user
 *
 * Every 403 and 500 leaves one entry in the XenForo error log naming the hook
 * by id and name, and the ticket too when a reply failed. A 500 means the
 * call saved nothing. Nothing else is told.
 *
 * Errors are logged with logException's rollback on. The Creator and the
 * Replier open a transaction before code that can throw, and nothing commits
 * a transaction left open by a throw. It rolls back when the connection
 * closes, and an error row written inside it would go with it.
 *
 * A call that waited for its key's lock is dated from when it took the lock.
 * What that rests on in NF/Tickets 2.12.2, read from its source and checked
 * by no test here: the Replier dates a reply with `\XF::$time`, which
 * XenForo sets when the request starts (`Replier::finalSetup()`). It refuses
 * a reply dated before the ticket's last message
 * (`Replier::setMessagePosition()`). Two calls with one key that arrive a
 * second apart can take the lock in either order. Dated from arrival, the
 * one that arrived first and waited would be older than the message the
 * other just wrote, and its caller would hear 500. `\XF::updateTime()` is
 * XenForo's own refresh, which its job runners call between jobs. The fix
 * also assumes every web server behind the board keeps the same clock.
 */
class Hook extends \XF\Pub\Controller\AbstractController
{
    /**
     * A browser never sends a form here, so there is no CSRF token to check.
     */
    public function checkCsrfIfNeeded($action, ParameterBag $params): void
    {
    }

    protected function preDispatchType($action, ParameterBag $params): void
    {
        $this->setResponseType('raw');
    }

    /**
     * A caller is not a visitor whose activity is worth a session row.
     */
    protected function canUpdateSessionActivity($action, ParameterBag $params, AbstractReply &$reply, &$viewState): bool
    {
        return false;
    }

    public function actionIndex(ParameterBag $params): AbstractReply
    {
        if (!$this->request->isPost()) {
            $this->app->response()->header('Allow', 'POST');

            return $this->status(405);
        }

        /** @var HookEntity|null $hook */
        $hook = $this->em()->find('Cav7\TicketWebhook:Hook', (int) $params->hook_id);

        $pathToken = $params->token !== null ? (string) $params->token : null;
        $authorization = $this->request->getAuthorizationHeader();

        $call = HookCall::decide(
            $hook ? $hook->facts() : null,
            $pathToken,
            $authorization !== '' ? $authorization : null,
            json_decode($this->request->getInputRaw(), true)
        );

        if ($call->outcome() === HookCall::NOT_FOUND) {
            return $this->status(404);
        }
        if ($call->outcome() === HookCall::BAD_REQUEST) {
            return $this->status(400);
        }

        /** @var \Cav7\TicketWebhook\Repository\Hook $repo */
        $repo = $this->repository('Cav7\TicketWebhook:Hook');
        $opener = $repo->findOpener();
        if (!$opener) {
            $this->logFailure($hook, sprintf(
                'could not open its ticket: opener user id %d names no user.',
                $repo->openerUserId()
            ));

            return $this->status(500);
        }

        $keyHash = $call->keyHash();
        if ($keyHash === null) {
            return $this->act($hook, $call, $opener);
        }

        // Two calls with one key that both find no link would both open a
        // ticket, and the one the key does not link would never hear its
        // all-clear. So a call holds its key's lock from before it reads the
        // link until its action is done, and a second call waits, then finds
        // the first call's ticket.
        $alertKeys = $this->alertKeys();
        try {
            $alertKeys->lock($hook, $keyHash);
        } catch (\Throwable $e) {
            $this->logFailure($hook, 'could not take its alert key\'s lock', $e);

            return $this->status(500);
        }

        // Dated from here, not from arrival. The class docblock says why.
        \XF::updateTime();

        try {
            return $this->act($hook, $call, $opener);
        } finally {
            $alertKeys->unlock($hook, $keyHash);
        }
    }

    /**
     * Acts on an accepted call. Looks its alert key's link up, then opens a
     * ticket or replies to the linked one, as HookCall answers. A call with a
     * key gets here holding the key's lock.
     */
    protected function act(HookEntity $hook, HookCall $call, \XF\Entity\User $opener): AbstractReply
    {
        $keyHash = $call->keyHash();
        try {
            $linked = $keyHash !== null ? $this->alertKeys()->linkedTicket($hook, $keyHash) : null;
            $facts = $linked ? $this->ticketFacts($linked) : null;
        } catch (\Throwable $e) {
            $this->logFailure($hook, 'could not look up its alert key', $e);

            return $this->status(500);
        }

        [$replies, $statusChange] = $this->steps($call->action($facts));

        if ($replies) {
            try {
                \XF::asVisitor($opener, function () use ($hook, $call, $linked, $statusChange) {
                    $replier = (new TicketReplier())->reply($linked, $call->replyMessage());
                    $this->afterSave($hook, $call, $linked, $replier, $statusChange);
                });
            } catch (\Throwable $e) {
                $this->logFailure($hook, sprintf('could not reply to ticket %d', $linked->ticket_id), $e);

                return $this->status(500);
            }

            return $this->reached($linked);
        }

        try {
            $ticket = \XF::asVisitor($opener, function () use ($hook, $call, $statusChange) {
                $creator = (new TicketOpener())->open($hook, $call->title(), $call->message());
                $this->afterSave($hook, $call, $creator->getTicket(), $creator, $statusChange);

                return $creator->getTicket();
            });
        } catch (NotPermitted $e) {
            $this->logFailure($hook, 'could not open its ticket: ' . $e->getMessage());

            return $this->status(403);
        } catch (\Throwable $e) {
            $this->logFailure($hook, 'could not open its ticket', $e);

            return $this->status(500);
        }

        return $this->reached($ticket);
    }

    /**
     * The two things an action HookCall answers says: whether the call
     * replies to its key's ticket rather than opening one, and the status
     * change made once the ticket or reply is recorded. An all-clear closes,
     * and a firing call for a closed ticket reopens.
     *
     * @return array{0: bool, 1: TicketStatusChange|null}
     */
    protected function steps(string $action): array
    {
        switch ($action) {
            case HookCall::REPLY:
                return [true, null];
            case HookCall::REPLY_AND_REOPEN:
                return [true, TicketStatusChange::reopen()];
            case HookCall::REPLY_AND_CLOSE:
                return [true, TicketStatusChange::close()];
            case HookCall::OPEN_AND_CLOSE:
                return [false, TicketStatusChange::close()];
            default:
                return [false, null];
        }
    }

    /**
     * What follows the save of a call's ticket or reply, in this order. The
     * hook links the call's alert key to the ticket and moves the hook list's
     * last used date and last ticket. Then the status change, if the call
     * makes one. The vendor's notifications go out last, through the Creator
     * or Replier that saved.
     *
     * A crash between the save and the record leaves the ticket in place and
     * the list one call behind, which the next call corrects. The reverse
     * order would list a ticket that does not exist. Each step fails on its
     * own. A failure leaves the ticket or reply standing and one error naming
     * the hook and the ticket, and the caller still hears success, so a
     * caller that retries does not add a duplicate. Never throws.
     *
     * Runs inside the opener's visitor scope, which the Editor behind the
     * status change reads.
     *
     * @param Creator|Replier $saved
     */
    protected function afterSave(
        HookEntity $hook,
        HookCall $call,
        Ticket $ticket,
        $saved,
        ?TicketStatusChange $statusChange
    ): void {
        [$did, $stands] = $saved instanceof Replier
            ? ['replied to', 'the reply stands']
            : ['opened', 'the ticket stands'];

        try {
            $keyHash = $call->keyHash();
            if ($keyHash !== null) {
                $this->alertKeys()->link($hook, $keyHash, $ticket->ticket_id);
            }
            $hook->recordCall($ticket->ticket_id);
        } catch (\Throwable $e) {
            $this->logFailure($hook, sprintf(
                '%s ticket %d but could not record it; %s',
                $did,
                $ticket->ticket_id,
                $stands
            ), $e);
        }

        if ($statusChange) {
            try {
                $statusChange->apply($ticket);
            } catch (\Throwable $e) {
                $this->logFailure($hook, sprintf(
                    '%s ticket %d but could not %s it; %s',
                    $did,
                    $ticket->ticket_id,
                    $statusChange->verb(),
                    $stands
                ), $e);
            }
        }

        try {
            $saved->sendNotifications();
        } catch (\Throwable $e) {
            $this->logFailure($hook, sprintf(
                '%s ticket %d but its notifications failed; %s',
                $did,
                $ticket->ticket_id,
                $stands
            ), $e);
        }
    }

    /**
     * The linked ticket as HookCall reads it. A deleted ticket is one staff
     * soft-deleted; one merged into another is gone, and never gets here.
     * The category plays no part, so a key follows its ticket wherever staff
     * move it.
     */
    protected function ticketFacts(Ticket $ticket): array
    {
        return [
            'closed' => TicketStatusChange::isClosed($ticket),
            'locked' => (bool) $ticket->ticket_locked,
            'deleted' => $ticket->discussion_state === 'deleted',
        ];
    }

    protected function alertKeys(): AlertKeys
    {
        /** @var AlertKeys $alertKeys */
        $alertKeys = $this->repository('Cav7\TicketWebhook:AlertKey');

        return $alertKeys;
    }

    /**
     * One entry in the XenForo error log, naming the hook by id and name
     * before $what. An exception's message follows $what, and its trace goes
     * in the entry. Rollback is on, for the reason the class docblock gives.
     */
    protected function logFailure(HookEntity $hook, string $what, ?\Throwable $e = null): void
    {
        $message = sprintf('[Cav7/TicketWebhook] Hook %d "%s" %s', $hook->hook_id, $hook->name, $what);
        if ($e) {
            \XF::logException($e, true, $message . ': ');
        } else {
            \XF::logError($message);
        }
    }

    /**
     * Success: 204, or with wait=true, 200 and the id of the ticket the call
     * reached, whether it opened that ticket or replied to it.
     */
    protected function reached(Ticket $ticket): AbstractReply
    {
        if (!$this->filter('wait', 'bool')) {
            return $this->status(204);
        }

        $this->app->response()->contentType('application/json', 'utf-8');

        return $this->view('Cav7\TicketWebhook:Hook\Reached', '', [
            'innerContent' => json_encode(['id' => (string) $ticket->ticket_id]),
        ]);
    }

    /**
     * A status code and an empty body, which is what the raw renderer makes
     * of a message reply.
     */
    protected function status(int $code): AbstractReply
    {
        return $this->message('', $code);
    }
}
