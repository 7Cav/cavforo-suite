<?php

namespace Cav7\Citations\Grant;

/** A log entry's "what changed", as phrases to print. Events are the values GrantLog holds. */
final class LogSummary
{
    /**
     * @param array<string, mixed> $detail
     * @param array<int, string> $names Member names by grant member id, for changes to a member.
     * @return list<array{string, array<string, string>}> Phrase name and its params, one per thing changed.
     */
    public static function of(string $event, array $detail, array $names): array
    {
        return match ($event) {
            'corrected' => self::changes($detail['changes'] ?? [], $names),
            'member_added' => [['cav7_cit_log_member_added', ['name' => (string) ($detail['name'] ?? '')]]],
            'member_removed' => [
                ['cav7_cit_log_member_removed', ['name' => (string) ($detail['name'] ?? '')]],
                ...self::cleared($detail['rows'] ?? []),
            ],
            'retypeset' => [[empty($detail['unchanged']) ? 'cav7_cit_log_retypeset' : 'cav7_cit_log_retypeset_unchanged', []]],
            'withdrawn' => [['cav7_cit_log_withdrawn', []]],
            default => [['cav7_cit_log_puc_sync', []]],
        };
    }

    /**
     * The rows a removal cleared, so S1 can reattach them after a mistaken one.
     *
     * @param list<array{row_type: string, kind_title: string, row_date: int}> $rows
     * @return list<array{string, array<string, string>}>
     */
    private static function cleared(array $rows): array
    {
        return array_map(fn (array $row): array => ["cav7_cit_log_cleared_{$row['row_type']}", [
            'title' => (string) $row['kind_title'],
            'date' => gmdate('Y-m-d', (int) $row['row_date']),
        ]], $rows);
    }

    /**
     * @param list<array{what: string, member?: int}> $changes
     * @param array<int, string> $names
     * @return list<array{string, array<string, string>}>
     */
    private static function changes(array $changes, array $names): array
    {
        $lines = [];
        foreach ($changes as $change) {
            [$what] = explode(':', $change['what'], 2);
            $member = $change['member'] ?? null;

            $line = $member === null
                ? ["cav7_cit_log_$what", []]
                : ["cav7_cit_log_member_$what", ['name' => $names[$member] ?? "#$member"]];

            // Two inputs changing read as one "Template inputs", as two signatures do.
            if (!in_array($line, $lines, true)) {
                $lines[] = $line;
            }
        }
        return $lines;
    }
}
