<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;

class AwardGroup extends XFCP_AwardGroup
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return 'award_group';
	}
}
