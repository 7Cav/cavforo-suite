<?php

namespace Cav7\Citations\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int $template_version_id
 * @property string $field_key
 * @property int $rank_id NF/Rosters rank
 * @property string $image_hash
 * @property int $image_width
 * @property int $image_height
 */
class RankArt extends Entity
{
    use DraftPart;

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_rank_art';
        $structure->shortName = 'Cav7\Citations:RankArt';
        $structure->primaryKey = ['template_version_id', 'field_key', 'rank_id'];
        $structure->columns = [
            'template_version_id' => ['type' => self::UINT, 'required' => true],
            'field_key' => ['type' => self::STR, 'required' => true, 'maxLength' => 50],
            'rank_id' => ['type' => self::UINT, 'required' => true],
            'image_hash' => ['type' => self::STR, 'required' => true, 'maxLength' => 64],
            'image_width' => ['type' => self::UINT, 'required' => true, 'max' => 65535],
            'image_height' => ['type' => self::UINT, 'required' => true, 'max' => 65535],
        ];
        $structure->getters = [];
        $structure->relations = [];

        return $structure;
    }
}
