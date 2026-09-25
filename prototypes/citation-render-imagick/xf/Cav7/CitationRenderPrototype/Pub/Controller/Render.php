<?php

namespace Cav7\CitationRenderPrototype\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

/**
 * PROTOTYPE, throwaway (#303). Serves one render at
 * `citation-render-prototype/{member_id}/{token}/{revision}/{filename}.jpg`, to see what
 * XenForo, nginx and Cloudflare do with it. Ignores its parameters and always renders the
 * fixture grant. Not the spec's route.
 *
 * XenForo's router strips a trailing `.jpg` as a response type and takes the last path
 * segment as the action name, so the readable filename arrives as an action. `__call`
 * takes every action, since `Dispatcher::dispatchClass()` only asks `is_callable()`.
 */
class Render extends \XF\Pub\Controller\AbstractController
{
    public function checkCsrfIfNeeded($action, ParameterBag $params): void
    {
    }

    protected function preDispatchType($action, ParameterBag $params): void
    {
        $this->setResponseType('raw');
    }

    protected function canUpdateSessionActivity($action, ParameterBag $params, AbstractReply &$reply, &$viewState): bool
    {
        return false;
    }

    public function __call($method, $args)
    {
        if (str_starts_with($method, 'action')) {
            return $this->actionIndex(...$args);
        }
        throw new \BadMethodCallException($method);
    }

    public function actionIndex(ParameterBag $params): AbstractReply
    {
        $dir = \XF::getAddOnDirectory() . '/Cav7/CitationRenderPrototype/proto';
        require_once $dir . '/render.php';
        $grant = require $dir . '/grant.php';

        $renderer = new \CitationRender($dir, $dir . '/fonts');
        $renderer->verify = false;
        $bytes = $renderer->render($grant, $grant['texts'][$this->filter('text', 'str') ?: 'short'] ?? $grant['texts']['short']);

        // A visitor with no session cookie gets a new session, and Advanced Forms writes
        // snogFormsCount into it at app_pub_start_end, so XenForo would save it and send
        // Set-Cookie, and Cloudflare will not cache a response that sets a cookie. A session
        // that was never saved can be dropped without touching anyone's stored session.
        // expunge() on a stored one would delete it and sign the member out, hence the guard.
        $session = $this->session();
        if (!$session->exists()) {
            $session->expunge();
        }

        return $this->view('Cav7\CitationRenderPrototype:Render', '', [
            'bytes' => $bytes,
            'filename' => basename($this->request->getRoutePath()),
            'renderMs' => $renderer->state['total ms'],
        ]);
    }
}
