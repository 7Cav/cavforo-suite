<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;
use Cav7\RosterAudit\Repository\AuditLog as AuditLogRepo;

class RecordType extends XFCP_RecordType
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return AuditLogRepo::TYPE_RECORD_TYPE;
	}
}
