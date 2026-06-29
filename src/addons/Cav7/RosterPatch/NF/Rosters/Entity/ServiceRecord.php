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
 * the date). It is display-invariant: it never changes the UTC day the value
 * falls on, only the time of day, so the enlistment record Cav7/EnlistmentDefaults
 * writes keeps the day it already shows.
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
