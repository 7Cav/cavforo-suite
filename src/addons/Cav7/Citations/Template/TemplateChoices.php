<?php

namespace Cav7\Citations\Template;

use Cav7\Citations\Render\FieldDependencies;
use Cav7\Citations\Render\RankArtField;
use Cav7\Citations\Render\Version;

/** What a grant form may offer. A kind is `award:<id>` or `record_type:<id>`. */
final class TemplateChoices
{
    /**
     * The newest published version of each template, which the manager shows and a form starts on.
     *
     * @param list<array{template_version_id: int, template_id: int, version_number: int, state: string, kinds: list<string>}> $versions
     * @return array<int, array{template_version_id: int, template_id: int, version_number: int, state: string, kinds: list<string>}> By template id.
     */
    public static function current(array $versions): array
    {
        $current = [];
        foreach ($versions as $version) {
            $templateId = $version['template_id'];
            if ($version['state'] === VersionLifecycle::PUBLISHED
                && $version['version_number'] > ($current[$templateId]['version_number'] ?? 0)) {
                $current[$templateId] = $version;
            }
        }
        return $current;
    }

    /**
     * Every published version serving the kind. Retiring a version is what stops its offer.
     *
     * @param list<array{template_version_id: int, template_id: int, version_number: int, state: string, kinds: list<string>}> $versions
     * @return list<int> Template version ids, in the order given.
     */
    public static function offered(array $versions, string $kind): array
    {
        $offered = [];
        foreach ($versions as $version) {
            if ($version['state'] === VersionLifecycle::PUBLISHED && in_array($kind, $version['kinds'], true)) {
                $offered[] = $version['template_version_id'];
            }
        }
        return $offered;
    }

    /** A regiment grant has no members, so nothing on the version may need one. */
    public static function servesRegiment(Version $version): bool
    {
        foreach ($version->inputs as $input) {
            if ($input->scope === 'member') {
                return false;
            }
        }
        return $version->rank_scope !== 'member' && FieldDependencies::memberFields($version) === [];
    }

    /**
     * The ranks a grant-set rank may be. A rank short of art on any rank-art field is out.
     *
     * @param list<int> $rankIds
     * @param array<string, list<int>> $artRanks Rank ids with art, by field key.
     * @return list<int>
     */
    public static function pickableRanks(Version $version, array $rankIds, array $artRanks): array
    {
        foreach ($version->fields as $field) {
            if ($field instanceof RankArtField) {
                $rankIds = array_values(array_intersect($rankIds, $artRanks[$field->key] ?? []));
            }
        }
        return $rankIds;
    }
}
