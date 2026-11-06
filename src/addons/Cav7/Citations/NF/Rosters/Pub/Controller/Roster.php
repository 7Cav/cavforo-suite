<?php

namespace Cav7\Citations\NF\Rosters\Pub\Controller;

use Cav7\Citations\Service\GrantLinks;
use Cav7\Citations\Storage\RowKind;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Redirect;

/**
 * Refuses a citation file before the vendor's save runs, so no row or file gets written.
 * Direct callers of the image services never come through here, so Cav7/EnlistmentDefaults
 * is untouched. delete_image is never refused: a removal is how a linked row takes a file.
 *
 * Assumes the vendor reads the file with getFile('upload', false, false) and finds the row
 * by record_id alone. Re-check on a dev stack after a vendor upgrade.
 */
class Roster extends XFCP_Roster
{
    public function actionProfileAwardsSave(ParameterBag $params): Redirect|Error|AbstractReply
    {
        return $this->refusedFile($params, RowKind::Award) ?? parent::actionProfileAwardsSave($params);
    }

    public function actionProfileServiceRecordSave(ParameterBag $params): Redirect|Error|AbstractReply
    {
        return $this->refusedFile($params, RowKind::Record) ?? parent::actionProfileServiceRecordSave($params);
    }

    /** Anything else about the request is the vendor's to answer, including a visitor who can't edit this row. */
    private function refusedFile(ParameterBag $params, RowKind $kind): ?AbstractReply
    {
        if (!$this->isPost() || !$this->request->getFile('upload', false, false)) {
            return null;
        }

        $rosterUser = $this->assertRosterUserExists($params->relation_id);
        if (!($kind === RowKind::Award ? $rosterUser->canManageAwards() : $rosterUser->canManageRecords())) {
            return null;
        }

        /** @var GrantLinks $links */
        $links = $this->service(GrantLinks::class);
        $refusal = $links->saveRefusal(
            $kind,
            $this->filter('record_id', 'int'),
            $rosterUser->relation_id,
            $this->filter('record_type_id', 'int'),
            true,
            0,
            (bool) $this->options()->cav7CitCutover,
        );

        return $refusal === null ? null : $this->error($refusal);
    }
}
