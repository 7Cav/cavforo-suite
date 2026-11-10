<?php

namespace Cav7\Citations\Repository;

use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Template\TemplateChoices;
use Cav7\Citations\Template\VersionLifecycle;
use XF\Mvc\Entity\Repository;

class Template extends Repository
{
    /**
     * What a grant form lists for a kind, each with its Template for the title.
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
            ->fetch()
            ->toArray();

        return array_values(array_filter(
            $versions,
            fn (TemplateVersion $version): bool => !$regiment || TemplateChoices::servesRegiment($version->renderVersion())
        ));
    }

    /** The version a submit pins, and what a form must still match when it comes back. */
    public function currentVersion(int $templateId): ?TemplateVersion
    {
        $current = TemplateChoices::current($this->versionFacts())[$templateId] ?? null;

        return $current === null
            ? null
            : $this->em->find('Cav7\Citations:TemplateVersion', $current['template_version_id']);
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
