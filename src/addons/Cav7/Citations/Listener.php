<?php

namespace Cav7\Citations;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Manager;
use XF\Mvc\Entity\Structure;

/**
 * entity_structure listeners, one method per hint. XF exports listeners sorted by class
 * and method, so two rows sharing both have no stable order in _data.
 */
class Listener
{
    public static function awardStructure(Manager $em, Structure &$structure): void
    {
        self::addCitationReference($structure);
    }

    public static function recordStructure(Manager $em, Structure &$structure): void
    {
        self::addCitationReference($structure);
    }

    // The relation rides in defaultWith because the profile page reads a blob per row
    // and its finders load nothing for us.
    private static function addCitationReference(Structure $structure): void
    {
        $structure->columns['citation_blob_id'] = ['type' => Entity::UINT, 'nullable' => true, 'default' => null];
        $structure->relations['CitationBlob'] = [
            'entity' => 'Cav7\Citations:Blob',
            'type' => Entity::TO_ONE,
            'conditions' => [['blob_id', '=', '$citation_blob_id']],
            'primary' => true,
        ];
        $structure->defaultWith[] = 'CitationBlob';
    }
}
