<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;
use Cav7\RosterAudit\Repository\AuditLog as AuditLogRepo;

class Roster extends XFCP_Roster
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return AuditLogRepo::TYPE_ROSTER;
	}

	protected function getAuditExcludedColumns(): array
	{
		// Derived/maintenance columns rebuilt by the system; auditing them would
		// drown the real changes.
		return ['position_cache', 'field_cache', 'last_update_date'];
	}
}
