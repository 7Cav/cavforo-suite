<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;

class PositionGroup extends XFCP_PositionGroup
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return 'position_group';
	}
}
