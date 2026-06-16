<?php

namespace Cav7\ApiKeyManager\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null $key_id
 * @property int $user_id
 * @property string $key_hash
 * @property string $key_prefix
 * @property bool $is_active
 * @property int $created_date
 * @property int $last_used_date
 *
 * GETTERS
 * @property-read string $key_display
 *
 * RELATIONS
 * @property-read \XF\Entity\User|null $User
 * @property-read \XF\Mvc\Entity\AbstractCollection|\Cav7\ApiKeyManager\Entity\ApiKeyScope[] $Scopes
 */
class ApiKey extends Entity
{
    public function getKeyDisplay(): string
    {
        return $this->key_prefix . '...';
    }

    protected function _postDelete(): void
    {
        $this->db()->delete(
            'xf_cav7_api_key_scope',
            'key_id = ?',
            $this->key_id
        );
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_api_key';
        $structure->shortName = 'Cav7\ApiKeyManager:ApiKey';
        $structure->primaryKey = 'key_id';
        $structure->columns = [
            'key_id'         => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            'user_id'        => ['type' => self::UINT, 'required' => true],
            'key_hash'       => ['type' => self::BINARY, 'required' => true, 'maxlength' => 32],
            'key_prefix'     => ['type' => self::STR, 'required' => true, 'maxlength' => 12],
            'is_active'      => ['type' => self::BOOL, 'default' => true],
            'created_date'   => ['type' => self::UINT, 'default' => \XF::$time],
            'last_used_date' => ['type' => self::UINT, 'default' => 0],
        ];
        $structure->getters = [
            'key_display' => true,
        ];
        $structure->relations = [
            'User' => [
                'entity'     => 'XF:User',
                'type'       => self::TO_ONE,
                'conditions' => 'user_id',
                'primary'    => true,
            ],
            'Scopes' => [
                'entity'     => 'Cav7\ApiKeyManager:ApiKeyScope',
                'type'       => self::TO_MANY,
                'conditions' => 'key_id',
                'key'        => 'scope_id',
                'with'       => 'ScopeDef',
                'order'      => 'scope_id',
            ],
        ];

        return $structure;
    }
}
