<?php

namespace Cav7\EnlistmentNextSteps\Pub\Controller;

use XF\Entity\Thread;
use XF\Mvc\ParameterBag;
use XF\Pub\Controller\AbstractController;

/**
 * The next-steps page (issue #291): GET enlistment-next-steps/{thread_id}/.
 * Renders the title, RRD's paragraph, the enlistment process image and the
 * "I Understand" link to the applicant's own thread, inside the normal page
 * layout.
 *
 * The page opens for anyone the thread itself would open for, and for nobody
 * else, so it cannot leak that a thread exists. Node 325 lets a member view
 * their own threads and not others', which is what admits the applicant and
 * refuses everyone without a clerk role. The visitor is not compared to the
 * thread author, because a form can be configured to post as a fixed user.
 */
class NextSteps extends AbstractController
{
    public function actionIndex(ParameterBag $params)
    {
        $visitor = \XF::visitor();

        $with = ['Forum', 'Forum.Node', 'Forum.Node.Permissions|' . $visitor->permission_combination_id];

        /** @var Thread|null $thread */
        $thread = $this->em()->find('XF:Thread', $params['thread_id'], $with);
        if (!$thread) {
            return $this->noPermission();
        }
        if (!$thread->canView($error)) {
            return $this->noPermission($error);
        }

        return $this->view('Cav7\EnlistmentNextSteps:NextSteps', 'cav7_ens_next_steps', [
            'thread' => $thread,
        ]);
    }
}
