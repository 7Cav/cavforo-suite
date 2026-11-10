<?php

namespace Cav7\EnlistmentDefaults;

/**
 * What the PUC sync writes on one milpac. Never adopts an unlinked row: once the strip has
 * run, a PUC row with no grant link can only have been added by hand.
 */
final class PucPlan
{
    /**
     * @param list<int> $create Grant ids, one new row each.
     * @param array<int, int> $reset Grant id by record id.
     * @param array<int, int|null> $delete Grant id by record id, null for an unlinked row.
     */
    private function __construct(
        public readonly array $create,
        public readonly array $reset,
        public readonly array $delete,
    ) {}

    /**
     * @param array<int, array{award_id: int, grant_date: int}> $grants Live regiment grants by grant id.
     * @param list<int> $awardIds From awardIds().
     * @param list<array{record_id: int, award_id: int, award_date: int, details: string, citation_regiment_grant_id: int|null}> $rows
     */
    public static function for(array $grants, array $awardIds, array $rows): self
    {
        usort($rows, fn (array $a, array $b): int => $a['record_id'] <=> $b['record_id']);

        $linked = [];
        $delete = [];
        foreach ($rows as $row) {
            $grantId = $row['citation_regiment_grant_id'];
            if ($grantId !== null && isset($grants[$grantId])) {
                $linked[$grantId][] = $row;
            } elseif ($grantId !== null) {
                $delete[$row['record_id']] = $grantId;
            } elseif (in_array($row['award_id'], $awardIds, true)) {
                $delete[$row['record_id']] = null;
            }
        }

        $create = [];
        $reset = [];
        foreach ($grants as $grantId => $grant) {
            $held = $linked[$grantId] ?? [];
            $kept = array_shift($held);
            if ($kept === null) {
                $create[] = $grantId;
                continue;
            }
            if ($kept['award_id'] !== $grant['award_id'] || $kept['award_date'] !== $grant['grant_date'] || $kept['details'] !== '') {
                $reset[$kept['record_id']] = $grantId;
            }
            foreach ($held as $duplicate) {
                $delete[$duplicate['record_id']] = $grantId;
            }
        }

        return new self($create, $reset, $delete);
    }

    /**
     * The awards whose rows are PUC rows: the regiment awards option and every live grant's award.
     *
     * @param list<int> $optionAwardIds
     * @param array<int, array{award_id: int, grant_date: int}> $grants
     * @return list<int>
     */
    public static function awardIds(array $optionAwardIds, array $grants): array
    {
        return array_values(array_unique([...$optionAwardIds, ...array_column($grants, 'award_id')]));
    }

    /**
     * Runs each write and counts the ones that land. A write that throws goes to $failed,
     * uncounted, and the rest still run.
     *
     * @param \Closure(int $grantId): void $create
     * @param \Closure(int $recordId, int $grantId): void $reset
     * @param \Closure(int $recordId): void $delete
     * @param \Closure(\Throwable, string): void $failed
     */
    public function run(PucCounts $counts, \Closure $create, \Closure $reset, \Closure $delete, \Closure $failed): void
    {
        foreach ($this->delete as $recordId => $grantId) {
            self::attempt(fn () => $delete($recordId), fn () => $counts->add($grantId, 'removed'), "failed to delete PUC row $recordId", $failed);
        }
        foreach ($this->reset as $recordId => $grantId) {
            self::attempt(fn () => $reset($recordId, $grantId), fn () => $counts->add($grantId, 'reset'), "failed to reset PUC row $recordId", $failed);
        }
        foreach ($this->create as $grantId) {
            self::attempt(fn () => $create($grantId), fn () => $counts->add($grantId, 'added'), "failed to add the PUC row for regiment grant $grantId", $failed);
        }
    }

    private static function attempt(\Closure $write, \Closure $count, string $context, \Closure $failed): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            $failed($e, $context);
            return;
        }
        $count();
    }
}
