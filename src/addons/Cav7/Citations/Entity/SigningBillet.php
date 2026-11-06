<?php

namespace Cav7\Citations\Entity;

use Cav7\Citations\Template\SignatureRules;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null $billet_id
 * @property string $title a matching key, never printed
 * @property int $display_order
 */
class SigningBillet extends Entity
{
    protected function _preDelete(): void
    {
        $signatures = $this->finder('Cav7\Citations:Signature')->where('billet_id', $this->billet_id)->total();
        $definitions = $this->db()->fetchAllColumn('SELECT field_definitions FROM xf_cav7_citation_template_version');

        if ($signatures > 0 || SignatureRules::billetInSlots($this->billet_id, $definitions)) {
            $this->error(\XF::phrase('cav7_cit_refused_billet_in_use'));
        }
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_signing_billet';
        $structure->shortName = 'Cav7\Citations:SigningBillet';
        $structure->primaryKey = 'billet_id';
        $structure->columns = [
            'billet_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            'title' => ['type' => self::STR, 'required' => 'please_enter_valid_title', 'maxLength' => 100],
            'display_order' => ['type' => self::UINT, 'default' => 0],
        ];
        $structure->getters = [];
        $structure->relations = [];

        return $structure;
    }
}
