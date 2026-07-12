<?php

namespace Cav7\MilpacMention\Pub\Controller;

use Cav7\MilpacMention\MilpacResolver;
use XF\Pub\Controller\AbstractController;

/**
 * The $name completer's find endpoint (phase 2, spec §4.3). GET
 * milpac-mention/find?q=… returns the standard XF autocomplete envelope
 * {q, results}, modelled on XF\Pub\Controller\MemberController::actionFind: a
 * username prefix match, filtered to isValidUser(true), joined against
 * NF\Rosters:RosterUser so only milpac owners come back. Each result row carries
 * the display fields (rank, name, roster — §4.5) and the value to insert (the
 * named roster-profile link — §4.2). The join and the row builders live in the
 * shared MilpacResolver so detection (#84) and this endpoint stay in step.
 *
 * Server side only — the editor autocompleter that consumes this is ticket #89.
 */
class MilpacMention extends AbstractController
{
    public function actionFind()
    {
        $q = ltrim($this->filter('q', 'str', ['no-trim']));

        if (MilpacResolver::isFindQueryLongEnough($q)) {
            /** @var \XF\Finder\UserFinder $userFinder */
            $userFinder = $this->finder('XF:User');
            $users = MilpacResolver::findMilpacOwningUsers($userFinder, $q, 10);
        } else {
            // A q shorter than two characters returns an empty result set, the
            // same as MemberController::actionFind ({q: "", results: []}).
            $users = [];
            $q = '';
        }

        $viewParams = [
            'q' => $q,
            'users' => $users,
        ];
        return $this->view('Cav7\MilpacMention:MilpacMention\Find', '', $viewParams);
    }
}
