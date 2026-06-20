<?php

namespace Cav7\RosterPatch;

/**
 * Group ids reduced to a sorted, unique, positive int list, so a recorded
 * change-set snapshot (a comma string such as '45,322' or '') and a position's
 * extra_group_ids (an int array off the entity) compare equal-or-not correctly
 * regardless of order, duplicates, or empty segments. This equality primitive is
 * what the drift detection rests on, kept free of any XenForo dependency so it
 * can be unit-tested without booting the framework.
 */
final class GroupIdList
{
	/**
	 * @param int[]|string|null $ids
	 * @return int[]
	 */
	public static function normalize($ids): array
	{
		if (!is_array($ids))
		{
			$ids = explode(',', (string) $ids);
		}
		// array_filter drops 0 and '' (no group has id 0), so a blank snapshot or
		// a trailing comma normalises to [] rather than [0].
		$ids = array_unique(array_filter(array_map('intval', $ids)));
		sort($ids);

		return array_values($ids);
	}
}
