<?php

namespace Cav7\FormsUserQuestion\Pub\Controller;

use XF\Finder\UserFinder;
use XF\Pub\Controller\AbstractController;
use XF\Util\Str;

/**
 * The user picker's name lookup, forms-user-question/find?q=<typed text>
 * (issue #314). cav7_fuq_question_macros::name_box points XenForo's autocomplete
 * box at it. What it suggests, and to whom, is in the addon's README.
 *
 * It is the addon's own route, not XenForo's members/find, because members/find
 * suggests only forum users active in the last 180 days. It filters with
 * UserFinder::isValidUser(true), and the argument adds a last_activity cut-off.
 * The Discharge Tool has to name someone who went quiet months ago, so the
 * picker must still find them. This lookup calls isValidUser() without the
 * cut-off and otherwise copies members/find, including two things it leaves to
 * XenForo:
 *
 *  - The reply goes through members/find's own view, XF:Member\Find, so the
 *    payload is the same and XenForo's stock auto-complete handler reads it
 *    unchanged.
 *  - It adds no gate of its own, so only the generic public controller gates
 *    apply, which are viewing permission and not being banned.
 *
 * Checked against XenForo 2.3.11. After a XenForo upgrade, compare
 * actionFind() with XF\Pub\Controller\MemberController::actionFind(), and
 * check that XF:Member\Find still renders the payload the stock handler reads.
 */
class Lookup extends AbstractController
{
    public function actionFind()
    {
        $q = ltrim($this->filter('q', 'str', ['no-trim']));

        if ($q !== '' && Str::strlen($q) >= 2) {
            $finder = $this->finder(UserFinder::class);
            $users = $finder
                ->where('username', 'like', $finder->escapeLike($q, '?%'))
                ->isValidUser()
                ->fetch(10);
        } else {
            $users = [];
            $q = '';
        }

        return $this->view('XF:Member\Find', '', [
            'q' => $q,
            'users' => $users,
        ]);
    }
}
