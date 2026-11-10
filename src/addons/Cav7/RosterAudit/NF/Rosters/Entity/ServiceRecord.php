<?php

namespace Cav7\RosterAudit\NF\Rosters\Entity;

use Cav7\RosterAudit\Entity\AuditableEntity;
use Cav7\RosterAudit\Repository\AuditLog as AuditLogRepo;

class ServiceRecord extends XFCP_ServiceRecord
{
	use AuditableEntity;

	protected function getAuditContentType(): string
	{
		return AuditLogRepo::TYPE_SERVICE_RECORD;
	}

	protected function getAuditRelationId(): int
	{
		return $this->relation_id;
	}

	protected function getAuditExcludedColumns(): array
	{
		// citation_date is a timestamp stamped by the citation image service on
		// every image upload/removal, and citation_blob_id is the citation reference
		// Cav7/Citations clears on those same saves. Excluding them keeps those saves
		// from producing noise entries. Creates and deletes are still logged in full.
		return ['citation_date', 'citation_blob_id'];
	}
}
