<?php

namespace Cav7\RosterAudit\NF\Rosters\Pub\Controller;

use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Redirect;

/**
 * Hardening for NF/Rosters 2.1.x: the vendor's actionProfileAwardsSave and
 * actionProfileServiceRecordSave perform no canManageAwards() /
 * canManageRecords() check, while the corresponding Add/Edit/Delete actions
 * all do. The guard resolves the roster user exactly as the parent does
 * ($params->relation_id via assertRosterUserExists) and runs before it.
 *
 * If a vendor update adds these checks, this extension becomes a harmless
 * duplicate and can be dropped.
 */
class Roster extends XFCP_Roster
{
	public function actionProfileAwardsSave(ParameterBag $params): Redirect|Error
	{
		$rosterUser = $this->assertRosterUserExists($params->relation_id);
		if (!$rosterUser->canManageAwards())
		{
			return $this->noPermission();
		}

		return parent::actionProfileAwardsSave($params);
	}

	public function actionProfileServiceRecordSave(ParameterBag $params): Redirect|Error
	{
		$rosterUser = $this->assertRosterUserExists($params->relation_id);
		if (!$rosterUser->canManageRecords())
		{
			return $this->noPermission();
		}

		return parent::actionProfileServiceRecordSave($params);
	}
}
