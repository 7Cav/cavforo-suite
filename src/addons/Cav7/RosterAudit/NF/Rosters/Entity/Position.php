<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;
use Cav7\RosterAudit\Repository\AuditLog as AuditLogRepo;

class Position extends XFCP_Position
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return AuditLogRepo::TYPE_POSITION;
	}

	protected function _preDelete(): void
	{
		parent::_preDelete();

		// The vendor's Position::_postDelete() raw-deletes every roster member
		// whose primary position is this one (DELETE FROM xf_nf_rosters_user),
		// bypassing entity hooks: the members' roster-membership group grants
		// leak, their award/service-record rows orphan, and nothing is audited.
		// Secondary holders survive the cascade but keep a stale id in their
		// secondary_position_ids list. Refuse the delete while ANY member still
		// references the position, primary or secondary; the admin removes the
		// assignments deliberately and each change is audited as a normal update.
		$memberCount = (int) $this->db()->fetchOne(
			'SELECT COUNT(*) FROM xf_nf_rosters_user
				WHERE position_id = ? OR FIND_IN_SET(?, secondary_position_ids)',
			[$this->position_id, $this->position_id]
		);
		if ($memberCount)
		{
			$this->error(\XF::phrase('cav7_raudit_position_has_members', ['count' => $memberCount]));
		}
	}
}
