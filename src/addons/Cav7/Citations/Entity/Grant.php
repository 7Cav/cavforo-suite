<?php

namespace Cav7\Citations\Entity;

use Cav7\Citations\Grant\GrantRules;
use Cav7\Citations\Grant\GrantValues;
use Cav7\Citations\Grant\RenderFilename;
use XF\Mvc\Entity\AbstractCollection;
use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

/**
 * An issued grant. A row exists only once issued, and it's never deleted. What makes it
 * this grant, its kind and whether it's a regiment grant, is written once.
 *
 * COLUMNS
 * @property int|null $grant_id
 * @property bool $is_regiment
 * @property string $kind_type
 * @property int $kind_id
 * @property string $kind_title copied at issue, so a rename or delete never reaches it
 * @property int $template_version_id
 * @property int $grant_date midnight UTC of the grant's day
 * @property string|null $citation_text null on a generic citation
 * @property array<string, string> $input_values grant-scoped inputs by key
 * @property int $revision
 * @property string|null $url_token regiment grants only
 * @property int $issue_user_id
 * @property string $issue_username
 * @property int $issue_date
 * @property int $withdrawn_date
 *
 * RELATIONS
 * @property-read TemplateVersion|null $Version
 * @property-read AbstractCollection<GrantMember> $Members by grant member id
 * @property-read AbstractCollection<GrantSignature> $PinnedSignatures
 * @property-read AbstractCollection<GrantLog> $Log oldest first
 */
class Grant extends Entity
{
    use RankColumns;

    public function values(): GrantValues
    {
        $members = [];
        foreach ($this->Members as $id => $member) {
            $members[$id] = $member->values();
        }

        $signatures = [];
        foreach ($this->PinnedSignatures as $pin) {
            $signatures[$pin->field_key] = $pin->signature_id;
        }

        return new GrantValues(
            gmdate('Y-m-d', $this->grant_date),
            $this->citation_text,
            $signatures,
            $this->input_values,
            $this->rank_id,
            $members,
            $this->rankSnapshot(),
        );
    }

    /** The columns a correction can reach. Members and signatures have rows of their own. */
    public function takeValues(GrantValues $values): void
    {
        $this->bulkSet([
            'grant_date' => GrantRules::day($values->date),
            'citation_text' => $values->citationText,
            'input_values' => $values->inputs,
        ]);
        $this->takeRank($values->rankId, $values->rank);
    }

    /** A regiment grant's render. Member grants render per grant member. */
    public function renderUrl(bool $canonical = false): string
    {
        return $this->app()->router('public')->buildLink(($canonical ? 'canonical:' : '') . 'citations/regiment', [
            'grant_id' => $this->grant_id,
            'url_token' => $this->url_token,
            'revision' => $this->revision,
            'filename' => $this->renderFilename(),
        ]);
    }

    public function renderFilename(): string
    {
        $router = $this->app()->router('public');

        return RenderFilename::forRegiment(
            $this->kind_title,
            $this->grant_date,
            $this->grant_id,
            fn (string $value): string => $router->prepareStringForUrl($value, true),
        );
    }

    protected function _preDelete(): void
    {
        $this->error(\XF::phrase('cav7_cit_refused_grant_undeletable'));
    }

    public static function getStructure(Structure $structure): Structure
    {
        $structure->table = 'xf_cav7_citation_grant';
        $structure->shortName = 'Cav7\Citations:Grant';
        $structure->primaryKey = 'grant_id';
        $structure->columns = [
            'grant_id' => ['type' => self::UINT, 'autoIncrement' => true, 'nullable' => true],
            'is_regiment' => ['type' => self::BOOL, 'default' => false, 'writeOnce' => true],
            'kind_type' => ['type' => self::STR, 'required' => true, 'allowedValues' => ['award', 'record_type'], 'writeOnce' => true],
            'kind_id' => ['type' => self::UINT, 'required' => true, 'writeOnce' => true],
            'kind_title' => ['type' => self::STR, 'required' => true, 'maxLength' => 150, 'writeOnce' => true],
            'template_version_id' => ['type' => self::UINT, 'required' => true],
            'grant_date' => ['type' => self::UINT, 'required' => true],
            'citation_text' => ['type' => self::STR, 'nullable' => true, 'default' => null],
            'input_values' => ['type' => self::JSON_ARRAY, 'default' => []],
            'revision' => ['type' => self::UINT, 'default' => 1],
            'url_token' => ['type' => self::STR, 'nullable' => true, 'default' => null, 'maxLength' => 32, 'writeOnce' => true],
            'issue_user_id' => ['type' => self::UINT, 'required' => true, 'writeOnce' => true],
            'issue_username' => ['type' => self::STR, 'required' => true, 'maxLength' => 50, 'writeOnce' => true],
            'issue_date' => ['type' => self::UINT, 'default' => \XF::$time, 'writeOnce' => true],
            'withdrawn_date' => ['type' => self::UINT, 'default' => 0],
        ] + self::rankColumns();
        $structure->getters = [];
        $structure->relations = [
            'Version' => [
                'entity' => 'Cav7\Citations:TemplateVersion',
                'type' => self::TO_ONE,
                'conditions' => 'template_version_id',
                'primary' => true,
            ],
            'Members' => [
                'entity' => 'Cav7\Citations:GrantMember',
                'type' => self::TO_MANY,
                'conditions' => 'grant_id',
                'key' => 'grant_member_id',
                'order' => 'grant_member_id',
            ],
            'PinnedSignatures' => [
                'entity' => 'Cav7\Citations:GrantSignature',
                'type' => self::TO_MANY,
                'conditions' => 'grant_id',
            ],
            'Log' => [
                'entity' => 'Cav7\Citations:GrantLog',
                'type' => self::TO_MANY,
                'conditions' => 'grant_id',
                'order' => 'log_id',
            ],
        ];

        return $structure;
    }
}
