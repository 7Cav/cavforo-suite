<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Entity\Grant;
use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Grant\Actor;
use Cav7\Citations\Grant\GrantForm;
use Cav7\Citations\Grant\GrantRules;
use Cav7\Citations\Render\FieldDependencies;
use Cav7\Citations\Render\InputDefinition;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\TextField;
use Cav7\Citations\Render\Version;
use Cav7\Citations\Template\TemplateChoices;
use XF\PrintableException;
use XF\Service\AbstractService;

/**
 * Turns a form's state into what its templates print. The form re-renders from its own post,
 * so choosing an award, a template or a member all come through here.
 *
 * Assumes NF/Rosters keeps a milpac's Full name in the custom field `realName`, and that a
 * user has one milpac, which Cav7/RosterPatch holds to.
 */
class GrantFormView extends AbstractService
{
    /**
     * @param array<string, mixed> $post
     * @param list<int> $regimentAwardIds
     * @return array<string, mixed>
     */
    public function forIssue(array $post, Actor $actor, array $regimentAwardIds): array
    {
        $kind = GrantForm::kind($post['kind']);
        $regiment = $kind !== null && GrantRules::isRegiment($kind[0], $kind[1], $regimentAwardIds);

        $offered = $kind === null
            ? []
            : $this->em()->getRepository('Cav7\Citations:Template')->offered($kind[0], $kind[1], $regiment);
        $version = $this->chosen($offered, $post['template_version_id']);

        // What was filled in belongs to the design it was filled in for.
        if ($version?->template_version_id !== ($post['form_version_id'] ?? 0)) {
            $post['signatures'] = [];
            $post['inputs'] = [];
            $post['rank_id'] = 0;
        }

        return [
            'mode' => 'issue',
            'kinds' => $this->kinds($actor, $regimentAwardIds),
            'kind' => $post['kind'],
            'offered' => array_map(fn (TemplateVersion $choice): array => $this->offeredChoice($choice), $offered),
            'regiment' => $regiment,
        ] + $this->fields($post, $version);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function forGrant(string $mode, array $post, Grant $grant, TemplateVersion $version): array
    {
        return [
            'mode' => $mode,
            'grant' => $grant,
            'regiment' => $grant->is_regiment,
        ] + $this->fields($post, $version);
    }

    /**
     * The name and rank snapshot a new member starts from, editable after this.
     *
     * @return array{relation_id: int, name: string, rank_id: int, inputs: array<string, string>}
     * @throws PrintableException
     */
    public function memberFrom(string $username, array $fillAll = []): array
    {
        $user = $this->em()->findOne('XF:User', ['username' => $username]);
        $milpac = $user === null ? null : $this->em()->findOne('NF\Rosters:RosterUser', ['user_id' => $user->user_id]);
        if ($milpac === null) {
            throw new PrintableException(\XF::phrase('cav7_cit_member_not_found', ['name' => $username]));
        }

        return [
            'relation_id' => $milpac->relation_id,
            'name' => (string) $milpac->custom_fields->realName,
            'rank_id' => $milpac->rank_id,
            'inputs' => $fillAll,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    public function fields(array $post, ?TemplateVersion $version): array
    {
        if ($version === null) {
            return ['version' => null, 'post' => $post];
        }

        $design = $version->renderVersion();
        $memberRank = $design->rank_scope === 'member';

        return [
            'version' => $version,
            'post' => $post,
            'slots' => $this->slots($design, $post),
            'members' => $this->members($post['members']),
            'memberInputs' => $this->inputs($design, 'member'),
            'grantInputs' => $this->inputs($design, 'grant'),
            'memberRanks' => $memberRank ? $this->ranks() : [],
            'grantRanks' => $design->rank_scope === 'grant' ? $this->grantRanks($design, $version) : [],
            'printsName' => FieldDependencies::used($design, 'name'),
            'writesText' => FieldDependencies::used($design, 'citation_text'),
            'genericText' => $this->genericText($design),
        ];
    }

    /**
     * The version the clerk picked, or, when one template offers them all, its newest.
     *
     * @param list<TemplateVersion> $offered By template, newest first.
     */
    private function chosen(array $offered, int $versionId): ?TemplateVersion
    {
        foreach ($offered as $version) {
            if ($version->template_version_id === $versionId) {
                return $version;
            }
        }
        $templates = array_unique(array_map(fn (TemplateVersion $version): int => $version->template_id, $offered));

        return count($templates) === 1 ? $offered[0] : null;
    }

    /**
     * @param list<int> $regimentAwardIds
     * @return array{awards: array<string, string>, records: array<string, string>} Titles by kind.
     */
    private function kinds(Actor $actor, array $regimentAwardIds): array
    {
        $kinds = GrantRules::listableKinds(
            $this->em()->getRepository('Cav7\Citations:Template')->offeredKinds(),
            $regimentAwardIds,
            $actor
        );

        $ids = ['award' => [], 'record_type' => []];
        foreach ($kinds as $kind) {
            [$type, $id] = GrantForm::kind($kind);
            $ids[$type][] = $id;
        }

        return [
            'awards' => $this->titles('award', 'NF\Rosters:Award', $ids['award']),
            'records' => $this->titles('record_type', 'NF\Rosters:RecordType', $ids['record_type']),
        ];
    }

    /**
     * @param list<int> $ids
     * @return array<string, string>
     */
    private function titles(string $type, string $entity, array $ids): array
    {
        $titles = [];
        foreach ($this->em()->findByIds($entity, $ids) as $id => $kind) {
            $titles["$type:$id"] = $kind->title;
        }
        asort($titles);

        return $titles;
    }

    /** @return array<string, mixed> */
    private function offeredChoice(TemplateVersion $version): array
    {
        return [
            'template_version_id' => $version->template_version_id,
            'title' => $version->Template->title,
            'version_number' => $version->version_number,
            'landscape' => $version->plate_width > $version->plate_height,
            'published' => gmdate('Y-m-d', $version->publish_date),
            'generic' => !FieldDependencies::used($version->renderVersion(), 'citation_text'),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return list<array<string, mixed>>
     */
    private function slots(Version $design, array $post): array
    {
        /** @var \Cav7\Citations\Repository\Signature $repo */
        $repo = $this->em()->getRepository('Cav7\Citations:Signature');

        $slots = [];
        foreach ($design->fields as $field) {
            if (!$field instanceof SignatureSlot) {
                continue;
            }

            $showRetired = !empty($post['show_retired'][$field->key]);
            $choices = $repo->choices($field, ($post['signatures'][$field->key] ?? 0) ?: null, $showRetired);
            $onFile = $repo->choices($field, null, true)->shown;

            $signatures = $this->em()->findByIds(
                'Cav7\Citations:Signature',
                array_column([...$choices->shown, ...$onFile], 'signature_id'),
                'Billet'
            );

            $retired = [];
            foreach ($onFile as $signature) {
                if ($signature['state'] === 'retired') {
                    $retired[] = self::printedLines($signatures[$signature['signature_id']])[0];
                }
            }

            $selected = $choices->preselected === null ? null : $signatures[$choices->preselected];

            $slots[] = [
                'key' => $field->key,
                'billet' => $this->em()->find('Cav7\Citations:SigningBillet', $field->billet_id)?->title,
                'options' => array_map(fn (array $signature): array => [
                    'id' => $signature['signature_id'],
                    'label' => self::printedLines($signatures[$signature['signature_id']])[0],
                    'retired' => $signature['state'] === 'retired',
                ], $choices->shown),
                'selected' => $choices->preselected,
                'prints_as' => $selected === null ? '' : implode(' / ', array_filter(self::printedLines($selected))),
                'several_active' => count($onFile) - count($retired) > 1,
                'show_retired' => $showRetired,
                'retired' => implode(', ', $retired),
            ];
        }
        return $slots;
    }

    /** @return list<string> */
    private static function printedLines(object $signature): array
    {
        return json_decode($signature->printed_lines, true, 2, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<int|string, mixed> $posted
     * @return array<int|string, array<string, mixed>>
     */
    public function members(array $posted): array
    {
        $milpacs = $this->em()->findByIds(
            'NF\Rosters:RosterUser',
            array_map(fn (array $member): int => (int) $member['relation_id'], $posted),
            'User'
        );

        $members = [];
        foreach ($posted as $key => $member) {
            $milpac = $milpacs[(int) $member['relation_id']] ?? null;
            $members[$key] = $member + ['key' => $key, 'milpac' => $milpac, 'username' => $milpac?->User?->username ?? $member['name']];
        }
        return $members;
    }

    /**
     * @param 'grant'|'member' $scope
     * @return list<array{key: string, label: string, required: bool}>
     */
    private function inputs(Version $design, string $scope): array
    {
        $inputs = array_filter($design->inputs, fn (InputDefinition $input): bool => $input->scope === $scope);

        return array_values(array_map(
            fn (InputDefinition $input): array => ['key' => $input->key, 'label' => $input->label, 'required' => $input->required],
            $inputs
        ));
    }

    /** @return list<array{rank_id: int|string, title: string, pay_grade: string, abbreviation: string}> */
    private function ranks(): array
    {
        return $this->em()->getRepository('Cav7\Citations:PayGrade')->list();
    }

    /** @return list<array{rank_id: int|string, title: string, pay_grade: string, abbreviation: string}> */
    private function grantRanks(Version $design, TemplateVersion $version): array
    {
        $ranks = $this->ranks();
        $pickable = TemplateChoices::pickableRanks(
            $design,
            array_map('intval', array_column($ranks, 'rank_id')),
            $version->artRanks()
        );

        return array_values(array_filter($ranks, fn (array $rank): bool => in_array((int) $rank['rank_id'], $pickable, true)));
    }

    /** A generic citation's text is the pattern of its wrapped field, which nothing fills in. */
    private function genericText(Version $design): string
    {
        foreach ($design->fields as $field) {
            if ($field instanceof TextField && $field->mode === 'wrap') {
                return $field->pattern;
            }
        }
        return '';
    }
}
