<?php

namespace Cav7\RosterAudit\NF\Rosters\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Redirect;

/**
 * Hardening for NF/Rosters 2.1.x: the vendor's actionAwardsSave and
 * actionServiceRecordSave perform no canManageAwards()/canManageRecords()
 * check (the corresponding Add/Edit/Delete actions all do), and call
 * ->getTimestamp() on an unchecked DateTime::createFromFormat() result, which
 * fatals on malformed input. Both guards run before the parent action.
 *
 * If a vendor update adds these checks, this extension becomes a harmless
 * duplicate and can be dropped.
 */
class Roster extends XFCP_Roster
{
	public function actionAwardsSave(ParameterBag $params): Redirect|Error
	{
		$rosterUser = $this->assertRosterUserExists($this->filter('unique_id', 'int'));
		if (!$rosterUser->canManageAwards())
		{
			return $this->noPermission();
		}

		if (!\DateTime::createFromFormat('Y-m-d', $this->filter('award_date', 'str')))
		{
			return $this->error(\XF::phrase('cav7_raudit_invalid_date'));
		}

		return parent::actionAwardsSave($params);
	}

	public function actionServiceRecordSave(ParameterBag $params): Redirect|Error
	{
		$rosterUser = $this->assertRosterUserExists($this->filter('unique_id', 'int'));
		if (!$rosterUser->canManageRecords())
		{
			return $this->noPermission();
		}

		if (!\DateTime::createFromFormat('Y-m-d', $this->filter('record_date', 'str')))
		{
			return $this->error(\XF::phrase('cav7_raudit_invalid_date'));
		}

		return parent::actionServiceRecordSave($params);
	}
}
