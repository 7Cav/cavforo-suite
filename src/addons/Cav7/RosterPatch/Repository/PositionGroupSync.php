<?php

namespace Cav7\RosterPatch\Repository;

use Cav7\RosterPatch\GroupIdList;
use NF\Rosters\Entity\Position;
use XF\Mvc\Entity\Repository;

/**
 * Re-applies a position's group grants to the members who hold it, for both the
 * _postSave hook and the reconcile command.
 *
 * Failure policy: per-holder fail-open with logging. Orphaned roster rows (a
 * roster row whose user was deleted — XenForo keeps no foreign key cascade here)
 * are excluded at the source by getHolderUserIds, so the user-group-change
 * service is never asked to grant to a missing user. That matters because such a
 * grant throws, and inside the hook's enclosing save transaction the throw would
 * roll back the admin's legitimate position edit and leave the position
 * un-editable. A holder whose group write still fails (the service returns false)
 * is collected and logged, never aborting the edit or the rest of the batch; the
 * reconcile command additionally reports failed holders and exits non-zero. A
 * skipped holder simply stays drifted, no worse than before, until the next run.
 */
class PositionGroupSync extends Repository
{
	/**
	 * The vendor records a position's grant on each user under this change-set
	 * key (see Service/Profile/Adder + Editor). We reuse the same key so our
	 * re-apply overwrites the vendor's snapshot rather than stacking a second
	 * grant beside it.
	 */
	protected const KEY_PREFIX = 'nfRostersPosition-';

	/**
	 * Re-apply a position's current extra_group_ids to the members who hold it
	 * (primary or secondary) but whose recorded grant has drifted from it.
	 * Returns the number of members actually reconciled — holders already in
	 * sync are skipped, and any whose write failed are logged and not counted.
	 */
	public function syncPositionHolders(Position $position): int
	{
		$drifted = $this->getDriftedHolderUserIds($position);
		$failed = $this->applyPositionGroups($position, $drifted);

		if ($failed)
		{
			\XF::logError(sprintf(
				'RosterPatch: position %d group sync failed for user id(s) %s',
				$position->position_id,
				implode(', ', $failed)
			));
		}

		return count($drifted) - count($failed);
	}

	/**
	 * User ids of holders whose recorded grant for this position differs from
	 * its current extra_group_ids — the members a reconcile would actually
	 * change. "Differs" includes a holder with no recorded grant at all (the
	 * grant was never applied) and a holder whose snapshot still lists a group
	 * the position no longer grants: recorded nfRostersPosition-{id} vs the
	 * position config.
	 *
	 * @return int[]
	 */
	public function getDriftedHolderUserIds(Position $position): array
	{
		$userIds = $this->getHolderUserIds($position->position_id);
		if (!$userIds)
		{
			return [];
		}

		$snapshots = $this->db()->fetchPairs(
			'SELECT user_id, group_ids
				FROM xf_user_group_change
				WHERE change_key = ? AND user_id IN (' . $this->db()->quote($userIds) . ')',
			self::KEY_PREFIX . $position->position_id
		);

		$target = GroupIdList::normalize($position->extra_group_ids);

		$drifted = [];
		foreach ($userIds AS $userId)
		{
			// A holder with no snapshot row has '' here, which normalises to []
			// and so counts as drift whenever the position grants anything.
			if (GroupIdList::normalize($snapshots[$userId] ?? '') !== $target)
			{
				$drifted[] = $userId;
			}
		}

		return $drifted;
	}

	/**
	 * Apply the position's current groups to the given holders through XF's own
	 * user-group-change service — the same path the vendor's Adder/Editor use. A
	 * single addUserGroupChange per holder transitions that holder from their
	 * recorded snapshot to the position's current groups: added groups are
	 * granted, removed groups are revoked. The service diffs across all of a
	 * user's change sets, so it never strips a group the member still holds
	 * through another position, roster, or rank; an empty list revokes the grant
	 * cleanly (addUserGroupChange delegates to removeUserGroupChange when there
	 * is nothing left to add).
	 *
	 * @param int[] $userIds
	 * @return int[] user ids whose grant could not be applied (service returned false)
	 */
	public function applyPositionGroups(Position $position, array $userIds): array
	{
		if (!$userIds)
		{
			return [];
		}

		$key = self::KEY_PREFIX . $position->position_id;
		$groupIds = $position->extra_group_ids;

		/** @var \XF\Service\User\UserGroupChangeService $userGroupChange */
		$userGroupChange = $this->app()->service('XF:User\UserGroupChange');

		$failed = [];
		foreach ($userIds AS $userId)
		{
			// The service returns false when the user's own save fails; it is not
			// a no-op we can ignore, or the holder is reported reconciled without
			// being reconciled.
			if (!$userGroupChange->addUserGroupChange($userId, $key, $groupIds))
			{
				$failed[] = $userId;
			}
		}

		return $failed;
	}

	/**
	 * User ids of every member seated in the position, as a primary or a
	 * secondary position. The INNER JOIN on xf_user drops orphaned roster rows
	 * (a member whose XenForo account was deleted; there is no FK cascade), so
	 * the group-change service is never handed a missing user id. secondary_position_ids
	 * is the vendor's own comma list on xf_nf_rosters_user; RosterAudit's
	 * Position::_preDelete guards on the same primary-or-secondary shape.
	 *
	 * @return int[]
	 */
	public function getHolderUserIds(int $positionId): array
	{
		$userIds = $this->db()->fetchAllColumn(
			'SELECT DISTINCT ru.user_id
				FROM xf_nf_rosters_user AS ru
				INNER JOIN xf_user AS u ON (u.user_id = ru.user_id)
				WHERE ru.position_id = ? OR FIND_IN_SET(?, ru.secondary_position_ids)',
			[$positionId, $positionId]
		);

		return array_map('intval', $userIds);
	}
}
