<?php

namespace Cav7\MilpacTooltip\Template;

/**
 * Template callback that renders a compact "mini-milpac" (rank insignia, rank,
 * roster status, billet and enlist date) linking to the member's NF/Rosters
 * profile, or an empty string when the member has no milpac. Used by both the
 * member hovercard (member_tooltip) and the member profile page (member_view).
 *
 * Origin: extracted from the retired Cav7/Keycloak add-on, whose
 * MemberMilpacTooltip::getMilpacUrl callback was wired into member_tooltip by a
 * hand-made template modification. Keycloak never owned that modification, so
 * removing the add-on left the callback dangling (error_invalid_class). This
 * add-on re-homes the callback and, unlike the original, owns its modifications.
 */
class MemberMilpacTooltip
{
	/**
	 * Wired from the member_tooltip / member_view template modifications as:
	 *   <xf:callback class="Cav7\MilpacTooltip\Template\MemberMilpacTooltip"
	 *       method="renderMilpac" params="[$user]" />
	 *
	 * Resolves the milpac and hands it to the templater; the markup lives in the
	 * cav7_milpac template so it stays escaped and themeable.
	 *
	 * Vendor assumption: the rank, roster and position relations the block renders
	 * are already eager-loaded with the milpac by NF/Rosters, so the block costs one
	 * extra query and no joins beyond what NF/Rosters loads anyway. Nothing breaks if
	 * that changes — the block still renders, just with a query per relation it reads.
	 *
	 * @param string $content Existing callback content (unused; required by XF).
	 * @param array  $params  [0] => \XF\Entity\User being shown.
	 *
	 * @return string Rendered HTML, or '' when the member has no milpac.
	 */
	public static function renderMilpac($content, array $params)
	{
		$user = $params[0] ?? null;
		if (!$user || !$user->user_id)
		{
			return '';
		}

		// A returning member can hold more than one roster row; prefer one on an
		// active roster so the block shows their current standing.
		$milpac = \XF::finder('NF\Rosters:RosterUser')
			->where('user_id', $user->user_id)
			->order('Roster.active', 'DESC')
			->fetchOne();

		if (!$milpac)
		{
			return '';
		}

		return \XF::app()->templater()->renderTemplate('public:cav7_milpac', [
			'milpac' => $milpac,
			'enlistDate' => self::formatEnlistDate($milpac),
			'mos' => trim((string) ($milpac->custom_fields->mos ?? '')),
			'user' => $user,
		]);
	}

	/**
	 * The enlist date is the milpac's "joinDate" roster custom field, stored as a
	 * plain Y-m-d string. It is a calendar date with no time or timezone, so we
	 * format it directly rather than running it through the timezone-aware date
	 * helper, which would shift it a day for some viewers.
	 *
	 * @param \XF\Mvc\Entity\Entity $milpac NF\Rosters:RosterUser
	 *
	 * @return string Display date (e.g. "Jun 4, 2016"), or '' if unset/unparseable.
	 */
	protected static function formatEnlistDate($milpac): string
	{
		$joinDate = $milpac->custom_fields->joinDate ?? '';
		if (empty($joinDate))
		{
			return '';
		}

		try
		{
			return (new \DateTime($joinDate))->format('M j, Y');
		}
		catch (\Exception $e)
		{
			return '';
		}
	}
}
