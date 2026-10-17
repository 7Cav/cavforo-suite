<?php

namespace Cav7\TicketWebhook\Pub\Controller;

use Cav7\TicketWebhook\Entity\Hook as HookEntity;
use Cav7\TicketWebhook\HookCall;
use Cav7\TicketWebhook\NotPermitted;
use Cav7\TicketWebhook\TicketOpener;
use Cav7\TicketWebhook\TicketReplier;
use Cav7\TicketWebhook\TicketStatusChange;
use NF\Tickets\Entity\Ticket;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

/**
 * The one public route, `ticket-webhooks/{hook_id}/{token}`. A caller sends
 * Discord's webhook request here, and the call opens a ticket or, when its
 * alert key links a ticket it can still reach, replies to that one. An
 * all-clear then closes the ticket, and a firing call reopens a closed one.
 * HookCall decides which. See GLOSSARY.md for hook, caller, call, alert key
 * and all-clear, ADR-0001 for why the request is Discord's, and ADR-0002 for
 * alert keys.
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
 *        alert key could not be looked up, or the opener names no user
 *
 * Every 403 and 500 leaves one entry in the XenForo error log naming the hook
 * by id and name, and the ticket too when a reply failed. A 500 means the
 * call saved nothing. Nothing else is told.
 *
 * Errors are logged with logException's rollback on. The Creator and the
 * Replier open a transaction before code that can throw, and nothing commits
 * a transaction left open by a throw. It rolls back when the connection
 * closes, and an error row written inside it would go with it.
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
            \XF::logError(sprintf(
                '[Cav7/TicketWebhook] Hook %d "%s" could not open its ticket: opener user id %d names no user.',
                $hook->hook_id,
                $hook->name,
                $repo->openerUserId()
            ));

            return $this->status(500);
        }

        $keyHash = $call->keyHash();
        try {
            $linked = $keyHash !== null ? $hook->linkedTicket($keyHash) : null;
            $facts = $linked ? $this->ticketFacts($linked) : null;
        } catch (\Throwable $e) {
            \XF::logException($e, true, sprintf(
                '[Cav7/TicketWebhook] Hook %d "%s" could not look up its alert key: ',
                $hook->hook_id,
                $hook->name
            ));

            return $this->status(500);
        }

        $action = $call->action($facts);
        $then = $this->statusChange($action);

        if (in_array($action, [HookCall::REPLY, HookCall::REPLY_AND_REOPEN, HookCall::REPLY_AND_CLOSE], true)) {
            try {
                \XF::asVisitor($opener, function () use ($hook, $linked, $call, $keyHash, $then) {
                    (new TicketReplier())->reply($hook, $linked, $call->replyMessage(), $keyHash, $then);
                });
            } catch (\Throwable $e) {
                \XF::logException($e, true, sprintf(
                    '[Cav7/TicketWebhook] Hook %d "%s" could not reply to ticket %d: ',
                    $hook->hook_id,
                    $hook->name,
                    $linked->ticket_id
                ));

                return $this->status(500);
            }

            return $this->reached($linked);
        }

        try {
            $ticket = \XF::asVisitor($opener, function () use ($hook, $call, $keyHash, $then) {
                return (new TicketOpener())->open($hook, $call->title(), $call->message(), $keyHash, $then);
            });
        } catch (NotPermitted $e) {
            \XF::logError(sprintf(
                '[Cav7/TicketWebhook] Hook %d "%s" could not open its ticket: %s',
                $hook->hook_id,
                $hook->name,
                $e->getMessage()
            ));

            return $this->status(403);
        } catch (\Throwable $e) {
            \XF::logException($e, true, sprintf(
                '[Cav7/TicketWebhook] Hook %d "%s" could not open its ticket: ',
                $hook->hook_id,
                $hook->name
            ));

            return $this->status(500);
        }

        return $this->reached($ticket);
    }

    /**
     * The status change an action makes once its ticket or reply is recorded:
     * close on an all-clear, reopen on a firing call for a closed ticket, and
     * none otherwise.
     */
    protected function statusChange(string $action): ?TicketStatusChange
    {
        switch ($action) {
            case HookCall::REPLY_AND_CLOSE:
            case HookCall::OPEN_AND_CLOSE:
                return TicketStatusChange::close();
            case HookCall::REPLY_AND_REOPEN:
                return TicketStatusChange::reopen();
            default:
                return null;
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
        $status = $ticket->Status;

        return [
            'closed' => $status ? (bool) $status->is_close : false,
            'locked' => (bool) $ticket->ticket_locked,
            'deleted' => $ticket->discussion_state === 'deleted',
        ];
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
