<?php

namespace Cav7\Citations\Repository;

use XF\Mvc\Entity\Repository;

/** Assumes NF/Rosters keeps its ranks in xf_nf_rosters_rank with `title` and `display_order`. */
class PayGrade extends Repository
{
    /**
     * Every NF/Rosters rank, so a new rank shows blank until S1 fills it in.
     *
     * @return list<array{rank_id: int|string, title: string, pay_grade: string, abbreviation: string}>
     */
    public function list(): array
    {
        return $this->db()->fetchAll(
            "SELECT r.rank_id, r.title, COALESCE(g.pay_grade, '') AS pay_grade, COALESCE(g.abbreviation, '') AS abbreviation
            FROM xf_nf_rosters_rank AS r
            LEFT JOIN xf_cav7_citation_pay_grade AS g ON g.rank_id = r.rank_id
            ORDER BY r.display_order, r.rank_id"
        );
    }

    public function set(int $rankId, string $payGrade, string $abbreviation): void
    {
        $row = $this->em->find('Cav7\Citations:PayGrade', $rankId) ?? $this->em->create('Cav7\Citations:PayGrade');
        $row->bulkSet(['rank_id' => $rankId, 'pay_grade' => $payGrade, 'abbreviation' => $abbreviation]);
        $row->save();
    }
}
