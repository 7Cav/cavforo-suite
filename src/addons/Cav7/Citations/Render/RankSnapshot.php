<?php

namespace Cav7\Citations\Render;

/** The snapshot's printed columns. `rank_id` only picks rank art, which arrives on Values. */
final class RankSnapshot
{
    public function __construct(
        public readonly string $rank_title,
        public readonly string $rank_pay_grade,
        public readonly string $rank_abbreviation,
    ) {
    }
}
