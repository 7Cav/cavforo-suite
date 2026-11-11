<?php

namespace Cav7\Citations\Pub\Controller;

use Cav7\Citations\Entity\Template;
use Cav7\Citations\Repository\Template as TemplateRepository;
use Cav7\Citations\Service\Drafts;
use Cav7\Citations\Template\ManagerAccess;
use Cav7\Citations\Template\PlateFit;
use Cav7\Citations\Template\TypesettingRows;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

/** The template list and page, the pay grade list and the legibility list. */
class Templates extends AbstractManager
{
    protected const PAGE = ManagerAccess::TEMPLATES;

    public function actionIndex(ParameterBag $params): AbstractReply
    {
        return $params->template_id ? $this->templatePage($this->template($params)) : $this->listPage();
    }

    public function actionAdd(): AbstractReply
    {
        if (!$this->isPost()) {
            return $this->view('Cav7\Citations:Template\Add', 'cav7_cit_template_add');
        }

        $draft = $this->drafts()->createTemplate($this->filter('title', 'str'), \XF::visitor()->user_id);

        return $this->redirect($this->buildLink('rosters/citation-versions', $draft));
    }

    public function actionNewDraft(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();
        $draft = $this->drafts()->startDraft($this->template($params), \XF::visitor()->user_id);

        return $this->redirect($this->buildLink('rosters/citation-versions', $draft));
    }

    public function actionPayGrades(): AbstractReply
    {
        return $this->view('Cav7\Citations:Template\PayGrades', 'cav7_cit_pay_grades', [
            'ranks' => $this->repository('Cav7\Citations:PayGrade')->list(),
            'canEdit' => $this->actor()->canPublish(),
        ]);
    }

    public function actionSavePayGrades(): AbstractReply
    {
        $this->assertPostOnly();
        $grades = $this->filter('grades', 'array');
        $repo = $this->repository('Cav7\Citations:PayGrade');

        $this->app->db()->executeTransaction(function () use ($repo, $grades): void {
            foreach ($repo->list() as $rank) {
                $posted = $grades[$rank['rank_id']] ?? null;
                if (!is_array($posted)) {
                    continue;
                }

                $payGrade = trim((string) ($posted['pay_grade'] ?? ''));
                $abbreviation = trim((string) ($posted['abbreviation'] ?? ''));
                if ($payGrade !== $rank['pay_grade'] || $abbreviation !== $rank['abbreviation']) {
                    $repo->set((int) $rank['rank_id'], $payGrade, $abbreviation);
                }
            }
        });

        return $this->redirect($this->buildLink('rosters/citation-templates/pay-grades'), \XF::phrase('your_changes_have_been_saved'));
    }

    public function actionLegibility(): AbstractReply
    {
        $entries = (new TypesettingRows($this->app->db()))->belowLegibility();

        $versions = $this->em()->findByIds(
            'Cav7\Citations:TemplateVersion',
            array_unique(array_column($entries, 'template_version_id'))
        );
        $designs = [];
        foreach ($versions as $id => $version) {
            $designs[$id] = $version->renderVersion();
        }

        $actor = $this->actor();

        return $this->view('Cav7\Citations:Template\Legibility', 'cav7_cit_legibility', [
            'entries' => array_map(
                fn (array $entry): array => $entry + ['can_move' => $actor->canIssue((bool) $entry['is_regiment'])],
                PlateFit::withThresholds($entries, $designs)
            ),
            'canRead' => $actor->canReadTemplates(),
        ]);
    }

    private function listPage(): AbstractReply
    {
        $repo = $this->repo();
        $kind = $this->filter('kind', 'str');
        $titles = array_merge(...array_values($repo->kindOptions()));
        $currentIds = $repo->currentVersionIds();
        $pins = $repo->versionPins();
        $regimentKinds = $this->regimentKinds();

        $rows = [];
        foreach ($this->finder('Cav7\Citations:Template')->order('title')->fetch() as $template) {
            $current = $template->Versions[$currentIds[$template->template_id] ?? 0] ?? null;
            $shown = $current ?? $template->Versions->last();
            $kinds = $shown->kinds();
            if ($kind !== '' && !in_array($kind, $kinds, true)) {
                continue;
            }

            $rows[] = [
                'template' => $template,
                'shown' => $shown,
                'serves' => $this->titlesOf($kinds, $titles),
                'regiment' => array_intersect($kinds, $regimentKinds) !== [],
                'issued' => array_sum(array_intersect_key($pins, $template->Versions->toArray())),
            ];
        }

        return $this->view('Cav7\Citations:Template\List', 'cav7_cit_template_list', [
            'rows' => $rows,
            'kind' => $kind,
            'kinds' => array_intersect_key($titles, array_flip($repo->offeredKinds())),
            'canAdd' => $this->actor()->canEditDrafts(),
            'canRead' => $this->actor()->canReadTemplates(),
        ]);
    }

    private function templatePage(Template $template): AbstractReply
    {
        $repo = $this->repo();
        $actor = $this->actor();
        $current = $repo->currentVersion($template->template_id);
        $shown = $current ?? $template->Versions->last();
        $titles = array_merge(...array_values($repo->kindOptions()));

        $published = 0;
        $hasDraft = false;
        foreach ($template->Versions as $version) {
            $published += $version->state === 'published' ? 1 : 0;
            $hasDraft = $hasDraft || $version->state === 'draft';
        }

        return $this->view('Cav7\Citations:Template\View', 'cav7_cit_template_view', [
            'template' => $template,
            'shown' => $shown,
            'serves' => $this->titlesOf($shown->kinds(), $titles),
            'inputs' => $shown->inputRows(),
            'pins' => $repo->versionPins(),
            'published' => $published,
            'can' => [
                'read' => $actor->canReadTemplates(),
                'edit' => $actor->canEditDrafts(),
                'publish' => $actor->canPublish(),
                'move' => $actor->canIssue(false) || $actor->canIssue(true),
                'newDraft' => $actor->canEditDrafts() && !$hasDraft,
            ],
        ]);
    }

    /**
     * @param list<string> $kinds
     * @param array<string, string> $titles
     * @return list<string>
     */
    private function titlesOf(array $kinds, array $titles): array
    {
        return array_values(array_intersect_key($titles, array_flip($kinds)));
    }

    /** @return list<string> */
    private function regimentKinds(): array
    {
        return array_map(fn (int $id): string => "award:$id", $this->repository('Cav7\Citations:Grant')->regimentAwardIds());
    }

    private function template(ParameterBag $params): Template
    {
        return $this->assertRecordExists('Cav7\Citations:Template', $params->template_id);
    }

    private function repo(): TemplateRepository
    {
        return $this->repository('Cav7\Citations:Template');
    }

    private function drafts(): Drafts
    {
        return $this->service(Drafts::class);
    }
}
