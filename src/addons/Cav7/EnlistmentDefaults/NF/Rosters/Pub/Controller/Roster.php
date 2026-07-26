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
 * Fail-open, and the two failures it covers do not behave alike.
 *
 * A bad board timezone throws: compute() builds a DateTimeZone from it, which
 * raises on an unknown zone. That throw reaches the catch below and is logged,
 * and because it lands before any assignment, the whole prefill is lost — rank
 * and position stay as the vendor created them, not just the dates. (A wrong rank
 * or position id is a different matter: it does not throw, it prefills the wrong
 * value.)
 *
 * A rejected custom-field value does not throw. Both set() calls pass
 * ignoreInvalid, and every rejection branch in XF\CustomField\Set::set() then
 * returns false without throwing and without putting an error on the entity.
 * Nothing is logged; that one field is simply left unfilled while the other
 * still fills. This silence is deliberate — see
 * docs/adr/0003-a-prefill-rejection-stays-silent.md — because the recruiter is
 * looking at the blank field and types the value, so an operator has nothing to
 * act on. Do not go looking in the error log for a field that did not prefill.
 *
 * Either way the form still renders. A missing prefill costs the recruiter a few
 * keystrokes; it must never break the add form.
 *
 * The catch is deliberately \Throwable rather than \Exception. The failure it
 * exists for is vendor drift, which raises \Error. Narrowing it to \Exception
 * leaves the suite green — nothing here throws through it — but measured on a dev
 * stack it lets an \Error escape, and XenForo then returns an error page in place
 * of the add form. That is the fail-open guarantee gone, so narrowing this is a
 * deliberate act, not a tidy-up. Unlike the other fail-open catches on the
 * enlistment path it has no test pinning it, because this add-on has no
 * controller harness — not because one is impossible; tests/FailureLoggingTest.php
 * pins the entity-level catches the same way and would be the pattern to follow.
 * See docs/adr/0003-a-prefill-rejection-stays-silent.md.
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
