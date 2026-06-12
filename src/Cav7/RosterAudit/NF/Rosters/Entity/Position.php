<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;

class Position extends XFCP_Position
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return 'position';
	}
}
