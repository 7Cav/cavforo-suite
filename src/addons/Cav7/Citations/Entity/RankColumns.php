<?php

namespace Cav7\Citations\Entity;

use Cav7\Citations\Render\RankSnapshot;
use XF\Mvc\Entity\Entity;

/**
 * The four snapshot columns a grant and a grant member both carry. Written once when a rank
 * is picked, so a later edit to the pay grade list or a vendor rename never reaches them.
 *
 * @property int|null $rank_id
 * @property string|null $rank_title
 * @property string|null $rank_pay_grade
 * @property string|null $rank_abbreviation
 */
trait RankColumns
{
    private function rankSnapshot(): ?RankSnapshot
    {
        return $this->rank_title === null
            ? null
            : new RankSnapshot($this->rank_title, (string) $this->rank_pay_grade, (string) $this->rank_abbreviation);
    }

    private function takeRank(?int $rankId, ?RankSnapshot $rank): void
    {
        $this->bulkSet([
            'rank_id' => $rankId,
            'rank_title' => $rank?->rank_title,
            'rank_pay_grade' => $rank?->rank_pay_grade,
            'rank_abbreviation' => $rank?->rank_abbreviation,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private static function rankColumns(): array
    {
        return [
            'rank_id' => ['type' => Entity::UINT, 'nullable' => true, 'default' => null],
            'rank_title' => ['type' => Entity::STR, 'nullable' => true, 'default' => null, 'maxLength' => 50],
            'rank_pay_grade' => ['type' => Entity::STR, 'nullable' => true, 'default' => null, 'maxLength' => 10],
            'rank_abbreviation' => ['type' => Entity::STR, 'nullable' => true, 'default' => null, 'maxLength' => 10],
        ];
    }
}
