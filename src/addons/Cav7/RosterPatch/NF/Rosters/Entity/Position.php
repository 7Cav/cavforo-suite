<?php

namespace Cav7\RosterPatch\NF\Rosters\Entity;

use Cav7\RosterPatch\Repository\PositionGroupSync;

class Position extends XFCP_Position
{
	/**
	 * The vendor applies a position's extra_group_ids to a user only at the
	 * moment that user is seated: Service/Profile/Adder when a member is first
	 * added, and Service/Profile/Editor only inside a guard that fires when a
	 * member's own assignment changes (isChanged('position_id') or
	 * isChanged('secondary_position_ids')). Editing the position's own group
	 * list afterwards changes neither, so it reaches nobody
	 * already holding it — the grant is a one-time snapshot in xf_user_group_change
	 * (keyed nfRostersPosition-{id}), not a live binding — so the position config
	 * and the seated members silently diverge in both directions: a group added
	 * to the position never reaches existing holders, and a group removed from it
	 * is never revoked from them.
	 *
	 * Re-apply the position's current groups to every holder whenever that list
	 * changes. This does not touch positions whose list is unchanged but already
	 * stale on the members (the backlog that built up before this hook existed);
	 * the cav7-rosterpatch:sync-position-groups command reconciles those.
	 */
	protected function _postSave(): void
	{
		parent::_postSave();

		if ($this->isChanged('extra_group_ids'))
		{
			$this->getPositionGroupSyncRepo()->syncPositionHolders($this);
		}
	}

	protected function getPositionGroupSyncRepo(): PositionGroupSync
	{
		return $this->repository('Cav7\RosterPatch:PositionGroupSync');
	}
}
