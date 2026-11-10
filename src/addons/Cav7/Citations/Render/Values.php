<?php

namespace Cav7\Citations\Render;

/** Everything a typesetting reads. The adapter fills it from snapshots, so nothing here looks anything up. */
final class Values
{
    private const MONTHS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /**
     * @param string $date `Y-m-d`
     * @param array<string, string> $inputs Grant and member inputs merged, by key.
     * @param array<string, PinnedSignature> $signatures By slot key.
     * @param array<string, ImageFile> $rankArt The snapshot rank's art, by field key.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?RankSnapshot $rank,
        public readonly string $date,
        public readonly string $citationText,
        public readonly array $inputs = [],
        public readonly array $signatures = [],
        public readonly array $rankArt = [],
    ) {
    }

    public function placeholder(string $name): string
    {
        return match ($name) {
            'name' => $this->name,
            'rank' => $this->rank?->rank_title ?? '',
            'pay_grade' => $this->rank?->rank_pay_grade ?? '',
            'rank_abbr' => $this->rank?->rank_abbreviation ?? '',
            'day' => (string) $this->dateParts()[2],
            'day_ordinal' => self::ordinal($this->dateParts()[2]),
            'month' => self::MONTHS[$this->dateParts()[1] - 1],
            'year' => sprintf('%04d', $this->dateParts()[0]),
            'citation_text' => $this->citationText,
            default => $this->inputs[substr($name, strlen('input:'))] ?? '',
        };
    }

    /** @return array{int, int, int} */
    private function dateParts(): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $this->date, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new \InvalidArgumentException("Not a Y-m-d date: {$this->date}");
        }
        return [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
    }

    private static function ordinal(int $day): string
    {
        $suffix = $day >= 11 && $day <= 13 ? 'th' : (['st', 'nd', 'rd'][$day % 10 - 1] ?? 'th');
        return $day . $suffix;
    }
}
