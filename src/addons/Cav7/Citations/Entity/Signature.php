<?php

namespace Cav7\Citations\Entity;

use Cav7\Citations\Render\ImageFile;
use Cav7\Citations\Render\PinnedSignature;
use Cav7\Citations\Template\SignatureRules;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * COLUMNS
 * @property int|null $signature_id
 * @property int $billet_id
 * @property string $printed_lines JSON list of 1 to 4 strings
 * @property string $ink_hash
 * @property int $ink_width
 * @property int $ink_height
 * @property string $state
 * @property int $create_user_id
 * @property int $create_date
 *
 * RELATIONS
 * @property-read SigningBillet|null $Billet
 */
class Signature extends Entity
{
    public function pinned(): PinnedSignature
    {
        return new PinnedSignature(
            json_decode($this->printed_lines, true, 2, JSON_THROW_ON_ERROR),
            new ImageFile($this->ink_hash, $this->ink_width, $this->ink_height),
        );
    }

    /** @param list<string> $lines */
    public function setPrintedLines(array $lines): void
    {
        $this->printed_lines = json_encode($lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isFrozen(): bool
    {
        return $this->repository('Cav7\Citations:Signature')->isFrozen($this->signature_id);
    }

    protected function verifyPrintedLines(&$value): bool
    {
        if (SignatureRules::linesValid(json_decode($value, true))) {
            return true;
        }

        $this->error(\XF::phrase('cav7_cit_refused_bad_printed_lines'), 'printed_lines');
        return false;
    }

    protected function _preSave(): void
    {
        if ($this->isChanged('billet_id') && !$this->Billet) {
            $this->error(\XF::phrase('cav7_cit_refused_no_such_billet'));
        }
        if ($this->isUpdate() && SignatureRules::editRefused($this->isFrozen(), array_keys($this->getNewValues()))) {
            $this->error(\XF::phrase('cav7_cit_refused_signature_frozen'));
        }
    }

    protected function _preDelete(): void
    {
        if ($this->isFrozen()) {
            $this->error(\XF::phrase('cav7_cit_refused_signature_frozen'));
        }
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_signature';
        $structure->shortName = 'Cav7\Citations:Signature';
        $structure->primaryKey = 'signature_id';
        $structure->columns = [
            'signature_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            'billet_id' => ['type' => self::UINT, 'required' => true],
            'printed_lines' => ['type' => self::BINARY, 'required' => 'cav7_cit_refused_bad_printed_lines'],
            'ink_hash' => ['type' => self::STR, 'required' => true, 'maxLength' => 64],
            'ink_width' => ['type' => self::UINT, 'required' => true, 'max' => 65535],
            'ink_height' => ['type' => self::UINT, 'required' => true, 'max' => 65535],
            'state' => ['type' => self::STR, 'default' => 'active', 'allowedValues' => ['active', 'retired']],
            'create_user_id' => ['type' => self::UINT, 'required' => true],
            'create_date' => ['type' => self::UINT, 'default' => \XF::$time],
        ];
        $structure->getters = [];
        $structure->relations = [
            'Billet' => [
                'entity' => 'Cav7\Citations:SigningBillet',
                'type' => self::TO_ONE,
                'conditions' => 'billet_id',
                'primary' => true,
            ],
        ];

        return $structure;
    }
}
