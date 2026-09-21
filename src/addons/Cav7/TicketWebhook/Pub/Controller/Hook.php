<?php

namespace Cav7\TicketWebhook\Pub\Controller;

use Cav7\TicketWebhook\Entity\Hook as HookEntity;
use Cav7\TicketWebhook\HookPost;
use Cav7\TicketWebhook\NotPermitted;
use Cav7\TicketWebhook\TicketOpener;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

/**
 * The one public route, `ticket-webhooks/{hook_id}/{token}`: a caller posts
 * Discord's webhook request here and one ticket opens. See CONTEXT.md for
 * hook, caller and token, and ADR-0001 for why the request is Discord's.
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
 *   204  the ticket opened
 *   200  the ticket opened and the query carried wait=true; the body is
 *        {"id": "<ticket id>"}
 *   400  the body is not a JSON object, or yields no text
 *   403  the opener may not open a ticket in the hook's category
 *   404  no such hook, an inactive hook, or no matching token
 *   405  a method other than POST
 *   500  the Creator refused the ticket, or the opener names no user
 *
 * Every 403 and 500 leaves one entry in the XenForo error log naming the hook
 * by id and name. Nothing else is told.
 */
class Hook extends \XF\Pub\Controller\AbstractController
{
    /**
     * No session posts here, so there is no CSRF token to check.
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

        $post = HookPost::decide(
            $hook ? $hook->facts() : null,
            $pathToken,
            $authorization !== '' ? $authorization : null,
            json_decode($this->request->getInputRaw(), true)
        );

        if ($post->outcome() === HookPost::NOT_FOUND) {
            return $this->status(404);
        }
        if ($post->outcome() === HookPost::BAD_REQUEST) {
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

        try {
            $ticket = \XF::asVisitor($opener, function () use ($hook, $post) {
                return (new TicketOpener())->open($hook, $post->title(), $post->message());
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
            \XF::logException($e, false, sprintf(
                '[Cav7/TicketWebhook] Hook %d "%s" could not open its ticket: ',
                $hook->hook_id,
                $hook->name
            ));

            return $this->status(500);
        }

        if (!$this->filter('wait', 'bool')) {
            return $this->status(204);
        }

        $this->app->response()->contentType('application/json', 'utf-8');

        return $this->view('Cav7\TicketWebhook:Hook\Opened', '', [
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
