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

		// As of NF/Rosters 2.1.5 deleting a position is destructive: the vendor's
		// Position::deletePrimaryHolders() calls ->delete() on every member whose
		// primary position is this one, removing them from the roster along with
		// their awards, service records, field values and uniform; secondary
		// holders have the id and its group grant scrubbed. Those deletes now run
		// through entity hooks, so they are audited, but the loss is real and
		// irreversible (the vendor's delete dialog warns of this, though only for
		// the primary holders). Refuse the
		// delete while ANY member still references the position, primary or
		// secondary, so an admin can't wipe members and their record history by
		// removing a position; they reassign deliberately first, and each
		// reassignment is audited as a normal update.
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
