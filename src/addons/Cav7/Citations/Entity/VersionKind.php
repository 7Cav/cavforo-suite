<?php

namespace Cav7\Citations\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * One row kind a version serves. It sits on the version so that changing what the grant
 * form offers needs a publish.
 *
 * COLUMNS
 * @property int $template_version_id
 * @property string $kind_type
 * @property int $kind_id
 */
class VersionKind extends Entity
{
    use DraftPart;

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_version_kind';
        $structure->shortName = 'Cav7\Citations:VersionKind';
        $structure->primaryKey = ['template_version_id', 'kind_type', 'kind_id'];
        $structure->columns = [
            'template_version_id' => ['type' => self::UINT, 'required' => true],
            'kind_type' => ['type' => self::STR, 'required' => true, 'allowedValues' => ['award', 'record_type']],
            'kind_id' => ['type' => self::UINT, 'required' => true],
        ];
        $structure->getters = [];
        $structure->relations = [];

        return $structure;
    }
}
