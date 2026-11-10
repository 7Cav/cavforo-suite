<?php

namespace Cav7\Citations\NF\Rosters\Pub\Controller;

use Cav7\Citations\Grant\RowSaveRules;
use Cav7\Citations\Service\GrantLinks;
use Cav7\Citations\Storage\RowKind;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;
use XF\PrintableException;

/**
 * The citation picker on the row forms, and the check around the vendor's save. A refusal
 * comes before the save, so no row or file gets written, and a pick attaches only once the
 * vendor has replied with a redirect. A new row commits with its attach or not at all, since
 * the vendor's form stays filled after an error and a second Save would add a second row.
 * Direct callers of the image services never come through here, so Cav7/EnlistmentDefaults
 * is untouched. delete_image is never refused: a removal is how a linked row takes a file.
 *
 * Assumes the vendor reads the file with getFile('upload', false, false), finds the row by
 * record_id alone, makes new rows through RosterUser::getNewAward() and getNewServiceRecord(),
 * and reads delete_image from the request after this runs. Re-check on a dev stack after a
 * vendor upgrade.
 */
class Roster extends XFCP_Roster
{
    private const NO_CITATION = 'none';

    public function actionProfileAwardsAdd(ParameterBag $params): View|AbstractReply
    {
        return $this->withPicker(parent::actionProfileAwardsAdd($params));
    }

    public function actionAwardsEdit(ParameterBag $params): View|AbstractReply
    {
        return $this->withPicker(parent::actionAwardsEdit($params));
    }

    public function actionProfileServiceRecordAdd(ParameterBag $params): View|AbstractReply
    {
        return $this->withPicker(parent::actionProfileServiceRecordAdd($params));
    }

    public function actionServiceRecordEdit(ParameterBag $params): View|AbstractReply
    {
        return $this->withPicker(parent::actionServiceRecordEdit($params));
    }

    public function actionProfileAwardsSave(ParameterBag $params): Redirect|Error|AbstractReply
    {
        return $this->aroundSave($params, RowKind::Award, fn () => parent::actionProfileAwardsSave($params));
    }

    public function actionProfileServiceRecordSave(ParameterBag $params): Redirect|Error|AbstractReply
    {
        return $this->aroundSave($params, RowKind::Record, fn () => parent::actionProfileServiceRecordSave($params));
    }

    private function withPicker(AbstractReply $reply): AbstractReply
    {
        if ($reply instanceof View && $this->options()->cav7CitCutover) {
            $reply->setParam('citationPicker', $this->links()->picker($reply->getParam('rosterUser')->relation_id));
            $reply->setParam('citationOpenRecordType', RowSaveRules::OPEN_RECORD_TYPE);
        }
        return $reply;
    }

    /**
     * Anything else about the request is the vendor's to answer, including a visitor who can't
     * edit this row.
     *
     * @param \Closure(): AbstractReply $save
     */
    private function aroundSave(ParameterBag $params, RowKind $kind, \Closure $save): AbstractReply
    {
        $choice = $this->filter('citation_pick', 'str');
        $pick = ctype_digit($choice) ? (int) $choice : 0;
        $hasFile = (bool) $this->request->getFile('upload', false, false);

        if (!$this->isPost() || (!$hasFile && $pick === 0 && $choice !== self::NO_CITATION)) {
            return $save();
        }

        $rosterUser = $this->assertRosterUserExists($params->relation_id);
        if (!($kind === RowKind::Award ? $rosterUser->canManageAwards() : $rosterUser->canManageRecords())) {
            return $save();
        }

        $links = $this->links();
        $recordId = $this->filter('record_id', 'int');
        $refusal = $links->saveRefusal(
            $kind,
            $recordId,
            $rosterUser->relation_id,
            $this->filter('record_type_id', 'int'),
            $hasFile,
            $pick,
            (bool) $this->options()->cav7CitCutover,
        );
        if ($refusal !== null) {
            return $this->error($refusal);
        }

        if ($choice === self::NO_CITATION) {
            $this->request->set('delete_image', 1);
        }

        // The radio defaults to the row's own pick, so the delete box beside it means No citation.
        if ($pick !== 0 && $this->filter('delete_image', 'bool') && $pick === $links->heldPick($kind, $recordId)) {
            $pick = 0;
        }

        if ($pick !== 0 && $links->makesRow($kind, $recordId)) {
            $refused = null;
            try {
                return $links->holdingPick($pick, function () use ($save, $links, $kind, $rosterUser, $pick, &$refused): AbstractReply {
                    $reply = $save();
                    if ($reply instanceof Redirect) {
                        try {
                            $links->attach($kind, $rosterUser->newRow->record_id, $pick);
                        } catch (PrintableException $e) {
                            $refused = $e;
                            throw $e;
                        }
                    }
                    return $reply;
                });
            } catch (PrintableException $e) {
                if ($e !== $refused) {
                    throw $e;
                }
                return $this->error(\XF::phrase('cav7_cit_row_not_saved', ['reason' => $e->getMessage()]));
            }
        }

        $reply = $save();
        if ($pick !== 0 && $reply instanceof Redirect) {
            try {
                $links->attach($kind, $rosterUser->newRow?->record_id ?? $recordId, $pick);
            } catch (PrintableException $e) {
                // The vendor's save has committed. An error, not the redirect: the vendor's ajax
                // form follows a redirect at once and never shows its message.
                return $this->error(\XF::phrase('cav7_cit_saved_without_citation', ['reason' => $e->getMessage()]));
            }
        }
        return $reply;
    }

    private function links(): GrantLinks
    {
        return $this->service(GrantLinks::class);
    }
}
