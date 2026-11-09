<?php

namespace Cav7\Citations\Repository;

use Cav7\Citations\Entity\GrantLog;
use Cav7\Citations\Entity\Signature;
use Cav7\Citations\Grant\Actor;
use Cav7\Citations\Grant\GrantValues;
use Cav7\Citations\Render\PinnedSignature;
use Cav7\Citations\Render\RankSnapshot;
use XF\Entity\User;
use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

/** Assumes NF/Rosters keeps award and record type titles in `title`, as NF\Rosters:Award and :RecordType. */
class Grant extends Repository
{
    private const LISTED_MEMBERS = 3;

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
     * Grants of one type, newest first.
     *
     * @param array{string, int}|null $kind
     * @param int|null $day Midnight UTC.
     * @param int|null $relationId Only grants this milpac is on.
     */
    public function listed(bool $regiment, ?array $kind, ?int $day, ?int $relationId): Finder
    {
        $finder = $this->finder('Cav7\Citations:Grant')
            ->where('is_regiment', $regiment)
            ->with('Version.Template')
            ->order([['grant_date', 'DESC'], ['grant_id', 'DESC']]);

        if ($kind !== null) {
            $finder->where(['kind_type' => $kind[0], 'kind_id' => $kind[1]]);
        }
        if ($day !== null) {
            $finder->where('grant_date', $day);
        }
        if ($relationId !== null) {
            $finder->where('grant_id', $this->db()->fetchAllColumn(
                'SELECT grant_id FROM xf_cav7_citation_grant_member WHERE relation_id = ?',
                $relationId
            ));
        }
        return $finder;
    }

    /** @return array<string, string> Kind titles by `award:<id>` or `record_type:<id>`, what the list can filter on. */
    public function issuedKinds(): array
    {
        return $this->db()->fetchPairs(
            "SELECT DISTINCT CONCAT(kind_type, ':', kind_id), kind_title FROM xf_cav7_citation_grant ORDER BY kind_title"
        );
    }

    /**
     * The list's members column: the first few usernames, and how many more. The snapshot name
     * stands in where a milpac has gone.
     *
     * @param list<int> $grantIds
     * @return array<int, string> By grant.
     */
    public function memberSummaries(array $grantIds): array
    {
        if ($grantIds === []) {
            return [];
        }

        $rows = $this->db()->fetchAll(
            'SELECT m.grant_id, COALESCE(u.username, m.name) AS name
            FROM xf_cav7_citation_grant_member AS m
            LEFT JOIN xf_nf_rosters_user AS r ON r.relation_id = m.relation_id
            LEFT JOIN xf_user AS u ON u.user_id = r.user_id
            WHERE m.grant_id IN (' . $this->db()->quote($grantIds) . ')
            ORDER BY m.grant_member_id'
        );

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['grant_id']][] = $row['name'];
        }

        return array_map(function (array $all): string {
            $shown = implode(', ', array_slice($all, 0, self::LISTED_MEMBERS));

            return count($all) > self::LISTED_MEMBERS ? $shown . ', +' . (count($all) - self::LISTED_MEMBERS) : $shown;
        }, $names);
    }

    /**
     * A post names members by relation id, so a crafted one can name a milpac that isn't there.
     *
     * @param list<int> $relationIds
     * @return list<int> The ones with a milpac.
     */
    public function milpacs(array $relationIds): array
    {
        return $relationIds === [] ? [] : array_map('intval', $this->db()->fetchAllColumn(
            'SELECT relation_id FROM xf_nf_rosters_user WHERE relation_id IN (' . $this->db()->quote($relationIds) . ')'
        ));
    }

    /**
     * The list's corrections column. A retypeset moves the revision too, so the revision alone
     * can't say whether a grant was corrected.
     *
     * @param list<int> $grantIds
     * @return array<int, int> The revision the latest correction made, by grant, for the ones corrected.
     */
    public function lastCorrections(array $grantIds): array
    {
        if ($grantIds === []) {
            return [];
        }

        return array_map('intval', $this->db()->fetchPairs(
            'SELECT grant_id, MAX(revision_after) FROM xf_cav7_citation_grant_log
            WHERE event = ? AND grant_id IN (' . $this->db()->quote($grantIds) . ')
            GROUP BY grant_id',
            GrantLog::CORRECTED
        ));
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
