<?php

namespace Cav7\EnlistmentDefaults\NF\Rosters\Pub\Controller;

use Cav7\EnlistmentDefaults\EnlistmentFormDefaults;
use NF\Rosters\Entity\RosterUser;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;

/**
 * Class extension on the vendor roster controller. The only thing it adds:
 * prefill the add-milpac form with the enlistment defaults (rank Recruit,
 * position New Recruit when the roster lists it, and Join Date and Promotion
 * Date set to today in board time) so a recruiter opens the form with the
 * common case already filled in.
 *
 * Form-only by design. It touches the milpac entity the GET render hands the
 * form view and nothing else: it never runs on the POST branch, so whatever the
 * recruiter submits is exactly what saves, and the API and import creation paths
 * are untouched. The values are the form's initial state, not enforced on save.
 *
 * Fail-open: the prefill is wrapped so a bad option or a custom-field rejection
 * is logged and the form still renders. A missing prefill costs the recruiter a
 * few keystrokes; it must never break the add form.
 */
class Roster extends XFCP_Roster
{
    public function actionAddUser(ParameterBag $params): Redirect|View|Error|AbstractReply
    {
        $reply = parent::actionAddUser($params);

        // Only the GET render carries the new milpac entity the form binds to.
        // The POST branch returns a redirect (or an error) and must be left
        // exactly as the vendor produced it, so the submitted values save as-is.
        if ($reply instanceof View)
        {
            try
            {
                $this->applyEnlistmentDefaults($reply);
            }
            catch (\Throwable $e)
            {
                \XF::logException(
                    $e,
                    false,
                    'Cav7/EnlistmentDefaults: add-form prefill failed: '
                );
            }
        }

        return $reply;
    }

    private function applyEnlistmentDefaults(View $reply): void
    {
        /** @var RosterUser|null $rosterUser */
        $rosterUser = $reply->getParam('rosterUser');
        if (!$rosterUser)
        {
            return;
        }

        $options = \XF::options();

        $defaults = EnlistmentFormDefaults::compute(
            (int) $options->cav7EnlistDefDefaultRankId,
            (int) $options->cav7EnlistDefDefaultPositionId,
            $this->rosterPositionIds($reply),
            \XF::$time,
            (string) $options->guestTimeZone
        );

        $rosterUser->rank_id = $defaults['rank_id'];
        if ($defaults['position_id'] !== null)
        {
            $rosterUser->position_id = $defaults['position_id'];
        }

        // joinDate and promoDate are free-text textbox custom fields. Set them
        // through the field set at the same edit mode the form renders with;
        // ignoreInvalid keeps a rejected value from putting an error on the
        // entity (prefill must never block the form).
        $customFields = $rosterUser->custom_fields;
        $customFields->set('joinDate', $defaults['joinDate'], 'moderator', true);
        $customFields->set('promoDate', $defaults['promoDate'], 'moderator', true);
    }

    /**
     * The position ids the roster lists, so EnlistmentFormDefaults can drop the
     * default position on a roster that does not carry it. Read from the same
     * Positions collection the form's position select renders from.
     *
     * @return int[]
     */
    private function rosterPositionIds(View $reply): array
    {
        /** @var \NF\Rosters\Entity\Roster|null $roster */
        $roster = $reply->getParam('roster');
        if (!$roster)
        {
            return [];
        }

        $ids = [];
        foreach ($roster->Positions as $positionPivot)
        {
            $ids[] = (int) $positionPivot->position_id;
        }

        return $ids;
    }
}
