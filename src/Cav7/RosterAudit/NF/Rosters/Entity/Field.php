<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;

class Field extends XFCP_Field
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return 'field';
	}
}
