<?php

namespace Cav7\Citations\Entity;

use Cav7\Citations\Template\VersionLifecycle;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * An identity and a title. Everything that freezes sits on the version.
 *
 * COLUMNS
 * @property int|null $template_id
 * @property string $title what the grant form lists, never printed
 * @property int $create_user_id
 * @property int $create_date
 *
 * RELATIONS
 * @property-read AbstractCollection<TemplateVersion> $Versions oldest first
 */
class Template extends Entity
{
    protected function _preDelete(): void
    {
        foreach ($this->Versions as $version) {
            if (!VersionLifecycle::isDraft($version->state)) {
                $this->error(\XF::phrase('cav7_cit_refused_template_undeletable'));
                return;
            }
        }
    }

    protected function _postDelete(): void
    {
        foreach ($this->Versions as $version) {
            $version->delete(true, false);
        }
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_template';
        $structure->shortName = 'Cav7\Citations:Template';
        $structure->primaryKey = 'template_id';
        $structure->columns = [
            'template_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            'title' => ['type' => self::STR, 'required' => 'please_enter_valid_title', 'maxLength' => 150],
            'create_user_id' => ['type' => self::UINT, 'required' => true],
            'create_date' => ['type' => self::UINT, 'default' => \XF::$time],
        ];
        $structure->getters = [];
        $structure->relations = [
            'Versions' => [
                'entity' => 'Cav7\Citations:TemplateVersion',
                'type' => self::TO_MANY,
                'conditions' => 'template_id',
                'order' => 'version_number',
            ],
        ];

        return $structure;
    }
}
