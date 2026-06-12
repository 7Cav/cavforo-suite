<?php

namespace Cav7\RosterAudit\Entity;

use Cav7\RosterAudit\Repository\AuditLog as AuditLogRepo;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $log_id
 * @property int $relation_id
 * @property int $user_id
 * @property string $username
 * @property string $content_type
 * @property string $content_id
 * @property string $action
 * @property int $log_date
 *
 * RELATIONS
 * @property-read \XF\Entity\User|null $User
 * @property-read \NF\Rosters\Entity\RosterUser|null $RosterUser
 */
class AuditLog extends Entity
{
	const ACTION_CREATE = 'create';
	const ACTION_UPDATE = 'update';
	const ACTION_DELETE = 'delete';

	public static function getStructure(Structure $structure): Structure
	{
		$structure->table = 'xf_cav7_roster_audit_log';
		$structure->shortName = 'Cav7\RosterAudit:AuditLog';
		$structure->primaryKey = 'log_id';
		$structure->columns = [
			'log_id' => ['type' => self::UINT, 'autoIncrement' => true],
			'relation_id' => ['type' => self::UINT, 'default' => 0],
			'user_id' => ['type' => self::UINT, 'default' => 0],
			'username' => ['type' => self::STR, 'maxLength' => 50, 'default' => ''],
			'content_type' => [
				'type' => self::STR, 'maxLength' => 50, 'required' => true,
				'allowedValues' => AuditLogRepo::CONTENT_TYPES,
			],
			// String, not int: content_id holds the audited entity's primary key, which
			// may be non-numeric (e.g. Field#field_id is a varchar).
			'content_id' => ['type' => self::STR, 'maxLength' => 50, 'required' => true],
			'action' => [
				'type' => self::STR, 'maxLength' => 25, 'required' => true,
				'allowedValues' => [self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_DELETE],
			],
			'log_date' => ['type' => self::UINT, 'default' => \XF::$time],
		];
		$structure->getters = [];
		$structure->relations = [
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true,
			],
			'RosterUser' => [
				'entity' => 'NF\Rosters:RosterUser',
				'type' => self::TO_ONE,
				'conditions' => [['relation_id', '=', '$relation_id']],
			],
		];

		return $structure;
	}
}
