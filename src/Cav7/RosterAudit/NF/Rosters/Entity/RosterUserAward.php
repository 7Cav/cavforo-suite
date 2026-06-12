<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;

class RosterUserAward extends XFCP_RosterUserAward
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return 'user_award';
	}

	protected function getAuditRelationId(): int
	{
		return $this->relation_id;
	}

	protected function getAuditExcludedColumns(): array
	{
		// citation_date is a timestamp stamped by the citation image service on
		// every image upload/removal; excluding it keeps those saves from
		// producing noise entries. Creates and deletes are still logged in full.
		return ['citation_date'];
	}
}
