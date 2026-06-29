<?php

namespace Cav7\RosterPatch\NF\Rosters\Entity;

use Cav7\RosterPatch\MilpacDate;

/**
 * Class extension on NF\Rosters\Entity\RosterUserAward.
 *
 * award_date is a calendar day. The vendor controller parses the submitted day
 * with \DateTime::createFromFormat('Y-m-d', ...), which keeps the current time
 * of day instead of midnight, so the stored value sits at an arbitrary point in
 * the day and used to drift across midnight when rendered in a viewer's
 * timezone. We normalise it to midnight UTC of its UTC day on save, the
 * canonical form a calendar date round-trips through.
 *
 * Flooring runs whenever the column is set (an insert, or an edit that touched
 * the date), so the controller path and any programmatic write land on the same
 * canonical value. It is display-invariant: it never changes the UTC day the
 * value falls on, only the time of day, so a grant written by another add-on
 * (for example a Cav7/EnlistmentDefaults PUC grant) keeps the day it already
 * shows.
 */
class RosterUserAward extends XFCP_RosterUserAward
{
	protected function _preSave(): void
	{
		if ($this->isInsert() || $this->isChanged('award_date'))
		{
			$this->award_date = MilpacDate::floorToMidnightUtc((int) $this->award_date);
		}

		parent::_preSave();
	}
}
