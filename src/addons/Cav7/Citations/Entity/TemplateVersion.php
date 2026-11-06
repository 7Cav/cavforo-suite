<?php

namespace Cav7\Citations\Entity;

use Cav7\Citations\Render\FieldDefinitions;
use Cav7\Citations\Render\FontCatalog;
use Cav7\Citations\Render\ImageFile;
use Cav7\Citations\Render\Plate;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Render\Version;
use Cav7\Citations\Template\FileStore;
use Cav7\Citations\Template\VersionLifecycle;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * A draft takes any well-formed definitions. Whether they make a printable design is
 * publish's question, so fonts, boxes and patterns aren't checked here.
 *
 * COLUMNS
 * @property int|null $template_version_id
 * @property int $template_id
 * @property int $version_number
 * @property string $state
 * @property string $plate_hash SHA-256 of the plate file, hex
 * @property string|null $plate_format
 * @property int $plate_width
 * @property int $plate_height
 * @property string $rank_scope
 * @property string $field_definitions JSON list, in draw order
 * @property string $input_definitions JSON list
 * @property string $font_hashes JSON font key => SHA-256, empty until publish
 * @property int $create_user_id
 * @property int $create_date
 * @property int $publish_user_id
 * @property int $publish_date
 * @property int $retire_date
 *
 * RELATIONS
 * @property-read Template|null $Template
 * @property-read AbstractCollection<VersionKind> $Kinds
 * @property-read AbstractCollection<RankArt> $RankArt
 */
class TemplateVersion extends Entity
{
    public function renderVersion(): Version
    {
        return Version::fromJson($this->field_definitions, $this->input_definitions, $this->rank_scope);
    }

    /**
     * A draft reads the shipped fonts. A published version reads what it pinned, so a
     * release can drop a font without moving it.
     *
     * @return array<string, string> Font key => path.
     */
    public function fontPaths(FileStore $files): array
    {
        if (VersionLifecycle::isDraft($this->state)) {
            return FontCatalog::paths();
        }

        return array_map(
            fn (string $hash): string => $files->path($hash, 'font'),
            json_decode($this->font_hashes, true, 2, JSON_THROW_ON_ERROR)
        );
    }

    /** @throws RenderFailed A draft with no plate yet. */
    public function plate(FileStore $files): Plate
    {
        if ($this->plate_format === null) {
            throw new RenderFailed("Template version {$this->template_version_id} has no plate");
        }

        return new Plate($files->path($this->plate_hash, $this->plate_format), $this->plate_format);
    }

    /** @return array<string, ImageFile> This rank's art, by field key. */
    public function rankArtFor(int $rankId): array
    {
        $art = [];
        foreach ($this->RankArt as $row) {
            if ($row->rank_id === $rankId) {
                $art[$row->field_key] = new ImageFile($row->image_hash, $row->image_width, $row->image_height);
            }
        }
        return $art;
    }

    protected function verifyFieldDefinitions(&$value): bool
    {
        return $this->definitionsDecode('field_definitions', fn () => FieldDefinitions::fields($value));
    }

    protected function verifyInputDefinitions(&$value): bool
    {
        return $this->definitionsDecode('input_definitions', fn () => FieldDefinitions::inputs($value));
    }

    private function definitionsDecode(string $column, \Closure $decode): bool
    {
        try {
            $decode();
            return true;
        } catch (\InvalidArgumentException | \JsonException $e) {
            $this->error(\XF::phrase('cav7_cit_definitions_invalid', ['detail' => $e->getMessage()]), $column);
            return false;
        }
    }

    protected function _preSave(): void
    {
        $prior = $this->isInsert() ? null : $this->getExistingValue('state');
        $refusal = VersionLifecycle::refusal($prior, $this->state, array_keys($this->getNewValues()));
        if ($refusal !== null) {
            $this->error(\XF::phrase("cav7_cit_refused_$refusal"));
        }

        if ($this->isInsert() && $this->templateHasDraft()) {
            $this->error(\XF::phrase('cav7_cit_refused_one_draft'));
        }
    }

    private function templateHasDraft(): bool
    {
        return $this->finder('Cav7\Citations:TemplateVersion')
            ->where(['template_id' => $this->template_id, 'state' => VersionLifecycle::DRAFT])
            ->total() > 0;
    }

    protected function _preDelete(): void
    {
        if (!VersionLifecycle::isDraft($this->getExistingValue('state'))) {
            $this->error(\XF::phrase('cav7_cit_refused_version_undeletable'));
        }
    }

    protected function _postDelete(): void
    {
        $where = 'template_version_id = ' . $this->db()->quote($this->template_version_id);
        $this->db()->delete('xf_cav7_citation_version_kind', $where);
        $this->db()->delete('xf_cav7_citation_rank_art', $where);
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_template_version';
        $structure->shortName = 'Cav7\Citations:TemplateVersion';
        $structure->primaryKey = 'template_version_id';
        $structure->columns = [
            'template_version_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            'template_id' => ['type' => self::UINT, 'required' => true],
            'version_number' => ['type' => self::UINT, 'required' => true, 'max' => 65535],
            'state' => [
                'type' => self::STR,
                'default' => VersionLifecycle::DRAFT,
                'allowedValues' => [VersionLifecycle::DRAFT, VersionLifecycle::PUBLISHED, VersionLifecycle::RETIRED],
            ],
            'plate_hash' => ['type' => self::STR, 'default' => '', 'maxLength' => 64],
            'plate_format' => ['type' => self::STR, 'nullable' => true, 'default' => null, 'allowedValues' => ['png', 'jpeg']],
            'plate_width' => ['type' => self::UINT, 'default' => 0, 'max' => 65535],
            'plate_height' => ['type' => self::UINT, 'default' => 0, 'max' => 65535],
            'rank_scope' => ['type' => self::STR, 'default' => 'none', 'allowedValues' => ['member', 'grant', 'none']],
            'field_definitions' => ['type' => self::BINARY, 'default' => '[]'],
            'input_definitions' => ['type' => self::BINARY, 'default' => '[]'],
            'font_hashes' => ['type' => self::BINARY, 'default' => '[]'],
            'create_user_id' => ['type' => self::UINT, 'required' => true],
            'create_date' => ['type' => self::UINT, 'default' => \XF::$time],
            'publish_user_id' => ['type' => self::UINT, 'default' => 0],
            'publish_date' => ['type' => self::UINT, 'default' => 0],
            'retire_date' => ['type' => self::UINT, 'default' => 0],
        ];
        $structure->getters = [];
        $structure->relations = [
            'Template' => [
                'entity' => 'Cav7\Citations:Template',
                'type' => self::TO_ONE,
                'conditions' => 'template_id',
                'primary' => true,
            ],
            'Kinds' => [
                'entity' => 'Cav7\Citations:VersionKind',
                'type' => self::TO_MANY,
                'conditions' => 'template_version_id',
            ],
            'RankArt' => [
                'entity' => 'Cav7\Citations:RankArt',
                'type' => self::TO_MANY,
                'conditions' => 'template_version_id',
            ],
        ];

        return $structure;
    }
}
