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
 * day. It agrees with the enlistment record Cav7/EnlistmentDefaults writes only
 * while the board timezone offset is at or behind UTC (true for the live UTC+0
 * board); EnlistmentDefaults stamps that record at board-local midnight, so on a
 * board ahead of UTC its midnight lands on the previous UTC day and flooring
 * plus UTC rendering would show that earlier day.
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
}
