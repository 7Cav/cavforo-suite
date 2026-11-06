<?php

namespace Cav7\Citations\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * Read at issue into the rank snapshot and nowhere else, so an edit reaches later grants only.
 *
 * COLUMNS
 * @property int $rank_id NF/Rosters rank, no foreign key
 * @property string $pay_grade
 * @property string $abbreviation
 */
class PayGrade extends Entity
{
    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_pay_grade';
        $structure->shortName = 'Cav7\Citations:PayGrade';
        $structure->primaryKey = 'rank_id';
        $structure->columns = [
            'rank_id' => ['type' => self::UINT, 'required' => true],
            'pay_grade' => ['type' => self::STR, 'default' => '', 'maxLength' => 10],
            'abbreviation' => ['type' => self::STR, 'default' => '', 'maxLength' => 10],
        ];
        $structure->getters = [];
        $structure->relations = [];

        return $structure;
    }
}
