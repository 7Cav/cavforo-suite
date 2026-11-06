<?php

namespace Cav7\Citations\Entity;

use Cav7\Citations\Grant\MemberValues;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * A milpac on a grant. Removal deletes the row and the log keeps the snapshot, so adding
 * the member again gets a new id and token.
 *
 * COLUMNS
 * @property int|null $grant_member_id
 * @property int $grant_id
 * @property int $relation_id the milpac, no foreign key
 * @property string $url_token
 * @property string $name Full name when issued or added, editable
 * @property array<string, string> $input_values member-scoped inputs by key
 * @property int $add_user_id
 * @property int $add_date
 *
 * RELATIONS
 * @property-read Grant|null $Grant
 */
class GrantMember extends Entity
{
    use RankColumns;

    public function values(): MemberValues
    {
        return new MemberValues($this->relation_id, $this->name, $this->rank_id, $this->input_values, $this->rankSnapshot());
    }

    public function takeValues(MemberValues $values): void
    {
        $this->bulkSet(['name' => $values->name, 'input_values' => $values->inputs]);
        $this->takeRank($values->rankId, $values->rank);
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_grant_member';
        $structure->shortName = 'Cav7\Citations:GrantMember';
        $structure->primaryKey = 'grant_member_id';
        $structure->columns = [
            'grant_member_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            'grant_id' => ['type' => self::UINT, 'required' => true, 'writeOnce' => true],
            'relation_id' => ['type' => self::UINT, 'required' => true, 'writeOnce' => true],
            'url_token' => ['type' => self::STR, 'required' => true, 'maxLength' => 32, 'writeOnce' => true],
            'name' => ['type' => self::STR, 'default' => '', 'maxLength' => 255],
            'input_values' => ['type' => self::JSON_ARRAY, 'default' => []],
            'add_user_id' => ['type' => self::UINT, 'required' => true, 'writeOnce' => true],
            'add_date' => ['type' => self::UINT, 'default' => \XF::$time, 'writeOnce' => true],
        ] + self::rankColumns();
        $structure->getters = [];
        $structure->relations = [
            'Grant' => [
                'entity' => 'Cav7\Citations:Grant',
                'type' => self::TO_ONE,
                'conditions' => 'grant_id',
                'primary' => true,
            ],
        ];

        return $structure;
    }
}
