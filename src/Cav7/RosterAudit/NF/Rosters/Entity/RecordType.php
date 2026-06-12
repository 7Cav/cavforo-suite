<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;

class RecordType extends XFCP_RecordType
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return 'record_type';
	}
}
