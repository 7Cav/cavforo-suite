<?php

namespace Cav7\Citations\Repository;

use Cav7\Citations\Entity\Signature;
use Cav7\Citations\Grant\Actor;
use Cav7\Citations\Grant\GrantValues;
use Cav7\Citations\Render\PinnedSignature;
use Cav7\Citations\Render\RankSnapshot;
use XF\Entity\User;
use XF\Mvc\Entity\Repository;

/** Assumes NF/Rosters keeps award and record type titles in `title`, as NF\Rosters:Award and :RecordType. */
class Grant extends Repository
{
    /** The permission check pages use: `actor(\XF::visitor())->canOpenGrantList()` and so on. */
    public function actor(User $user): Actor
    {
        return new Actor(
            (int) $user->user_id,
            (string) $user->username,
            array_values(array_filter(
                Actor::PERMISSIONS,
                fn (string $permission): bool => $user->hasPermission(Actor::GROUP, $permission)
            )),
        );
    }

    /** @param 'award'|'record_type' $kindType */
    public function kindTitle(string $kindType, int $kindId): ?string
    {
        $kind = $this->em->find($kindType === 'award' ? 'NF\Rosters:Award' : 'NF\Rosters:RecordType', $kindId);

        return $kind?->title;
    }

    /** Fills in the snapshot of any rank just picked: the vendor's title, and the pay grade list as it stands today. */
    public function snapshotted(GrantValues $values): GrantValues
    {
        $pending = $values->pendingRanks();
        if ($pending === []) {
            return $values;
        }

        $rows = $this->db()->fetchAll(
            "SELECT r.rank_id, r.title, COALESCE(g.pay_grade, '') AS pay_grade, COALESCE(g.abbreviation, '') AS abbreviation
            FROM xf_nf_rosters_rank AS r
            LEFT JOIN xf_cav7_citation_pay_grade AS g ON g.rank_id = r.rank_id
            WHERE r.rank_id IN (" . $this->db()->quote($pending) . ')'
        );

        $ranks = [];
        foreach ($rows as $row) {
            $ranks[(int) $row['rank_id']] = new RankSnapshot($row['title'], $row['pay_grade'], $row['abbreviation']);
        }
        return $values->snapshotted($ranks);
    }

    /**
     * @param list<int> $signatureIds
     * @return array<int, int> Billet id by signature id.
     */
    public function billets(array $signatureIds): array
    {
        $billets = [];
        foreach ($this->signatures($signatureIds) as $id => $signature) {
            $billets[$id] = $signature->billet_id;
        }
        return $billets;
    }

    /** @return array<string, PinnedSignature> By slot key. */
    public function pinned(GrantValues $values): array
    {
        $signatures = $this->signatures(array_values($values->signatures));

        $pinned = [];
        foreach ($values->signatures as $slot => $id) {
            if (isset($signatures[$id])) {
                $pinned[$slot] = $signatures[$id]->pinned();
            }
        }
        return $pinned;
    }

    public function liveRegimentExists(int $awardId, int $grantDate, int $exceptGrantId = 0): bool
    {
        return (bool) $this->db()->fetchOne(
            "SELECT 1 FROM xf_cav7_citation_grant
            WHERE is_regiment = 1 AND withdrawn_date = 0 AND kind_type = 'award' AND kind_id = ? AND grant_date = ? AND grant_id <> ?
            LIMIT 1",
            [$awardId, $grantDate, $exceptGrantId]
        );
    }

    /**
     * @param list<int> $ids
     * @return array<int, Signature>
     */
    private function signatures(array $ids): array
    {
        return $ids === []
            ? []
            : $this->finder('Cav7\Citations:Signature')->whereIds($ids)->fetch()->toArray();
    }
}
