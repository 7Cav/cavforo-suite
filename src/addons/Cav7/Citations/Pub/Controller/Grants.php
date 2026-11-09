<?php

namespace Cav7\Citations\Pub\Controller;

use Cav7\Citations\Entity\Grant;
use Cav7\Citations\Entity\GrantLog;
use Cav7\Citations\Entity\GrantMember;
use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Grant\Actor;
use Cav7\Citations\Grant\GrantForm;
use Cav7\Citations\Grant\GrantRules;
use Cav7\Citations\Grant\IssueRequest;
use Cav7\Citations\Grant\LogSummary;
use Cav7\Citations\Grant\PageAccess;
use Cav7\Citations\Render\FieldDependencies;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\Version;
use Cav7\Citations\Repository\Grant as GrantRepository;
use Cav7\Citations\Service\GrantFormView;
use Cav7\Citations\Service\GrantLinks;
use Cav7\Citations\Service\Grants as GrantService;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\Phrase;
use XF\PrintableException;
use XF\Pub\Controller\AbstractController;

/**
 * The S1 Citations pages for grants. Every action answers to PageAccess before it runs, so a
 * crafted POST meets the same refusal as a click. Refusals from the services are
 * PrintableExceptions, which the dispatcher turns into an error reply holding every message.
 */
class Grants extends AbstractController
{
    private const PER_PAGE = 50;

    protected function preDispatchController($action, ParameterBag $params): void
    {
        $action = strtolower((string) $action);
        $actor = $this->actor();

        if (!PageAccess::allows($action, $actor, null)) {
            throw $this->exception($this->noPermission());
        }

        $regiment = $this->regiment($params);
        if ($regiment !== null && !PageAccess::allows($action, $actor, $regiment)) {
            throw $this->exception($this->noPermission());
        }
    }

    public function actionIndex(ParameterBag $params): AbstractReply
    {
        return $params->grant_id ? $this->grantPage($this->grant($params)) : $this->listPage();
    }

    public function actionIssue(): AbstractReply
    {
        if (!$this->isPost()) {
            return $this->formPage($this->formView()->forIssue(GrantForm::blank(), $this->actor(), $this->regimentAwardIds()));
        }

        $post = $this->post();
        [$kindType, $kindId] = GrantForm::kind($post['kind']) ?? ['award', 0];
        $request = new IssueRequest(
            $kindType,
            $kindId,
            $post['template_version_id'],
            GrantForm::values($post),
            $this->regimentAwardIds()
        );

        $grant = $this->typesetting(fn (): Grant => $this->grants()->issue($request, $this->actor()));

        return $this->redirect($this->buildLink('rosters/citations', $grant), \XF::phrase('cav7_cit_citation_issued'));
    }

    /** The grant form re-rendered from its own post, after a choice that reshapes it. */
    public function actionForm(ParameterBag $params): AbstractReply
    {
        $post = GrantForm::acted($this->post(), $this->filter('form_action', 'str'));

        if ($this->filter('form_action', 'str') === 'add') {
            $post = $this->withAddedMember($post);
        }

        if ($params->grant_id) {
            $grant = $this->grant($params);
            $target = $this->targetVersion($grant);
            $mode = $target === null ? 'correct' : 'move';
            $fields = $this->formView()->forGrant($mode, $post, $grant, $target ?? $grant->Version);
            if ($target !== null) {
                $fields['moveTargets'] = $this->moveTargets($grant);
            }
        } else {
            $fields = $this->formView()->forIssue($post, $this->actor(), $this->regimentAwardIds());
        }

        return $this->view('Cav7\Citations:Grant\Form', 'cav7_cit_grant_form_body', $fields);
    }

    /** Never cached, stores nothing, and refuses nothing a half-filled form hasn't got yet. */
    public function actionPreview(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();
        $post = $this->post();
        $service = $this->grants();

        if ($params->grant_id) {
            $grant = $this->grant($params);
            $version = $this->targetVersion($grant) ?? $grant->Version;
            $values = $service->correctedValues($grant, $version, GrantForm::changes($post));
        } else {
            /** @var TemplateVersion|null $version */
            $version = $this->em()->find('Cav7\Citations:TemplateVersion', $post['template_version_id']);
            // The kind is what the permission check read, so the version has to be that kind's.
            if ($version?->state !== 'published' || !in_array($post['kind'], $version->kinds(), true)) {
                return $this->view('Cav7\Citations:Grant\Preview', 'cav7_cit_grant_preview', []);
            }
            $values = GrantForm::values($post);
        }

        $memberKey = $this->filter('preview_member', 'int');
        try {
            $preview = $service->preview($version, $values, $memberKey);
        } catch (RenderFailed $e) {
            \XF::logException($e, false, 'Citation preview failed: ');

            return $this->view('Cav7\Citations:Grant\Preview', 'cav7_cit_grant_preview', ['failed' => true]);
        }

        $tabs = [];
        foreach ($this->formView()->members($post['members']) as $key => $member) {
            $tabs[$key] = $member['username'];
        }

        return $this->view('Cav7\Citations:Grant\Preview', 'cav7_cit_grant_preview', [
            'src' => 'data:image/jpeg;base64,' . base64_encode($preview->jpeg),
            'text' => $preview->fit->text,
            'below' => $preview->fit->below,
            'wantsMember' => $values->members === [] && FieldDependencies::used($version->renderVersion(), 'name'),
            'tabs' => count($tabs) > 1 ? $tabs : [],
            'activeTab' => isset($tabs[$memberKey]) ? $memberKey : array_key_first($tabs),
        ]);
    }

    public function actionCorrect(ParameterBag $params): AbstractReply
    {
        $grant = $this->grant($params);

        if (!$this->isPost()) {
            return $this->formPage(
                $this->formView()->forGrant('correct', GrantForm::fromValues($grant->values()), $grant, $grant->Version)
            );
        }

        $post = $this->post();
        $this->assertFormIsCurrent($grant);

        $changed = $this->typesetting(fn (): bool => $this->grants()->correct(
            $grant,
            GrantForm::changes($post),
            $this->filter('reason', 'str'),
            $this->actor()
        ));
        if (!$changed) {
            return $this->error(\XF::phrase('cav7_cit_nothing_changed'));
        }

        return $this->redirect($this->buildLink('rosters/citations', $grant), \XF::phrase('cav7_cit_correction_saved'));
    }

    public function actionMove(ParameterBag $params): AbstractReply
    {
        $grant = $this->grant($params);
        $targets = $this->moveTargets($grant);
        if ($targets === []) {
            return $this->error(\XF::phrase('cav7_cit_no_version_to_move_to'));
        }

        if (!$this->isPost()) {
            $post = GrantForm::fromValues($grant->values());
            $target = reset($targets);
            $fields = $this->formView()->forGrant('move', $post, $grant, $target);

            return $this->formPage($fields + ['moveTargets' => $targets]);
        }

        $post = $this->post();
        $this->assertFormIsCurrent($grant);

        $missing = $this->typesetting(fn (): array => $this->grants()->moveVersion(
            $grant,
            $this->filter('target_version_id', 'uint'),
            $this->filter('reason', 'str'),
            GrantForm::changes($post),
            $this->actor()
        ));
        if ($missing !== []) {
            return $this->error(GrantService::messages($missing));
        }

        return $this->redirect($this->buildLink('rosters/citations', $grant), \XF::phrase('cav7_cit_correction_saved'));
    }

    /** Two steps: who, then the name and rank that person starts from, editable. */
    public function actionAddMember(ParameterBag $params): AbstractReply
    {
        $grant = $this->grant($params);
        $post = $this->post();
        $viewParams = ['grant' => $grant];

        if ($this->isPost() && $post['members'] !== []) {
            $added = GrantForm::values($post)->members;

            $this->typesetting(fn (): GrantMember => $this->grants()->addMember($grant, reset($added), $this->actor()));

            return $this->redirect($this->buildLink('rosters/citations', $grant), \XF::phrase('cav7_cit_member_added'));
        }

        if ($this->isPost()) {
            $post['members'] = [$this->formView()->memberFrom($this->filter('add_username', 'str'))];
            $viewParams += $this->formView()->fields($post, $grant->Version);
        }

        return $this->view('Cav7\Citations:Grant\AddMember', 'cav7_cit_grant_add_member', $viewParams);
    }

    public function actionRemoveMember(ParameterBag $params): AbstractReply
    {
        $grant = $this->grant($params);

        if ($this->isPost()) {
            $memberId = $this->filter('grant_member_id', 'uint');
            $member = $grant->Members[$memberId] ?? null;
            if ($member === null) {
                return $this->error(\XF::phrase('cav7_cit_refused_no_such_member', ['field' => $memberId]));
            }

            $this->grants()->removeMember($member, $this->filter('reason', 'str'), $this->actor(), $this->links());

            return $this->redirect($this->buildLink('rosters/citations', $grant), \XF::phrase('cav7_cit_member_removed'));
        }

        return $this->view('Cav7\Citations:Grant\RemoveMember', 'cav7_cit_grant_remove_member', [
            'grant' => $grant,
            'members' => $this->memberRows($grant),
        ]);
    }

    public function actionWithdraw(ParameterBag $params): AbstractReply
    {
        $grant = $this->grant($params);

        if ($this->isPost()) {
            $this->grants()->withdraw($grant, $this->filter('reason', 'str'), $this->actor());

            return $this->redirect($this->buildLink('rosters/citations', $grant), \XF::phrase('cav7_cit_grant_withdrawn'));
        }

        return $this->view('Cav7\Citations:Grant\Withdraw', 'cav7_cit_grant_withdraw', ['grant' => $grant]);
    }

    private function listPage(): AbstractReply
    {
        $actor = $this->actor();
        $page = $this->filterPage();
        $filters = $this->filter(['kind' => 'str', 'date' => 'str', 'member' => 'str']);

        $kind = GrantForm::kind($filters['kind']);
        $day = $filters['date'] === '' ? null : GrantRules::day($filters['date']);
        $relationId = $filters['member'] === '' ? null : $this->milpacOf($filters['member']);
        $repo = $this->repo();

        $grants = [];
        $total = 0;
        if ($actor->canOpenGrant(false)) {
            $finder = $repo->listed(false, $kind, $day, $relationId);
            $total = $finder->total();
            $grants = $finder->limitByPage($page, self::PER_PAGE)->fetch();
        }
        $regiment = $actor->canOpenGrant(true) ? $repo->listed(true, $kind, $day, $relationId)->fetch() : [];

        return $this->view('Cav7\Citations:Grant\List', 'cav7_cit_grant_list', [
            'grants' => $grants,
            'regimentGrants' => $regiment,
            'names' => $repo->memberSummaries($grants === [] ? [] : $grants->keys()),
            'filters' => $filters,
            'kinds' => $repo->issuedKinds(),
            'canIssue' => PageAccess::allows('issue', $actor, null),
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
        ]);
    }

    private function grantPage(Grant $grant): AbstractReply
    {
        $actor = $this->actor();
        $design = $grant->Version->renderVersion();
        $members = $this->memberRows($grant);
        $form = $this->formView()->fields(GrantForm::blank(), $grant->Version);

        return $this->view('Cav7\Citations:Grant\View', 'cav7_cit_grant_view', [
            'grant' => $grant,
            'signatures' => $this->pinnedSignatures($grant, $design),
            'grantInputs' => $this->printedInputs($design, 'grant', $grant->input_values),
            'memberInputs' => $form['memberInputs'],
            'memberRank' => $design->rank_scope === 'member',
            'generic' => FieldDependencies::generic($design),
            'genericText' => $form['genericText'],
            'members' => $members,
            'regimentUrl' => $grant->is_regiment ? $grant->renderUrl() : '',
            'log' => $this->history($grant, $members),
            'canOpenTemplate' => $actor->canOpenTemplateManager(),
            'can' => [
                'correct' => PageAccess::allows('correct', $actor, $grant->is_regiment) && !$grant->withdrawn_date,
                'addMember' => PageAccess::allows('addmember', $actor, $grant->is_regiment),
                'removeMember' => $grant->Members->count() > 0 && PageAccess::allows('removemember', $actor, $grant->is_regiment),
                'move' => PageAccess::allows('move', $actor, $grant->is_regiment) && $this->moveTargets($grant) !== [],
                'withdraw' => !$grant->withdrawn_date && PageAccess::allows('withdraw', $actor, $grant->is_regiment),
            ],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function memberRows(Grant $grant): array
    {
        $links = $this->links();

        $rows = [];
        foreach ($grant->Members as $member) {
            $rows[] = [
                'member' => $member,
                'milpac' => $member->RosterUser,
                'username' => $member->RosterUser?->User?->username ?? $member->name,
                'render_url' => $member->renderUrl(),
                'rows' => $links->rowLabels($member->grant_member_id),
            ];
        }
        return $rows;
    }

    /** @return list<array{billet: string, line: string, retired: bool}> */
    private function pinnedSignatures(Grant $grant, Version $design): array
    {
        $pins = [];
        foreach ($grant->PinnedSignatures as $pin) {
            $pins[$pin->field_key] = $pin->signature_id;
        }
        $signatures = $this->em()->findByIds('Cav7\Citations:Signature', array_values($pins), 'Billet');

        $printed = [];
        foreach ($design->fields as $field) {
            $signature = $field instanceof SignatureSlot ? ($signatures[$pins[$field->key] ?? 0] ?? null) : null;
            if ($signature !== null) {
                $printed[] = [
                    'billet' => (string) $signature->Billet?->title,
                    'line' => $signature->pinned()->printed_lines[0],
                    'retired' => $signature->state === 'retired',
                ];
            }
        }
        return $printed;
    }

    /**
     * @param array<string, string> $values
     * @return list<array{label: string, value: string}>
     */
    private function printedInputs(Version $design, string $scope, array $values): array
    {
        $inputs = [];
        foreach ($design->inputs as $input) {
            if ($input->scope === $scope) {
                $inputs[] = ['label' => $input->label, 'value' => $values[$input->key] ?? ''];
            }
        }
        return $inputs;
    }

    /**
     * Newest first, ending on the issue, which the grant row holds rather than the log.
     *
     * @param list<array<string, mixed>> $members
     * @return list<array{revision: int, date: int, by: string, what: list<Phrase>, reason: string}>
     */
    private function history(Grant $grant, array $members): array
    {
        $names = [];
        foreach ($members as $member) {
            $names[$member['member']->grant_member_id] = $member['username'];
        }

        $history = [[
            'revision' => 1,
            'date' => $grant->issue_date,
            'by' => $grant->issue_username,
            'what' => [\XF::phrase('cav7_cit_log_issued')],
            'reason' => '',
        ]];

        /** @var GrantLog $log */
        foreach ($grant->Log as $log) {
            array_unshift($history, [
                'revision' => $log->revision_after,
                'date' => $log->log_date,
                'by' => $log->username,
                'what' => array_map(
                    fn (array $line): Phrase => \XF::phrase($line[0], $line[1]),
                    LogSummary::of($log->event, $log->detail, $names)
                ),
                'reason' => $log->reason,
            ]);
        }
        return $history;
    }

    /** @param array<string, mixed> $fields */
    private function formPage(array $fields): AbstractReply
    {
        return $this->view('Cav7\Citations:Grant\FormPage', 'cav7_cit_grant_form', $fields);
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     * @throws PrintableException
     */
    private function withAddedMember(array $post): array
    {
        $added = $this->formView()->memberFrom($this->filter('add_username', 'str'), $post['fill_all']);

        foreach ($post['members'] as $member) {
            if ((int) $member['relation_id'] === $added['relation_id']) {
                throw new PrintableException(\XF::phrase('cav7_cit_member_already_on', ['name' => $this->filter('add_username', 'str')]));
            }
        }

        $post['members'][] = $added;

        return $post;
    }

    /** @return array<int, TemplateVersion> */
    private function moveTargets(Grant $grant): array
    {
        return $this->em()->getRepository('Cav7\Citations:Template')->movableVersions(
            $grant->Version->template_id,
            $grant->template_version_id,
            "{$grant->kind_type}:{$grant->kind_id}"
        );
    }

    private function targetVersion(Grant $grant): ?TemplateVersion
    {
        return $this->moveTargets($grant)[$this->filter('target_version_id', 'uint')] ?? null;
    }

    /**
     * A correction posts the whole form, so one opened before another save would undo it.
     *
     * @throws PrintableException
     */
    private function assertFormIsCurrent(Grant $grant): void
    {
        if ($this->filter('revision', 'uint') !== $grant->revision) {
            throw new PrintableException(\XF::phrase('cav7_cit_refused_grant_moved_on'));
        }
    }

    /**
     * @template T
     * @param \Closure(): T $write
     * @return T
     */
    private function typesetting(\Closure $write): mixed
    {
        try {
            return $write();
        } catch (RenderFailed $e) {
            \XF::logException($e, false, 'Citation typesetting failed: ');

            throw $this->exception($this->error(\XF::phrase('cav7_cit_typesetting_failed')));
        }
    }

    private function milpacOf(string $username): int
    {
        $user = $this->em()->findOne('XF:User', ['username' => $username]);
        $milpac = $user === null ? null : $this->em()->findOne('NF\Rosters:RosterUser', ['user_id' => $user->user_id]);

        return (int) $milpac?->relation_id;
    }

    /** @return array<string, mixed> */
    private function post(): array
    {
        $post = $this->filter([
            'kind' => 'str',
            'template_version_id' => 'uint',
            'form_version_id' => 'uint',
            'date' => 'str',
            'citation_text' => 'str',
            'signatures' => 'array-uint',
            'inputs' => 'array-str',
            'rank_id' => 'uint',
            'members' => 'array',
            'show_retired' => 'array-str',
            'fill_all' => 'array-str',
            'reason' => 'str',
            'revision' => 'uint',
        ]);
        $post['members'] = array_filter($post['members'], 'is_array');

        return $post;
    }

    /** Null until a grant or a posted kind says which. */
    private function regiment(ParameterBag $params): ?bool
    {
        if ($params->grant_id) {
            return $this->grant($params)->is_regiment;
        }

        $kind = GrantForm::kind($this->filter('kind', 'str'));

        return $kind === null ? null : GrantRules::isRegiment($kind[0], $kind[1], $this->regimentAwardIds());
    }

    /** @return list<int> */
    private function regimentAwardIds(): array
    {
        return GrantRules::awardList((string) $this->options()->cav7CitRegimentAwardIds) ?? [];
    }

    private function grant(ParameterBag $params): Grant
    {
        return $this->assertRecordExists('Cav7\Citations:Grant', $params->grant_id, ['Version']);
    }

    private function actor(): Actor
    {
        return $this->repo()->actor(\XF::visitor());
    }

    private function repo(): GrantRepository
    {
        return $this->repository('Cav7\Citations:Grant');
    }

    private function grants(): GrantService
    {
        return $this->service(GrantService::class);
    }

    private function links(): GrantLinks
    {
        return $this->service(GrantLinks::class);
    }

    private function formView(): GrantFormView
    {
        return $this->service(GrantFormView::class);
    }
}
