<?php

namespace Cav7\ApiKeyManager\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $key_id
 * @property int $scope_id
 *
 * RELATIONS
 * @property-read \Cav7\ApiKeyManager\Entity\ApiKey|null         $ApiKey
 * @property-read \Cav7\ApiKeyManager\Entity\ApiKeyScopeDef|null $ScopeDef
 */
class ApiKeyScope extends Entity
{
    public static function getStructure(Structure $structure): Structure
    {
        $structure->table      = 'xf_cav7_api_key_scope';
        $structure->shortName  = 'Cav7\ApiKeyManager:ApiKeyScope';
        $structure->primaryKey = ['key_id', 'scope_id'];
        $structure->columns = [
            'key_id'   => ['type' => self::UINT, 'required' => true],
            'scope_id' => ['type' => self::UINT, 'required' => true],
        ];
        $structure->relations = [
            'ApiKey' => [
                'entity'     => 'Cav7\ApiKeyManager:ApiKey',
                'type'       => self::TO_ONE,
                'conditions' => 'key_id',
                'primary'    => true,
            ],
            'ScopeDef' => [
                'entity'     => 'Cav7\ApiKeyManager:ApiKeyScopeDef',
                'type'       => self::TO_ONE,
                'conditions' => 'scope_id',
                'primary'    => true,
            ],
        ];

        return $structure;
    }
}
