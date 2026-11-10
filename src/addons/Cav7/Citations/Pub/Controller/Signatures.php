<?php

namespace Cav7\Citations\Pub\Controller;

use Cav7\Citations\Entity\Signature;
use Cav7\Citations\Entity\SigningBillet;
use Cav7\Citations\Repository\Signature as SignatureRepository;
use Cav7\Citations\Service\Signatures as SignatureService;
use Cav7\Citations\Template\ManagerAccess;
use Cav7\Citations\Template\SignatureRules;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;
use XF\PrintableException;

/** Signatures and the signing billets they match to template slots. */
class Signatures extends AbstractManager
{
    protected const PAGE = ManagerAccess::SIGNATURES;

    public function actionIndex(): AbstractReply
    {
        $uses = $this->repo()->uses();
        $billets = $this->billets();

        $signatures = [];
        foreach ($this->finder('Cav7\Citations:Signature')->with('Billet')->order('state')->order('signature_id')->fetch() as $signature) {
            $signatures[] = [
                'signature' => $signature,
                'lines' => $signature->pinned()->printed_lines,
                'uses' => $uses[$signature->signature_id] ?? 0,
            ];
        }
        usort($signatures, fn (array $a, array $b): int => ($a['signature']->Billet?->display_order ?? 0) <=> ($b['signature']->Billet?->display_order ?? 0));

        $slots = SignatureRules::slotCounts(
            $this->app->db()->fetchAllColumn('SELECT field_definitions FROM xf_cav7_citation_template_version')
        );

        $active = [];
        $held = [];
        foreach ($signatures as $row) {
            $billetId = $row['signature']->billet_id;
            $active[$billetId] = ($active[$billetId] ?? 0) + ($row['signature']->state === 'active' ? 1 : 0);
            $held[$billetId] = true;
        }

        $rows = [];
        foreach ($billets as $id => $billet) {
            $rows[] = [
                'billet' => $billet,
                'slots' => $slots[$id] ?? 0,
                'active' => $active[$id] ?? 0,
                'removable' => !isset($slots[$id]) && !isset($held[$id]),
            ];
        }

        return $this->view('Cav7\Citations:Signature\List', 'cav7_cit_signature_list', [
            'billets' => $rows,
            'signatures' => $signatures,
            'canEdit' => $this->actor()->canPublish(),
        ]);
    }

    public function actionInk(ParameterBag $params): AbstractReply
    {
        return $this->image($this->signature($params)->ink_hash, 'png');
    }

    public function actionAdd(): AbstractReply
    {
        if (!$this->isPost()) {
            return $this->form(null);
        }

        $ink = $this->uploaded('ink') ?? throw new PrintableException(\XF::phrase('cav7_cit_refused_no_file'));
        $this->signatures()->create($this->filter('billet_id', 'uint'), $this->printedLines(), $ink, \XF::visitor()->user_id);

        return $this->redirect($this->buildLink('rosters/citation-signatures'), \XF::phrase('your_changes_have_been_saved'));
    }

    public function actionEdit(ParameterBag $params): AbstractReply
    {
        $signature = $this->signature($params);

        if (!$this->isPost()) {
            if ($this->repo()->isFrozen($signature->signature_id)) {
                return $this->error(\XF::phrase('cav7_cit_refused_signature_frozen'));
            }
            return $this->form($signature);
        }

        $this->signatures()->update($signature, $this->filter('billet_id', 'uint'), $this->printedLines(), $this->uploaded('ink'));

        return $this->redirect($this->buildLink('rosters/citation-signatures'), \XF::phrase('your_changes_have_been_saved'));
    }

    public function actionDelete(ParameterBag $params): AbstractReply
    {
        $signature = $this->signature($params);

        if ($this->isPost()) {
            $this->signatures()->delete($signature);

            return $this->redirect($this->buildLink('rosters/citation-signatures'));
        }

        return $this->confirm(
            \XF::phrase('confirm_action'),
            \XF::phrase('cav7_cit_delete_the_signature_x', ['line' => $signature->pinned()->printed_lines[0]]),
            $this->buildLink('rosters/citation-signatures/delete', $signature),
            \XF::phrase('delete')
        );
    }

    public function actionRetire(ParameterBag $params): AbstractReply
    {
        return $this->setState($params, 'retired', 'cav7_cit_signature_retired');
    }

    public function actionReinstate(ParameterBag $params): AbstractReply
    {
        return $this->setState($params, 'active', 'cav7_cit_signature_active_again');
    }

    public function actionAddBillet(): AbstractReply
    {
        return $this->billetForm($this->em()->create('Cav7\Citations:SigningBillet'));
    }

    public function actionEditBillet(): AbstractReply
    {
        return $this->billetForm($this->billet());
    }

    public function actionDeleteBillet(): AbstractReply
    {
        $billet = $this->billet();

        if ($this->isPost()) {
            $billet->delete();

            return $this->redirect($this->buildLink('rosters/citation-signatures'));
        }

        return $this->confirm(
            \XF::phrase('confirm_action'),
            \XF::phrase('cav7_cit_delete_the_billet_x', ['title' => $billet->title]),
            $this->buildLink('rosters/citation-signatures/delete-billet', null, ['billet_id' => $billet->billet_id]),
            \XF::phrase('delete')
        );
    }

    private function setState(ParameterBag $params, string $state, string $message): AbstractReply
    {
        $this->assertPostOnly();
        $signature = $this->signature($params);
        $signature->state = $state;
        $signature->save();

        return $this->redirect($this->buildLink('rosters/citation-signatures'), \XF::phrase($message));
    }

    private function form(?Signature $signature): AbstractReply
    {
        return $this->view('Cav7\Citations:Signature\Form', 'cav7_cit_signature_form', [
            'signature' => $signature,
            'lines' => $signature?->pinned()->printed_lines ?? [],
            'billets' => $this->billets(),
        ]);
    }

    private function billetForm(SigningBillet $billet): AbstractReply
    {
        if (!$this->isPost()) {
            return $this->view('Cav7\Citations:Signature\BilletForm', 'cav7_cit_billet_form', ['billet' => $billet]);
        }

        $billet->bulkSet($this->filter(['title' => 'str', 'display_order' => 'uint']));
        $billet->save();

        return $this->redirect($this->buildLink('rosters/citation-signatures'), \XF::phrase('your_changes_have_been_saved'));
    }

    /** @return list<string> */
    private function printedLines(): array
    {
        return array_values(array_filter($this->filter('lines', 'array-str'), fn (string $line): bool => $line !== ''));
    }

    /** @return \XF\Mvc\Entity\AbstractCollection<SigningBillet> */
    private function billets(): \XF\Mvc\Entity\AbstractCollection
    {
        return $this->finder('Cav7\Citations:SigningBillet')->order('display_order')->order('title')->fetch();
    }

    private function billet(): SigningBillet
    {
        return $this->assertRecordExists('Cav7\Citations:SigningBillet', $this->filter('billet_id', 'uint'));
    }

    private function signature(ParameterBag $params): Signature
    {
        return $this->assertRecordExists('Cav7\Citations:Signature', $params->signature_id, ['Billet']);
    }

    private function repo(): SignatureRepository
    {
        return $this->repository('Cav7\Citations:Signature');
    }

    private function signatures(): SignatureService
    {
        return $this->service(SignatureService::class);
    }
}
