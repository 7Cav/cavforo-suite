<?php

namespace Cav7\EnlistmentDefaults;

use Cav7\Citations\Repository\Grant as GrantRepository;
use Cav7\Citations\Service\Grants;
use Cav7\RosterAudit\AuditPause;
use NF\Rosters\Entity\RosterUser;
use NF\Rosters\Entity\RosterUserAward;
use XF\App;

/**
 * Plans milpacs' PUC rows with PucPlan and writes the plans through entities, so the vendor's
 * _postDelete takes a row's legacy file and Cav7/RosterPatch's date floor runs on save.
 *
 * Assumes NF/Rosters keeps milpacs in xf_nf_rosters_user and award rows in
 * xf_nf_rosters_user_award by relation_id, and that RosterUser::getNewAward() makes a row.
 * After a vendor upgrade, re-check on a dev stack that a new milpac gets one linked row per
 * live regiment grant, and that the strip takes the old PUC rows' citation files with them.
 */
final class PucSync
{
    private const BATCH = 200;

    public int $failures = 0;

    /**
     * @param array<int, array{award_id: int, grant_date: int}> $grants
     * @param list<int> $awardIds
     */
    private function __construct(
        private readonly App $app,
        private readonly array $grants,
        private readonly array $awardIds,
        public readonly PucCounts $counts,
    ) {}

    public static function forApp(App $app, PucCounts $counts = new PucCounts()): self
    {
        /** @var GrantRepository $repo */
        $repo = $app->repository(GrantRepository::class);
        $grants = $repo->liveRegimentGrants();

        return new self($app, $grants, PucPlan::awardIds($repo->regimentAwardIds(), $grants), $counts);
    }

    public static function isOn(): bool
    {
        return (bool) \XF::options()->cav7EnlistDefPucSync;
    }

    /**
     * The daily cron entry and the regiment grant listener both call this. Queueing replaces a
     * queued walk's data, so the restart carries the counts that walk hasn't logged yet.
     */
    public static function enqueue(): void
    {
        if (!self::isOn()) {
            return;
        }
        $jobs = \XF::app()->jobManager();
        $queued = $jobs->getUniqueJob('cav7EnlistDefPucSync');
        $counts = $queued ? (unserialize($queued['execute_data'])['counts'] ?? []) : [];
        $jobs->enqueueUnique('cav7EnlistDefPucSync', Job\PucSync::class, ['counts' => $counts], false);
    }

    public function hasLiveGrants(): bool
    {
        return $this->grants !== [];
    }

    public function isPucRow(int $awardId, ?int $regimentGrantId): bool
    {
        return $regimentGrantId !== null || in_array($awardId, $this->awardIds, true);
    }

    /**
     * One new milpac, written in the open, so Cav7/RosterAudit logs its rows as it logs the
     * rest of the milpac.
     *
     * @param \Closure(\Throwable, string): void $failed
     */
    public function milpac(RosterUser $rosterUser, int $fromUserId, \Closure $failed): void
    {
        $relationId = (int) $rosterUser->relation_id;
        $plan = PucPlan::for($this->grants, $this->awardIds, $this->rows([$relationId])[$relationId] ?? []);
        $this->write(
            [$relationId => $plan],
            [$relationId => $rosterUser],
            $fromUserId,
            fn (int $relationId, \Throwable $e, string $context) => $failed($e, $context),
        );
    }

    /** @return list<int> The next milpacs on any roster after $after, by relation id. */
    public function nextMilpacs(int $after): array
    {
        return array_map('intval', $this->app->db()->fetchAllColumn(
            'SELECT relation_id FROM xf_nf_rosters_user WHERE relation_id > ? ORDER BY relation_id LIMIT ' . self::BATCH,
            $after
        ));
    }

    /**
     * Writes out of the audit log, since the grant log records the counts. Rows are attributed
     * to the system user: a job runs in whichever visitor's request picks it up.
     *
     * @param list<int> $relationIds
     */
    public function batch(array $relationIds, bool $dryRun): void
    {
        $rows = $this->rows($relationIds);
        $plans = [];
        foreach ($relationIds as $relationId) {
            $plans[$relationId] = PucPlan::for($this->grants, $this->awardIds, $rows[$relationId] ?? []);
        }

        if ($dryRun) {
            $none = function (): void {
            };
            foreach ($plans as $plan) {
                $plan->run($this->counts, $none, $none, $none, $none);
            }
            return;
        }

        $em = $this->app->em();
        $milpacs = $em->findByIds('NF\Rosters:RosterUser', array_keys(array_filter($plans, fn (PucPlan $plan): bool => $plan->create !== [])));

        AuditPause::during(fn () => $this->write(
            $plans,
            $milpacs->toArray(),
            (int) $this->app->options()->cav7EnlistDefSystemUserId,
            function (int $relationId, \Throwable $e, string $context): void {
                $this->failures++;
                \XF::logException($e, false, "Cav7/EnlistmentDefaults: PUC sync on milpac $relationId: $context: ");
            },
        ));

        $em->clearEntityCache('NF\Rosters:RosterUserAward');
        $em->clearEntityCache('NF\Rosters:RosterUser');
    }

    /** One grant that can't take its log, such as a deleted one, doesn't cost the others theirs. */
    public function logOnGrants(): void
    {
        /** @var Grants $grants */
        $grants = $this->app->service(Grants::class);
        foreach ($this->counts->grants as $grantId => $counts) {
            try {
                $grants->logPucSync($grantId, $counts);
            } catch (\Throwable $e) {
                \XF::logException($e, false, "Cav7/EnlistmentDefaults: PUC sync could not log on regiment grant $grantId: ");
            }
        }
    }

    /**
     * @param array<int, PucPlan> $plans By relation id.
     * @param array<int, RosterUser> $milpacs By relation id, for the plans that create.
     * @param \Closure(int $relationId, \Throwable, string): void $failed
     */
    private function write(array $plans, array $milpacs, int $fromUserId, \Closure $failed): void
    {
        $recordIds = [];
        foreach ($plans as $plan) {
            array_push($recordIds, ...array_keys($plan->reset), ...array_keys($plan->delete));
        }
        $awardRows = $this->app->em()->findByIds('NF\Rosters:RosterUserAward', $recordIds);

        foreach ($plans as $relationId => $plan) {
            $plan->run(
                $this->counts,
                function (int $grantId) use ($milpacs, $relationId, $fromUserId): void {
                    /** @var RosterUserAward $row */
                    $row = $milpacs[$relationId]->getNewAward();
                    $row->bulkSet($this->fields($grantId) + ['from_user_id' => $fromUserId]);
                    $row->linkRegimentGrant($grantId);
                    $row->save();
                },
                function (int $recordId, int $grantId) use ($awardRows): void {
                    $row = $awardRows[$recordId];
                    $row->bulkSet($this->fields($grantId));
                    $row->save();
                },
                fn (int $recordId) => $awardRows[$recordId]->delete(),
                fn (\Throwable $e, string $context) => $failed($relationId, $e, $context),
            );
        }
    }

    /** @return array{award_id: int, award_date: int, details: string} */
    private function fields(int $grantId): array
    {
        return ['award_id' => $this->grants[$grantId]['award_id'], 'award_date' => $this->grants[$grantId]['grant_date'], 'details' => ''];
    }

    /**
     * Each milpac's rows of a PUC award or with a regiment link.
     *
     * @param list<int> $relationIds
     * @return array<int, list<array{record_id: int, award_id: int, award_date: int, details: string, citation_regiment_grant_id: int|null}>>
     */
    private function rows(array $relationIds): array
    {
        if ($relationIds === []) {
            return [];
        }

        $db = $this->app->db();
        $puc = $this->awardIds === []
            ? 'citation_regiment_grant_id IS NOT NULL'
            : 'award_id IN (' . $db->quote($this->awardIds) . ') OR citation_regiment_grant_id IS NOT NULL';

        $byMilpac = [];
        foreach ($db->fetchAll(
            'SELECT record_id, relation_id, award_id, award_date, details, citation_regiment_grant_id
            FROM xf_nf_rosters_user_award
            WHERE relation_id IN (' . $db->quote($relationIds) . ") AND ($puc)"
        ) as $row) {
            $byMilpac[(int) $row['relation_id']][] = [
                'record_id' => (int) $row['record_id'],
                'award_id' => (int) $row['award_id'],
                'award_date' => (int) $row['award_date'],
                'details' => (string) $row['details'],
                'citation_regiment_grant_id' => $row['citation_regiment_grant_id'] === null ? null : (int) $row['citation_regiment_grant_id'],
            ];
        }
        return $byMilpac;
    }
}
