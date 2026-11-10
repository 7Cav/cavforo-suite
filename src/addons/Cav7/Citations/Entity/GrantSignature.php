<?php

namespace Cav7\Citations\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * A slot's pinned signature. The grant copies nothing, and this row is what freezes the signature.
 *
 * COLUMNS
 * @property int $grant_id
 * @property string $field_key the slot on the pinned version
 * @property int $signature_id
 */
class GrantSignature extends Entity
{
    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_grant_signature';
        $structure->shortName = 'Cav7\Citations:GrantSignature';
        $structure->primaryKey = ['grant_id', 'field_key'];
        $structure->columns = [
            'grant_id' => ['type' => self::UINT, 'required' => true],
            'field_key' => ['type' => self::STR, 'required' => true, 'maxLength' => 50],
            'signature_id' => ['type' => self::UINT, 'required' => true],
        ];
        $structure->getters = [];
        $structure->relations = [];

        return $structure;
    }
}
