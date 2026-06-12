<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;
use Cav7\RosterAudit\Repository\AuditLog as AuditLogRepo;

class Rank extends XFCP_Rank
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return AuditLogRepo::TYPE_RANK;
	}

	protected function getAuditExcludedColumns(): array
	{
		// rank_image is a timestamp stamped by the image service on every image
		// upload/removal; excluding it keeps those saves from producing noise
		// entries. Creates and deletes are still logged in full.
		return ['rank_image'];
	}
}
