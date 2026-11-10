<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Entity\Grant;
use Cav7\Citations\Entity\GrantLog;
use Cav7\Citations\Entity\GrantMember;
use Cav7\Citations\Entity\TemplateVersion;
use Cav7\Citations\Grant\Actor;
use Cav7\Citations\Grant\Correction;
use Cav7\Citations\Grant\GrantChanges;
use Cav7\Citations\Grant\GrantPreview;
use Cav7\Citations\Grant\GrantRender;
use Cav7\Citations\Grant\GrantRules;
use Cav7\Citations\Grant\GrantValues;
use Cav7\Citations\Grant\IssueRequest;
use Cav7\Citations\Grant\MemberValues;
use Cav7\Citations\Grant\RemakePlan;
use Cav7\Citations\Grant\RowClearer;
use Cav7\Citations\Render\PinnedSignature;
use Cav7\Citations\Render\RenderFailed;
use Cav7\Citations\Repository\Grant as GrantRepository;
use Cav7\Citations\Template\VersionLifecycle;
use XF\Phrase;
use XF\PrintableException;
use XF\Service\AbstractService;

/**
 * Every write to a grant. The caller checks permissions against Actor, apart from the
 * regiment refusal in issue(). Decisions live in Grant\GrantRules, and each method here
 * gathers the facts, refuses, typesets ahead of the transaction, then writes.
 *
 * Refusals are PrintableExceptions holding every message, and a failed typesetting is a
 * RenderFailed. Neither leaves anything written.
 */
class Grants extends AbstractService
{
    public const EVENT = 'cav7_citation_regiment_grant_changed';

    private ?GrantRender $render = null;

    /**
     * @throws PrintableException
     * @throws RenderFailed
     */
    public function issue(IssueRequest $request, Actor $actor): Grant
    {
        /** @var TemplateVersion|null $version */
        $version = $this->em()->find('Cav7\Citations:TemplateVersion', $request->templateVersionId);
        if ($version === null) {
            throw new PrintableException(\XF::phrase('cav7_cit_refused_version_not_published'));
        }

        $design = $version->renderVersion();
        $regiment = GrantRules::isRegiment($request->kindType, $request->kindId, $request->regimentAwardIds);
        $values = $this->repo()->snapshotted(
            $request->values->withCitationText($this->plain($request->values->citationText))->fittedTo($design)
        );
        $kindTitle = $this->repo()->kindTitle($request->kindType, $request->kindId);
        $day = GrantRules::day($values->date);
        $billets = $this->repo()->billets(array_values($values->signatures));

        self::refuse(GrantRules::issueProblems(
            $request,
            $values,
            $design,
            $version->state,
            $version->kinds(),
            $kindTitle,
            $regiment && $day !== null && $this->repo()->liveRegimentExists($request->kindId, $day),
            $actor,
            $billets,
            $version->artRanks(),
            $this->repo()->milpacs(array_map(fn (MemberValues $member): int => $member->relationId, array_values($values->members))),
        ));

        $pinned = $this->repo()->pinned($values);
        $typesetting = $this->render()->make(
            $version,
            $values,
            RemakePlan::everything($design, array_keys($values->members)),
            $pinned,
        );

        $grant = $this->awardTransaction($regiment ? $request->kindId : null, function () use ($request, $version, $values, $regiment, $day, $kindTitle, $actor, $typesetting, $pinned, $billets): Grant {
            $state = $this->db()->fetchOne(
                'SELECT state FROM xf_cav7_citation_template_version WHERE template_version_id = ? FOR UPDATE',
                $version->template_version_id
            );
            if ($state !== VersionLifecycle::PUBLISHED) {
                throw new PrintableException(\XF::phrase('cav7_cit_refused_version_not_published'));
            }
            $this->holdSignatures($values->signatures, $pinned, $billets);
            if ($regiment && $day !== null && $this->repo()->liveRegimentExists($request->kindId, $day)) {
                throw new PrintableException(\XF::phrase('cav7_cit_refused_regiment_duplicate'));
            }

            /** @var Grant $grant */
            $grant = $this->em()->create('Cav7\Citations:Grant');
            $grant->bulkSet([
                'is_regiment' => $regiment,
                'kind_type' => $request->kindType,
                'kind_id' => $request->kindId,
                'kind_title' => $kindTitle,
                'template_version_id' => $version->template_version_id,
                'url_token' => $regiment ? self::token() : null,
                'issue_user_id' => $actor->userId,
                'issue_username' => $actor->username,
            ]);
            $grant->takeValues($values);
            $grant->save(true, false);

            $this->pin($grant, $values->signatures);

            $memberIds = [];
            foreach ($values->members as $key => $member) {
                $memberIds[$key] = $this->createMember($grant, $member, $actor)->grant_member_id;
            }
            $this->render()->store($grant->grant_id, $typesetting, $memberIds);

            return $grant;
        });

        if ($regiment) {
            $this->announce($grant, 'issued');
        }
        return $grant;
    }

    /**
     * What a form's values would print, for one member of them or for no member at all.
     * Nothing is stored and nothing refuses, so a half-filled form still shows.
     *
     * @throws RenderFailed
     */
    public function preview(TemplateVersion $version, GrantValues $values, int $memberKey): GrantPreview
    {
        $values = $this->repo()->snapshotted(
            $values->withCitationText($this->plain($values->citationText))->fittedTo($version->renderVersion())
        );

        return $this->render()->preview($version, $values, $values->previewedMember($memberKey), $this->repo()->pinned($values));
    }

    /** The values a correction or a move would leave, for its preview. */
    public function correctedValues(Grant $grant, TemplateVersion $version, GrantChanges $changes): GrantValues
    {
        $old = $grant->values();
        $design = $version->renderVersion();

        return $version->template_version_id === $grant->template_version_id
            ? $old->changed($changes, $design)
            : $old->movedTo($design, $changes, $this->repo()->billets(array_values($changes->signatures + $old->signatures)));
    }

    /**
     * @return bool False when the changes changed nothing, so nothing was written.
     * @throws PrintableException
     * @throws RenderFailed
     */
    public function correct(Grant $grant, GrantChanges $changes, string $reason, Actor $actor): bool
    {
        $version = $grant->Version;
        $design = $version->renderVersion();
        $changes = $changes->withCitationText($this->plain($changes->citationText));

        $old = $grant->values();
        $new = $this->repo()->snapshotted($old->changed($changes, $design));
        $correction = Correction::between($design, $old, $new);

        if ($correction->isEmpty() && array_diff_key($changes->members, $old->members) === []) {
            return false;
        }

        $billets = $this->repo()->billets(array_values($new->signatures));
        self::refuse(GrantRules::correctionProblems(
            $design,
            $new,
            $changes,
            $reason,
            $grant->withdrawn_date,
            $this->dateMeetsLiveRegiment($grant, $old, $new),
            $billets,
            $version->artRanks(),
        ));

        $this->applyCorrection($grant, $version, $old, $new, $billets, $correction->changes, $correction->remake, $reason, $actor);
        return true;
    }

    /**
     * @return list<array{field: string, rule: string, params: array<string, string|int>}> The missing list. Nothing moved unless it's empty.
     * @throws PrintableException
     * @throws RenderFailed
     */
    public function moveVersion(Grant $grant, int $targetVersionId, string $reason, GrantChanges $extra, Actor $actor): array
    {
        /** @var TemplateVersion|null $target */
        $target = $this->em()->find('Cav7\Citations:TemplateVersion', $targetVersionId);
        $design = $target?->renderVersion();

        self::refuse(GrantRules::moveProblems(
            $reason,
            $grant->withdrawn_date,
            $grant->is_regiment,
            "{$grant->kind_type}:{$grant->kind_id}",
            $grant->template_version_id,
            $targetVersionId,
            $grant->Version->template_id,
            $target?->template_id,
            $target?->state,
            $target?->kinds() ?? [],
            $design,
        ));

        $old = $grant->values();
        $billets = $this->repo()->billets(array_values($extra->signatures + $old->signatures));
        $new = $this->repo()->snapshotted(
            $old->movedTo($design, $extra->withCitationText($this->plain($extra->citationText)), $billets)
        );

        $missing = GrantRules::valueProblems($design, $new, $billets, $target->artRanks());
        if ($missing !== []) {
            return $missing;
        }
        if ($this->dateMeetsLiveRegiment($grant, $old, $new)) {
            throw new PrintableException(\XF::phrase('cav7_cit_refused_regiment_duplicate'));
        }

        $changes = Correction::between($design, $old, $new)->changes;
        $changes[] = ['what' => 'template_version', 'old' => $grant->template_version_id, 'new' => $targetVersionId];

        $this->applyCorrection(
            $grant,
            $target,
            $old,
            $new,
            $billets,
            $changes,
            RemakePlan::everything($design, array_keys($new->members)),
            $reason,
            $actor,
        );
        return [];
    }

    /**
     * Each grant moves as its own correction, sharing this service's width table. A grant with
     * something missing or in the way is skipped and reported, and so is a kind of grant the
     * actor can't issue.
     *
     * @param list<int> $grantIds
     * @return array{moved: list<int>, skipped: array<int, list<string>>} `skipped` holds why, by grant id.
     */
    public function moveMany(array $grantIds, int $targetVersionId, string $reason, Actor $actor): array
    {
        $moved = [];
        $skipped = [];

        foreach ($grantIds as $grantId) {
            /** @var Grant|null $grant */
            $grant = $this->em()->find('Cav7\Citations:Grant', $grantId);
            if ($grant === null) {
                $skipped[$grantId] = [(string) \XF::phrase('cav7_cit_refused_no_such_grant')];
                continue;
            }
            if (!$actor->canIssue($grant->is_regiment)) {
                $skipped[$grantId] = [(string) \XF::phrase(
                    $grant->is_regiment ? 'cav7_cit_refused_regiment_needs_permission' : 'cav7_cit_refused_member_grant_needs_permission'
                )];
                continue;
            }

            try {
                $missing = $this->moveVersion($grant, $targetVersionId, $reason, new GrantChanges(), $actor);
            } catch (PrintableException $e) {
                $skipped[$grantId] = array_map('strval', $e->getMessages());
                continue;
            } catch (RenderFailed $e) {
                $skipped[$grantId] = [$e->getMessage()];
                continue;
            }

            if ($missing === []) {
                $moved[] = $grantId;
            } else {
                $skipped[$grantId] = array_map('strval', self::messages($missing));
            }
        }

        return ['moved' => $moved, 'skipped' => $skipped];
    }

    /**
     * The member takes the grant as it stands, retired signatories included, and reuses its
     * shared rows, so citation text breaks for them as it does for everyone else.
     *
     * @throws PrintableException
     * @throws RenderFailed
     */
    public function addMember(Grant $grant, MemberValues $member, Actor $actor): GrantMember
    {
        $version = $grant->Version;
        $design = $version->renderVersion();
        $values = $grant->values();

        $key = 0;
        $withMember = $this->repo()->snapshotted($values->withMember($key, $member->fittedTo($design)));
        $member = $withMember->members[$key];

        self::refuse(GrantRules::addProblems(
            $grant->is_regiment,
            array_map(fn (MemberValues $held): int => $held->relationId, array_values($values->members)),
            $design,
            $member,
            $this->repo()->milpacs([$member->relationId]),
        ));

        $typesetting = $this->render()->make(
            $version,
            $withMember,
            RemakePlan::forMember($design, $key),
            $this->repo()->pinned($values),
        );

        return $this->db()->executeTransaction(function () use ($grant, $values, $member, $actor, $typesetting, $key): GrantMember {
            $this->assertUnmoved($grant, $values);

            $row = $this->createMember($grant, $member, $actor);
            $this->render()->store($grant->grant_id, $typesetting, [$key => $row->grant_member_id]);

            $this->log($grant, GrantLog::MEMBER_ADDED, $actor, '', [
                'name' => $row->name,
                'rank' => GrantValues::rankFacet($member->rankId, $member->rank),
            ], $row->grant_member_id);
            $grant->clearCache('Members');

            return $row;
        });
    }

    /**
     * Rows clear first, inside the transaction, so a failure leaves the member on the grant.
     * A grant whose last member goes stays on file.
     *
     * @return list<array<string, mixed>> The rows cleared.
     * @throws PrintableException
     */
    public function removeMember(GrantMember $member, string $reason, Actor $actor, RowClearer $clearer): array
    {
        self::refuse(GrantRules::reasonProblems($reason));

        $grant = $member->Grant;
        $values = $grant->values();

        return $this->db()->executeTransaction(function () use ($grant, $values, $member, $reason, $actor, $clearer): array {
            $this->assertUnmoved($grant, $values);

            $memberId = $member->grant_member_id;
            $cleared = $clearer->clear($memberId);
            $this->render()->rows->clearMember($grant->grant_id, $memberId);

            $this->log($grant, GrantLog::MEMBER_REMOVED, $actor, $reason, [
                'name' => $member->name,
                'rank' => $member->rank_id === null ? null : [
                    'rank_id' => $member->rank_id,
                    'rank_title' => $member->rank_title,
                    'rank_pay_grade' => $member->rank_pay_grade,
                    'rank_abbreviation' => $member->rank_abbreviation,
                ],
                'inputs' => $member->input_values,
                'rows' => $cleared,
            ], $memberId);

            $member->delete(true, false);
            $grant->clearCache('Members');

            return $cleared;
        });
    }

    /** @throws PrintableException */
    public function withdraw(Grant $grant, string $reason, Actor $actor): void
    {
        self::refuse(GrantRules::withdrawProblems($grant->is_regiment, $grant->withdrawn_date, $reason));

        $values = $grant->values();
        $this->db()->executeTransaction(function () use ($grant, $values, $reason, $actor): void {
            $this->assertUnmoved($grant, $values);

            $grant->withdrawn_date = \XF::$time;
            $grant->save(true, false);
            $this->log($grant, GrantLog::WITHDRAWN, $actor, $reason);
        });

        $this->announce($grant, 'withdrawn');
    }

    /**
     * One save moves the revision by one, however many things changed.
     *
     * @param array<int, int> $billets Billet id by signature id, as the refusals read them.
     * @param list<array<string, mixed>> $changes
     */
    private function applyCorrection(
        Grant $grant,
        TemplateVersion $version,
        GrantValues $old,
        GrantValues $new,
        array $billets,
        array $changes,
        RemakePlan $remake,
        string $reason,
        Actor $actor,
    ): void {
        $pinned = $this->repo()->pinned($new);
        $typesetting = $this->render()->make($version, $new, $remake, $pinned);

        $redated = $grant->is_regiment && $new->date !== $old->date;
        $this->awardTransaction($redated ? $grant->kind_id : null, function () use ($grant, $version, $old, $new, $billets, $changes, $typesetting, $pinned, $reason, $actor): void {
            $this->holdSignatures($new->signatures, $pinned, $billets);
            $this->assertUnmoved($grant, $old);
            if ($this->dateMeetsLiveRegiment($grant, $old, $new)) {
                throw new PrintableException(\XF::phrase('cav7_cit_refused_regiment_duplicate'));
            }

            if ($version->template_version_id !== $grant->template_version_id) {
                $this->render()->rows->clearGrant($grant->grant_id);
                $grant->template_version_id = $version->template_version_id;
                $grant->clearCache('Version');
            }

            $grant->takeValues($new);
            $grant->revision += 1;
            $grant->save(true, false);

            $this->pin($grant, $new->signatures);

            foreach ($new->members as $id => $member) {
                if ($member != $old->members[$id]) {
                    $row = $grant->Members[$id];
                    $row->takeValues($member);
                    $row->save(true, false);
                }
            }

            $this->render()->store($grant->grant_id, $typesetting);
            $this->log($grant, GrantLog::CORRECTED, $actor, $reason, ['changes' => $changes]);
        });

        if ($grant->is_regiment) {
            $this->announce($grant, 'corrected');
        }
    }

    /**
     * Typesetting runs outside the transaction, so what it read may have moved on. Holding the
     * row, a save compares what it planned from with what's there, and a clerk tries again.
     * The member rows are locked too, so a removal's row clear still comes before any plain read.
     *
     * @throws PrintableException
     */
    private function assertUnmoved(Grant $grant, GrantValues $read): void
    {
        $row = $this->db()->fetchRow(
            'SELECT revision, withdrawn_date FROM xf_cav7_citation_grant WHERE grant_id = ? FOR UPDATE',
            $grant->grant_id
        );
        $members = array_map('intval', $this->db()->fetchAllColumn(
            'SELECT grant_member_id FROM xf_cav7_citation_grant_member WHERE grant_id = ? ORDER BY grant_member_id FOR UPDATE',
            $grant->grant_id
        ));
        $held = array_keys($read->members);
        sort($held);

        if ((int) $row['revision'] !== $grant->revision
            || (int) $row['withdrawn_date'] !== $grant->withdrawn_date
            || $members !== $held) {
            throw new PrintableException(\XF::phrase('cav7_cit_refused_grant_moved_on'));
        }
    }

    /**
     * No two live regiment grants share an award and a day, and since withdrawn ones don't
     * count no unique key can say so. A named lock per award puts the check and the write in
     * one step. It's taken before the transaction begins, so the transaction's reads see
     * whatever the last holder committed.
     *
     * @template T
     * @param \Closure(): T $write
     * @return T
     */
    private function awardTransaction(?int $awardId, \Closure $write): mixed
    {
        if ($awardId === null) {
            return $this->db()->executeTransaction($write);
        }

        $lock = "cav7_cit_regiment_award_$awardId";
        if ((int) $this->db()->fetchOne('SELECT GET_LOCK(?, 30)', $lock) !== 1) {
            throw new PrintableException(\XF::phrase('cav7_cit_refused_busy'));
        }
        try {
            return $this->db()->executeTransaction($write);
        } finally {
            $this->db()->fetchOne('SELECT RELEASE_LOCK(?)', $lock);
        }
    }

    /**
     * Typesetting and the refusals read the signatures before the transaction. Their rows stay
     * locked until it commits, so an edit or a delete waits for the first-use mark and then
     * refuses. One that got in first refuses this write instead. Call it first in the
     * transaction: MariaDB fails a locking read of a row changed since the first plain read.
     *
     * @param array<string, int> $signatures By slot key.
     * @param array<string, PinnedSignature> $pinned What typesetting printed, by slot key.
     * @param array<int, int> $billets Billet id by signature id, as the refusals read them.
     * @throws PrintableException
     */
    private function holdSignatures(array $signatures, array $pinned, array $billets): void
    {
        $held = $this->repo()->lockSignatures(array_values($signatures));
        foreach ($signatures as $slot => $id) {
            if (!isset($held[$id]) || $held[$id] != [$billets[$id] ?? null, $pinned[$slot] ?? null]) {
                throw new PrintableException(\XF::phrase('cav7_cit_refused_signature_moved_on'));
            }
        }
    }

    private function dateMeetsLiveRegiment(Grant $grant, GrantValues $old, GrantValues $new): bool
    {
        $day = GrantRules::day($new->date);

        return $grant->is_regiment
            && $grant->withdrawn_date === 0
            && $day !== null
            && $new->date !== $old->date
            && $this->repo()->liveRegimentExists($grant->kind_id, $day, $grant->grant_id);
    }

    /**
     * A correction re-pins, so the pins alone can't tell whether a signature was ever used.
     * The first-use mark is what freezes a signature, and nothing clears it.
     *
     * @param array<string, int> $signatures By slot key.
     */
    private function pin(Grant $grant, array $signatures): void
    {
        $this->db()->delete('xf_cav7_citation_grant_signature', 'grant_id = ?', $grant->grant_id);
        if ($signatures !== []) {
            $this->db()->query(
                'UPDATE xf_cav7_citation_signature SET first_used_date = ?
                WHERE first_used_date = 0 AND signature_id IN (' . $this->db()->quote(array_values($signatures)) . ')',
                \XF::$time
            );
        }

        foreach ($signatures as $slot => $signatureId) {
            $pin = $this->em()->create('Cav7\Citations:GrantSignature');
            $pin->bulkSet(['grant_id' => $grant->grant_id, 'field_key' => $slot, 'signature_id' => $signatureId]);
            $pin->save(true, false);
        }
        $grant->clearCache('PinnedSignatures');
    }

    private function createMember(Grant $grant, MemberValues $member, Actor $actor): GrantMember
    {
        /** @var GrantMember $row */
        $row = $this->em()->create('Cav7\Citations:GrantMember');
        $row->bulkSet([
            'grant_id' => $grant->grant_id,
            'relation_id' => $member->relationId,
            'url_token' => self::token(),
            'add_user_id' => $actor->userId,
        ]);
        $row->takeValues($member);
        $row->save(true, false);

        return $row;
    }

    /** @param array<string, mixed> $detail */
    private function log(Grant $grant, string $event, Actor $actor, string $reason, array $detail = [], ?int $grantMemberId = null): void
    {
        /** @var GrantLog $log */
        $log = $this->em()->create('Cav7\Citations:GrantLog');
        $log->bulkSet([
            'grant_id' => $grant->grant_id,
            'event' => $event,
            'grant_member_id' => $grantMemberId,
            'user_id' => $actor->userId,
            'username' => $actor->username,
            'reason' => trim($reason),
            'revision_after' => $grant->revision,
            'detail' => $detail,
        ]);
        $log->save(true, false);
    }

    private function announce(Grant $grant, string $change): void
    {
        $this->app->fire(self::EVENT, [$grant, $change]);
    }

    private function plain(?string $text): ?string
    {
        return $text === null ? null : $this->app->stringFormatter()->stripBbCode($text);
    }

    private function render(): GrantRender
    {
        return $this->render ??= GrantRender::forApp($this->app);
    }

    private function repo(): GrantRepository
    {
        return $this->em()->getRepository('Cav7\Citations:Grant');
    }

    /** The same form as a blob's token, and it fits the URL: no dot. */
    private static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** @param list<array{field: string, rule: string, params: array<string, string|int>}> $problems */
    private static function refuse(array $problems): void
    {
        if ($problems !== []) {
            throw new PrintableException(self::messages($problems));
        }
    }

    /**
     * @param list<array{field: string, rule: string, params: array<string, string|int>}> $problems
     * @return list<Phrase>
     */
    public static function messages(array $problems): array
    {
        $billets = \XF::em()->findByIds('Cav7\Citations:SigningBillet', array_filter(array_map(
            fn (array $problem): ?int => $problem['params']['billet_id'] ?? null,
            $problems
        )));

        return array_map(function (array $problem) use ($billets): Phrase {
            $params = ['field' => $problem['field']] + $problem['params'];
            if (isset($params['billet_id'])) {
                $params['billet'] = $billets[$params['billet_id']]->title ?? '';
            }
            return \XF::phrase("cav7_cit_refused_{$problem['rule']}", $params);
        }, $problems);
    }
}
