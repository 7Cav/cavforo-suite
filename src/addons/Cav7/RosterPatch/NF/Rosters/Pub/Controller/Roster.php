<?php

namespace Cav7\RosterPatch\NF\Rosters\Pub\Controller;

use Cav7\RosterPatch\MilpacDate;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Mvc\Reply\Error;
use XF\Mvc\Reply\Redirect;
use XF\Mvc\Reply\View;

/**
 * Class extension on the vendor roster controller, for the award and
 * service-record date handling (issue #43).
 *
 * Two jobs, both thin wrappers around the vendor actions:
 *
 *  - On save, reject a submitted day that is not a real calendar date before
 *    the vendor stores it. The vendor's own parse is lenient: it rolls an
 *    out-of-range value over (2026-13-40 becomes a date in 2027) and saves it
 *    silently. We validate with MilpacDate::parseEnteredDay and return the
 *    vendor's own "invalid date" error when it does not round-trip, then defer
 *    to the vendor for the save itself. Midnight-UTC normalisation of the
 *    accepted value happens in the entity extensions.
 *
 *  - On add, prefill the new entry's date with the editor's own today in their
 *    timezone, not UTC's today, so a staffer outside UTC does not open the form
 *    on the wrong day.
 *
 * Cav7/EnlistmentDefaults also extends this controller (for actionAddUser);
 * XenForo chains both extensions, and the two touch different actions.
 */
class Roster extends XFCP_Roster
{
	public function actionProfileAwardsAdd(ParameterBag $params): View|AbstractReply
	{
		$reply = parent::actionProfileAwardsAdd($params);
		$this->prefillEditorToday($reply, 'award_date');

		return $reply;
	}

	public function actionProfileServiceRecordAdd(ParameterBag $params): View|AbstractReply
	{
		$reply = parent::actionProfileServiceRecordAdd($params);
		$this->prefillEditorToday($reply, 'record_date');

		return $reply;
	}

	public function actionProfileAwardsSave(ParameterBag $params): Redirect|Error|AbstractReply
	{
		$error = $this->validateEnteredDate('award_date');
		if ($error !== null)
		{
			return $error;
		}

		return parent::actionProfileAwardsSave($params);
	}

	public function actionProfileServiceRecordSave(ParameterBag $params): Redirect|Error|AbstractReply
	{
		$error = $this->validateEnteredDate('record_date');
		if ($error !== null)
		{
			return $error;
		}

		return parent::actionProfileServiceRecordSave($params);
	}

	/**
	 * Reject a submitted day that is not a real calendar date, with the vendor's
	 * own invalid-date error, before the vendor parses it. Returns null when the
	 * value is a clean calendar day, so the caller defers to the vendor action.
	 */
	protected function validateEnteredDate(string $input): ?AbstractReply
	{
		if (MilpacDate::parseEnteredDay($this->filter($input, 'str')) === null)
		{
			return $this->error(\XF::phrase('please_enter_valid_date'));
		}

		return null;
	}

	/**
	 * Prefill a brand-new entry's date with the editor's own today. The add
	 * actions hand the form a freshly created entity; we set its date column to
	 * midnight UTC of the editor's today so the date field opens on the editor's
	 * day. The vendor getter renders that column in UTC, so it reads back as the
	 * same day.
	 *
	 * Fail-open: a bad timezone or option is logged and the form still renders;
	 * a missing prefill costs the staffer a correction, it must never break the
	 * add form.
	 */
	protected function prefillEditorToday(AbstractReply $reply, string $column): void
	{
		if (!($reply instanceof View))
		{
			return;
		}

		try
		{
			$record = $reply->getParam('record');
			if (!$record || !$record->isInsert())
			{
				return;
			}

			$timezone = \XF::language()->getTimeZone()->getName();
			$record->{$column} = MilpacDate::editorTodayTimestamp(\XF::$time, $timezone);
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'Cav7/RosterPatch: date prefill failed: ');
		}
	}
}
