<?php

namespace Cav7\Citations\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null $log_id
 * @property int $grant_id
 * @property string $event
 * @property int|null $grant_member_id kept after the member row is gone
 * @property int $user_id 0 for the system
 * @property string $username empty for the system
 * @property int $log_date
 * @property string $reason
 * @property int $revision_after the grant's revision once this event finished
 * @property array<string, mixed> $detail
 */
class GrantLog extends Entity
{
    public const CORRECTED = 'corrected';
    public const MEMBER_ADDED = 'member_added';
    public const MEMBER_REMOVED = 'member_removed';
    public const RETYPESET = 'retypeset';
    public const WITHDRAWN = 'withdrawn';
    public const PUC_SYNC = 'puc_sync';

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_grant_log';
        $structure->shortName = 'Cav7\Citations:GrantLog';
        $structure->primaryKey = 'log_id';
        $structure->columns = [
            'log_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            'grant_id' => ['type' => self::UINT, 'required' => true],
            'event' => [
                'type' => self::STR,
                'required' => true,
                'allowedValues' => [
                    self::CORRECTED, self::MEMBER_ADDED, self::MEMBER_REMOVED, self::RETYPESET, self::WITHDRAWN, self::PUC_SYNC,
                ],
            ],
            'grant_member_id' => ['type' => self::UINT, 'nullable' => true, 'default' => null],
            'user_id' => ['type' => self::UINT, 'required' => true],
            'username' => ['type' => self::STR, 'default' => '', 'maxLength' => 50],
            'log_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'reason' => ['type' => self::STR, 'default' => ''],
            'revision_after' => ['type' => self::UINT, 'required' => true],
            'detail' => ['type' => self::JSON_ARRAY, 'default' => []],
        ];
        $structure->getters = [];
        $structure->relations = [];

        return $structure;
    }
}
