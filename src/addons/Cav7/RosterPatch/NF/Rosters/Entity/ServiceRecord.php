<?php

namespace Cav7\RosterPatch\NF\Rosters\Entity;

use Cav7\RosterPatch\MilpacDate;

/**
 * Class extension on NF\Rosters\Entity\ServiceRecord.
 *
 * record_date is a calendar day, and carries the same flaw as award_date: the
 * vendor controller stores the entered day at the current time of day, which
 * drifts across midnight when rendered in a viewer's timezone. We normalise it
 * to midnight UTC of its UTC day on save, the canonical form a calendar date
 * round-trips through.
 *
 * Flooring runs whenever the column is set (an insert, or an edit that touched
 * the date), and never changes the UTC day the value falls on, only the time of
 * day. It agrees with the enlistment record Cav7/EnlistmentDefaults writes on
 * any board timezone: EnlistmentDefaults now stamps that record at midnight UTC
 * (and its blank/unparseable fallback at midnight UTC of the creation day), so
 * the value is already canonical and floorToMidnightUtc() is a no-op for it.
 *
 * Display is symmetric with storage. The vendor's getRecordDate() reads the
 * column with date(), which follows the PHP process timezone, so it and
 * RosterPatch's own UTC rendering only agree while that timezone is UTC. We
 * override it to render through MilpacDate::render() (UTC), so the stored
 * midnight-UTC day shows as the same calendar day whatever the process timezone.
 */
class ServiceRecord extends XFCP_ServiceRecord
{
	protected function _preSave(): void
	{
		if ($this->isInsert() || $this->isChanged('record_date'))
		{
			$this->record_date = MilpacDate::floorToMidnightUtc((int) $this->record_date);
		}

		parent::_preSave();
	}

	public function getRecordDate(): string
	{
		return MilpacDate::render((int) $this->record_date);
	}
}
