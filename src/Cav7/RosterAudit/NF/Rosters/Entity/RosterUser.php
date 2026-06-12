<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;

class RosterUser extends XFCP_RosterUser
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return 'roster_user';
	}

	protected function getAuditRelationId(): int
	{
		return $this->relation_id;
	}

	protected function getAuditExcludedColumns(): array
	{
		// uniform_date is a timestamp stamped by the uniform image service on
		// every image upload/removal; excluding it keeps those saves from
		// producing noise entries.
		return ['uniform_date'];
	}
}
