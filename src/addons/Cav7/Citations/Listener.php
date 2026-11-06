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

    // The relations ride in defaultWith because the profile page reads an image URL per row
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
        $structure->columns['citation_grant_member_id'] = ['type' => Entity::UINT, 'nullable' => true, 'default' => null];
        $structure->columns['citation_regiment_grant_id'] = ['type' => Entity::UINT, 'nullable' => true, 'default' => null];
        $structure->relations['CitationGrantMember'] = [
            'entity' => 'Cav7\Citations:GrantMember',
            'type' => Entity::TO_ONE,
            'conditions' => [['grant_member_id', '=', '$citation_grant_member_id']],
            'primary' => true,
        ];
        $structure->relations['CitationRegimentGrant'] = [
            'entity' => 'Cav7\Citations:Grant',
            'type' => Entity::TO_ONE,
            'conditions' => [['grant_id', '=', '$citation_regiment_grant_id']],
            'primary' => true,
        ];
        array_push($structure->defaultWith, 'CitationBlob', 'CitationGrantMember.Grant', 'CitationRegimentGrant');
    }
}
