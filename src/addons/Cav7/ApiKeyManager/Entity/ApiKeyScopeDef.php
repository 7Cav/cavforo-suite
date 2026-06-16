<?php

namespace Cav7\ApiKeyManager\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null $scope_id
 * @property string $scope_name
 * @property string $title
 * @property string $description
 * @property string $user_group_ids
 * @property bool $is_active
 * @property int $display_order
 *
 * GETTERS
 * @property-read bool $is_gated
 */
class ApiKeyScopeDef extends Entity
{
    public function getIsGated(): bool
    {
        return $this->user_group_ids !== '';
    }

    protected function _preSave(): void
    {
        $ids = array_filter(
            array_map('intval', explode(',', (string) $this->user_group_ids)),
            fn ($id) => $id > 0
        );
        $normalized = implode(',', array_values(array_unique($ids)));
        if ($normalized !== $this->user_group_ids)
        {
            $this->set('user_group_ids', $normalized);
        }

        if (!preg_match('/^[a-z][a-z0-9_:]*$/', $this->scope_name))
        {
            $this->error(\XF::phrase('cav7_api_scope_name_invalid'), 'scope_name');
        }
    }

    protected function _postDelete(): void
    {
        $this->db()->delete(
            'xf_cav7_api_key_scope',
            'scope_id = ?',
            $this->scope_id
        );
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table      = 'xf_cav7_api_key_scope_def';
        $structure->shortName  = 'Cav7\ApiKeyManager:ApiKeyScopeDef';
        $structure->primaryKey = 'scope_id';
        $structure->columns = [
            'scope_id'        => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            'scope_name'      => ['type' => self::STR, 'required' => true, 'maxLength' => 50, 'unique' => true],
            'title'           => ['type' => self::STR, 'required' => true, 'maxLength' => 100],
            'description'     => ['type' => self::STR, 'default' => ''],
            'user_group_ids'  => ['type' => self::STR, 'default' => '', 'maxLength' => 255],
            'is_active'       => ['type' => self::BOOL, 'default' => true],
            'display_order'   => ['type' => self::UINT, 'default' => 0],
        ];
        $structure->getters = [
            'is_gated' => true,
        ];

        return $structure;
    }
}
