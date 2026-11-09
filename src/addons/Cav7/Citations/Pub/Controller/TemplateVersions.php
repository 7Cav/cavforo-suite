<?php

namespace Cav7\Citations\Pub\Controller;

use Cav7\Citations\Entity\RankArt;
use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Grant\GrantForm;
use Cav7\Citations\Render\FontCatalog;
use Cav7\Citations\Render\PatternParser;
use Cav7\Citations\Render\RankArtField;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Render\VersionRules;
use Cav7\Citations\Repository\Template as TemplateRepository;
use Cav7\Citations\Service\Drafts;
use Cav7\Citations\Service\Grants as GrantService;
use Cav7\Citations\Service\PlatePreview;
use Cav7\Citations\Service\Publication;
use Cav7\Citations\Template\FieldForm;
use Cav7\Citations\Template\ImageUpload;
use Cav7\Citations\Template\ManagerAccess;
use Cav7\Citations\Template\PlateFit;
use Cav7\Citations\Template\VersionLifecycle;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\PrintableException;

/** A template version: draft editing, its preview, publish and retire, and moving citations off it. */
class TemplateVersions extends AbstractManager
{
    protected const PAGE = ManagerAccess::VERSIONS;

    public function actionIndex(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);
        $actor = $this->actor();
        $titles = array_merge(...array_values($this->repo()->kindOptions()));
        $draft = VersionLifecycle::isDraft($version->state);

        $plateWarns = $version->plate_format !== null
            && (new ImageUpload($version->plate_format, $version->plate_width, $version->plate_height))->plateWidthWarns();

        return $this->view('Cav7\Citations:Version\View', 'cav7_cit_version_view', [
            'version' => $version,
            'serves' => array_values(array_intersect_key($titles, array_flip($version->kinds()))),
            'inputs' => $version->inputRows(),
            'fields' => $this->fieldRows($version),
            'plateWarns' => $plateWarns,
            'panel' => $this->panel($version),
            'can' => [
                'edit' => $draft && $actor->canEditDrafts(),
                'publish' => $draft && $actor->canPublish(),
            ],
        ]);
    }

    public function actionPlate(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);

        return $version->plate_format === null ? $this->notFound() : $this->image($version->plate_hash, $version->plate_format);
    }

    public function actionArt(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);
        /** @var RankArt|null $art */
        $art = $this->em()->find('Cav7\Citations:RankArt', [
            'template_version_id' => $version->template_version_id,
            'field_key' => $this->filter('field', 'str'),
            'rank_id' => $this->filter('rank', 'uint'),
        ]);

        return $art === null ? $this->notFound() : $this->image($art->image_hash, 'png');
    }

    public function actionUploadPlate(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);

        if (!$this->isPost()) {
            return $this->view('Cav7\Citations:Version\Plate', 'cav7_cit_version_plate', ['version' => $version]);
        }

        $path = $this->uploaded('plate') ?? throw new PrintableException(\XF::phrase('cav7_cit_refused_no_file'));
        $warning = $this->drafts()->setPlate($version, $path);

        return $this->redirect($this->versionLink($version), $warning ?? \XF::phrase('your_changes_have_been_saved'));
    }

    public function actionSettings(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);

        if (!$this->isPost()) {
            return $this->view('Cav7\Citations:Version\Settings', 'cav7_cit_version_settings', [
                'version' => $version,
                'kindOptions' => $this->repo()->kindOptions(),
                'kinds' => $version->kinds(),
                'inputs' => $version->inputRows(),
            ]);
        }

        $posted = $this->filter(['rank_scope' => 'str', 'kinds' => 'array-str', 'inputs' => 'array']);
        if (array_filter($posted['kinds'], fn (string $kind): bool => !VersionRules::generated($kind)) !== []) {
            throw new PrintableException(\XF::phrase('cav7_cit_refused_kind_outside_generator'));
        }
        $known = array_merge(...array_values($this->repo()->kindOptions()));
        $kinds = array_map(GrantForm::kind(...), array_keys(array_intersect_key($known, array_flip($posted['kinds']))));

        $this->drafts()->saveSettings(
            $version,
            $posted['rank_scope'],
            FieldForm::inputs(array_filter($posted['inputs'], 'is_array')),
            $kinds
        );

        return $this->redirect($this->versionLink($version), \XF::phrase('your_changes_have_been_saved'));
    }

    /** Opens read-only for anyone who can't save it, the only place a frozen field's attributes show. */
    public function actionField(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);
        $key = $this->filter('key', 'str');
        $field = FieldForm::blank($version->plate_width);

        if ($key !== '') {
            $saved = array_column($version->fieldDefinitions(), null, 'key')[$key] ?? null;
            if ($saved === null) {
                return $this->notFound();
            }
            $field = $saved + $field;
        }

        $catalog = array_map(fn (array $font): string => $font['label'], FontCatalog::FONTS);
        $placeholders = array_map(
            fn (string $name): string => '{' . $name . '}',
            [...PatternParser::NAMES, ...array_map(fn (array $input): string => "input:{$input['key']}", $version->inputRows())]
        );

        return $this->view('Cav7\Citations:Version\Field', 'cav7_cit_version_field', [
            'version' => $version,
            'field' => $field,
            'originalKey' => $key,
            'fonts' => $field['font'] === '' || isset($catalog[$field['font']]) ? $catalog : [$field['font'] => $field['font']] + $catalog,
            'billets' => $this->finder('Cav7\Citations:SigningBillet')->order('display_order')->fetch(),
            'placeholders' => $placeholders,
            'panel' => $this->panel($version),
            'canEdit' => VersionLifecycle::isDraft($version->state) && $this->actor()->canEditDrafts(),
        ]);
    }

    public function actionSaveField(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();
        $version = $this->version($params);

        $this->drafts()->saveField(
            $version,
            $this->filter('original_key', 'str') ?: null,
            FieldForm::definition($this->filter('field', 'array-str'))
        );

        return $this->redirect($this->versionLink($version), \XF::phrase('your_changes_have_been_saved'));
    }

    public function actionRemoveField(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);
        $key = $this->filter('key', 'str');

        if ($this->isPost()) {
            $this->drafts()->removeField($version, $key);

            return $this->redirect($this->versionLink($version));
        }

        return $this->confirm(
            \XF::phrase('confirm_action'),
            \XF::phrase('cav7_cit_delete_the_field_x', ['key' => $key]),
            $this->buildLink('rosters/citation-versions/remove-field', $version, ['key' => $key]),
            \XF::phrase('delete')
        );
    }

    public function actionMoveField(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();
        $version = $this->version($params);
        [$key, $direction] = explode(':', $this->filter('move', 'str') . ':');

        $this->drafts()->moveField($version, $key, $direction === 'up' ? -1 : 1);

        return $this->redirect($this->versionLink($version));
    }

    /** One row per rank, read-only for anyone who can't save it. */
    public function actionRankArt(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);
        $key = $this->artFieldKey($version);

        $hashes = [];
        foreach ($version->RankArt as $art) {
            if ($art->field_key === $key) {
                $hashes[$art->rank_id] = $art->image_hash;
            }
        }

        return $this->view('Cav7\Citations:Version\RankArt', 'cav7_cit_version_rank_art', [
            'version' => $version,
            'key' => $key,
            'ranks' => $this->repository('Cav7\Citations:PayGrade')->list(),
            'hashes' => $hashes,
            'canEdit' => VersionLifecycle::isDraft($version->state) && $this->actor()->canEditDrafts(),
        ]);
    }

    public function actionSaveRankArt(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();
        $version = $this->version($params);
        $key = $this->artFieldKey($version);
        $drafts = $this->drafts();

        foreach ($this->repository('Cav7\Citations:PayGrade')->list() as $rank) {
            $rankId = (int) $rank['rank_id'];
            $path = $this->uploaded("art_$rankId");
            if ($path !== null) {
                $drafts->setRankArt($version, $key, $rankId, $path);
            }
        }
        foreach ($this->filter('remove', 'array-uint') as $rankId) {
            $drafts->removeRankArt($version, $key, $rankId);
        }

        return $this->redirect($this->buildLink('rosters/citation-versions/rank-art', $version, ['key' => $key]), \XF::phrase('your_changes_have_been_saved'));
    }

    /** Never cached and stores nothing. A field posted with it is what S1 is typing, unsaved. */
    public function actionPreview(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();
        $version = $this->version($params);
        $service = $this->plate();

        $field = $this->filter('field', 'array-str');
        if ($field !== [] && VersionLifecycle::isDraft($version->state)) {
            try {
                $service->withField($version, $this->filter('original_key', 'str') ?: null, FieldForm::definition($field));
            } catch (\InvalidArgumentException | \JsonException $e) {
                return $this->previewReply(['invalid' => $e->getMessage()]);
            }
        }

        if ($version->plate_format === null) {
            return $this->previewReply(['noPlate' => true]);
        }

        $posted = $this->filter(['sample' => [
            'name' => 'str', 'rank_id' => 'uint', 'date' => 'str', 'text' => 'str', 'long' => 'bool',
            'inputs' => 'array-str', 'signatures' => 'array-uint',
        ]]);

        try {
            $preview = $service->draw($version, $service->sample($version, $posted['sample']));
        } catch (RenderFailed $e) {
            \XF::logException($e, false, 'Template preview failed: ');

            return $this->previewReply(['failed' => true]);
        }

        $reply = $this->previewReply(['fit' => $preview['fit']]);
        $reply->setJsonParam('src', 'data:image/jpeg;base64,' . base64_encode($preview['jpeg']));

        return $reply;
    }

    public function actionDelete(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);
        $template = $version->Template;

        if ($this->isPost()) {
            $this->drafts()->deleteDraft($version);

            return $this->redirect($this->em()->find('Cav7\Citations:Template', $template->template_id)
                ? $this->buildLink('rosters/citation-templates', $template)
                : $this->buildLink('rosters/citation-templates'));
        }

        return $this->confirm(
            \XF::phrase('confirm_action'),
            \XF::phrase('cav7_cit_delete_draft_x', ['number' => $version->version_number, 'title' => $template->title]),
            $this->buildLink('rosters/citation-versions/delete', $version),
            \XF::phrase('delete')
        );
    }

    public function actionPublish(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);
        $service = $this->publication();

        if ($this->isPost()) {
            $service->publish($version, \XF::visitor()->user_id);

            return $this->redirect(
                $this->buildLink('rosters/citation-templates', $version->Template),
                \XF::phrase('cav7_cit_version_x_published', ['number' => $version->version_number])
            );
        }

        return $this->confirm(
            \XF::phrase('cav7_cit_publish_version_x', ['number' => $version->version_number]),
            \XF::phrase('cav7_cit_publish_explain', ['number' => $version->version_number, 'title' => $version->Template->title]),
            $this->buildLink('rosters/citation-versions/publish', $version),
            \XF::phrase('cav7_cit_publish'),
            VersionLifecycle::isDraft($version->state) ? $service->problems($version) : [\XF::phrase('cav7_cit_refused_not_a_draft')]
        );
    }

    public function actionRetire(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);

        if ($this->isPost()) {
            $this->publication()->retire($version);

            return $this->redirect(
                $this->buildLink('rosters/citation-templates', $version->Template),
                \XF::phrase('cav7_cit_version_x_retired', ['number' => $version->version_number])
            );
        }

        $pins = $this->repo()->versionPins()[$version->template_version_id] ?? 0;

        return $this->confirm(
            \XF::phrase('cav7_cit_retire_version_x', ['number' => $version->version_number]),
            \XF::phrase($pins === 1 ? 'cav7_cit_retire_explain_one' : 'cav7_cit_retire_explain', [
                'number' => $version->version_number,
                'title' => $version->Template->title,
                'count' => $pins,
            ]),
            $this->buildLink('rosters/citation-versions/retire', $version),
            \XF::phrase('cav7_cit_retire')
        );
    }

    public function actionMoveCitations(ParameterBag $params): AbstractReply
    {
        $version = $this->version($params);
        $actor = $this->actor();

        $grants = array_filter(
            $this->finder('Cav7\Citations:Grant')
                ->where('template_version_id', $version->template_version_id)
                ->order('grant_date', 'DESC')
                ->fetch()
                ->toArray(),
            fn (object $grant): bool => $actor->canIssue($grant->is_regiment)
        );

        $targets = [];
        foreach ($grants as $grant) {
            $targets += $this->repo()->movableVersions(
                $version->template_id,
                $version->template_version_id,
                "{$grant->kind_type}:{$grant->kind_id}"
            );
        }
        krsort($targets);

        if ($this->isPost()) {
            $ids = array_values(array_intersect($this->filter('grant_ids', 'array-uint'), array_keys($grants)));
            if ($ids === []) {
                throw new PrintableException(\XF::phrase('cav7_cit_refused_no_citations_chosen'));
            }

            $result = $this->service(GrantService::class)->moveMany(
                $ids,
                $this->filter('target_version_id', 'uint'),
                $this->filter('reason', 'str'),
                $actor
            );

            return $this->view('Cav7\Citations:Version\Moved', 'cav7_cit_version_moved', [
                'version' => $version,
                'moved' => count($result['moved']),
                'skipped' => array_map(fn (int $id, array $why): array => [
                    'grant' => $grants[$id],
                    'why' => implode(' ', $why),
                ], array_keys($result['skipped']), $result['skipped']),
            ]);
        }

        return $this->view('Cav7\Citations:Version\Move', 'cav7_cit_version_move', [
            'version' => $version,
            'grants' => $grants,
            'targets' => $targets,
            'names' => $this->repository('Cav7\Citations:Grant')->memberSummaries(array_keys($grants)),
        ]);
    }

    /** @param array<string, mixed> $params */
    private function previewReply(array $params): AbstractReply
    {
        return $this->view('Cav7\Citations:Version\Preview', 'cav7_cit_version_preview', $params);
    }

    /** @return list<array<string, mixed>> */
    private function fieldRows(TemplateVersion $version): array
    {
        $billets = $this->finder('Cav7\Citations:SigningBillet')->fetch();

        $rows = [];
        foreach ($version->fieldDefinitions() as $field) {
            $rows[] = $field + [
                'font_label' => FontCatalog::FONTS[$field['font'] ?? '']['label'] ?? '',
                'billet' => $billets[$field['billet_id'] ?? 0]->title ?? '',
                'error' => PlateFit::patternError($field['pattern'] ?? ''),
            ];
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    private function panel(TemplateVersion $version): array
    {
        return ['can' => $this->actor()->canReadTemplates()] + $this->plate()->panel($version);
    }

    /** @throws \XF\Mvc\Reply\Exception A key that isn't one of this version's rank art fields. */
    private function artFieldKey(TemplateVersion $version): string
    {
        $key = $this->filter('key', 'str');
        foreach ($version->renderVersion()->fields as $field) {
            if ($field instanceof RankArtField && $field->key === $key) {
                return $key;
            }
        }
        throw $this->exception($this->notFound());
    }

    private function versionLink(TemplateVersion $version): string
    {
        return $this->buildLink('rosters/citation-versions', $version);
    }

    private function version(ParameterBag $params): TemplateVersion
    {
        return $this->assertRecordExists('Cav7\Citations:TemplateVersion', $params->template_version_id, ['Template']);
    }

    private function repo(): TemplateRepository
    {
        return $this->repository('Cav7\Citations:Template');
    }

    private function drafts(): Drafts
    {
        return $this->service(Drafts::class);
    }

    private function publication(): Publication
    {
        return $this->service(Publication::class);
    }

    private function plate(): PlatePreview
    {
        return $this->service(PlatePreview::class);
    }
}
