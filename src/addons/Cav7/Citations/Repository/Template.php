<?php

namespace Cav7\Citations\Repository;

use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Render\VersionRules;
use Cav7\Citations\Template\TemplateChoices;
use Cav7\Citations\Template\VersionLifecycle;
use XF\Mvc\Entity\Repository;

class Template extends Repository
{
    /**
     * What a grant form lists for a kind, each with its Template for the title, by template
     * and newest first.
     *
     * @param 'award'|'record_type' $kindType
     * @param bool $regiment True for a regiment-grant award: only versions that need no member.
     * @return list<TemplateVersion>
     */
    public function offered(string $kindType, int $kindId, bool $regiment = false): array
    {
        $ids = TemplateChoices::offered($this->versionFacts(), "$kindType:$kindId");
        if ($ids === []) {
            return [];
        }

        $versions = $this->finder('Cav7\Citations:TemplateVersion')
            ->where('template_version_id', $ids)
            ->with('Template')
            ->order('Template.title')
            ->order('version_number', 'DESC')
            ->fetch()
            ->toArray();

        return array_values(array_filter(
            $versions,
            fn (TemplateVersion $version): bool => !$regiment || TemplateChoices::servesRegiment($version->renderVersion())
        ));
    }

    /**
     * Every kind a published version serves, which is what the grant form can list.
     *
     * @return list<string> As `award:<id>` or `record_type:<id>`.
     */
    public function offeredKinds(): array
    {
        $kinds = array_merge([], ...array_column($this->versionFacts(), 'kinds'));

        return array_values(array_unique($kinds));
    }

    /**
     * Where a version move can take a grant: the template's other published versions serving
     * its kind.
     *
     * @return array<int, TemplateVersion> By template version id.
     */
    public function movableVersions(int $templateId, int $fromVersionId, string $kind): array
    {
        $versions = $this->finder('Cav7\Citations:TemplateVersion')
            ->where('template_id', $templateId)
            ->where('state', VersionLifecycle::PUBLISHED)
            ->where('template_version_id', '<>', $fromVersionId)
            ->order('version_number', 'DESC')
            ->fetch()
            ->toArray();

        return array_filter($versions, fn (TemplateVersion $version): bool => in_array($kind, $version->kinds(), true));
    }

    /** The template's newest published version. */
    public function currentVersion(int $templateId): ?TemplateVersion
    {
        $current = TemplateChoices::current($this->versionFacts())[$templateId] ?? null;

        return $current === null
            ? null
            : $this->em->find('Cav7\Citations:TemplateVersion', $current['template_version_id']);
    }

    /** @return array<int, int> Each template's current version, by template id. */
    public function currentVersionIds(): array
    {
        return array_column(TemplateChoices::current($this->versionFacts()), 'template_version_id', 'template_id');
    }

    /** @return array<int, int> How many grants pin each version, withdrawn ones included. */
    public function versionPins(): array
    {
        return array_map('intval', $this->db()->fetchPairs(
            'SELECT template_version_id, COUNT(*) FROM xf_cav7_citation_grant GROUP BY template_version_id'
        ));
    }

    /**
     * Every NF/Rosters award and record type the generator covers, whether or not a template
     * serves it. Assumes the vendor keeps their titles in `title`, as NF\Rosters:Award and :RecordType.
     *
     * @return array{awards: array<string, string>, records: array<string, string>} Titles by kind.
     */
    public function kindOptions(): array
    {
        $titles = function (string $entity, string $type): array {
            $titles = [];
            foreach ($this->finder($entity)->order('title')->fetch() as $id => $kind) {
                if (VersionRules::generated("$type:$id")) {
                    $titles["$type:$id"] = $kind->title;
                }
            }
            return $titles;
        };

        return [
            'awards' => $titles('NF\Rosters:Award', 'award'),
            'records' => $titles('NF\Rosters:RecordType', 'record_type'),
        ];
    }

    /** @return list<array{template_version_id: int, template_id: int, version_number: int, state: string, kinds: list<string>}> */
    private function versionFacts(): array
    {
        $rows = $this->db()->fetchAll(
            'SELECT v.template_version_id, v.template_id, v.version_number, v.state, k.kind_type, k.kind_id
            FROM xf_cav7_citation_template_version AS v
            LEFT JOIN xf_cav7_citation_version_kind AS k ON k.template_version_id = v.template_version_id
            WHERE v.state = ?',
            [VersionLifecycle::PUBLISHED]
        );

        $versions = [];
        foreach ($rows as $row) {
            $id = (int) $row['template_version_id'];
            $versions[$id] ??= [
                'template_version_id' => $id,
                'template_id' => (int) $row['template_id'],
                'version_number' => (int) $row['version_number'],
                'state' => $row['state'],
                'kinds' => [],
            ];
            if ($row['kind_type'] !== null) {
                $versions[$id]['kinds'][] = "{$row['kind_type']}:{$row['kind_id']}";
            }
        }
        return array_values($versions);
    }
}
