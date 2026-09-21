<?php

namespace Cav7\EnlistmentNextSteps\Snog\Forms\Pub\Controller;

use Cav7\EnlistmentNextSteps\NextStepsDecision;
use Snog\Forms\Entity\Form as FormEntity;
use Snog\Forms\Service\Form\Submit;
use XF\Entity\Thread;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\Redirect;

/**
 * Sends a recruit to the next-steps page after a triggering form is accepted,
 * in place of the vendor's toast and redirect (issue #291). Advanced Forms
 * fires no code event after a submission, so this class extension over its
 * public form controller is the only attachment point.
 *
 * What it assumes about the vendor, and what breaks silently if the vendor
 * moves. Checked against [OzzModz] Advanced Forms 2.2.6 RC3:
 *
 *  - actionSubmit() creates its submit service through the protected
 *    setupFormSubmitter(), which this class overrides to keep the instance.
 *    A parent that built the service some other way leaves $submitter null
 *    here, and every submission falls back to the vendor reply.
 *  - On success actionSubmit() returns a Redirect built from
 *    $form->getRedirectUrl() and $form->thanks. Any other reply shape (the
 *    purchase view, a validation error) passes through untouched.
 *  - The created thread is read off the service's thread creator. A form
 *    that posts only through the vendor's second thread creator produces no
 *    thread here, and keeps the vendor reply.
 *  - The form's own id arrives as the route's `posid` parameter.
 *
 * The vendor form submits over AJAX with data-ajax-redirect and
 * data-force-flash-message, so the toast is the redirect's message. A Redirect
 * carrying an empty message makes the vendor's JS skip the flash and follow
 * the URL at once, which is what removes the toast. Passing null instead would
 * substitute XenForo's "Your changes have been saved".
 */
class Form extends XFCP_Form
{
    /** @var Submit|null */
    protected $cav7ensSubmitter = null;

    protected function setupFormSubmitter(FormEntity $form, ?Thread $replyThread = null)
    {
        $submitter = parent::setupFormSubmitter($form, $replyThread);
        $this->cav7ensSubmitter = $submitter;

        return $submitter;
    }

    public function actionSubmit(ParameterBag $params)
    {
        $reply = parent::actionSubmit($params);

        if (!$reply instanceof Redirect) {
            return $reply;
        }

        $thread = $this->cav7ensCreatedThread();
        $threadId = NextStepsDecision::threadToShow(
            (string) $this->options()->cav7ENSTriggeringFormIds,
            (int) $params['posid'],
            $thread ? (int) $thread->thread_id : null
        );
        if ($threadId === null) {
            return $reply;
        }

        return $this->redirect(
            $this->buildLink('enlistment-next-steps', ['thread_id' => $threadId]),
            ''
        );
    }

    protected function cav7ensCreatedThread(): ?Thread
    {
        $submitter = $this->cav7ensSubmitter;
        if (!$submitter) {
            return null;
        }

        $creator = $submitter->getThreadCreator();
        if (!$creator) {
            return null;
        }

        $thread = $creator->getThread();

        return ($thread instanceof Thread && $thread->thread_id) ? $thread : null;
    }
}
