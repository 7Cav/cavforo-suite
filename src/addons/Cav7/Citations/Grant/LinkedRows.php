<?php

namespace Cav7\Citations\Grant;

use Cav7\Citations\Storage\RowKind;
use XF\Db\AbstractAdapter;

/** Reads rows through their grant link. Assumes NF/Rosters keeps kind titles in `title`, and a deleted kind reads as ''. */
final class LinkedRows
{
    public function __construct(private AbstractAdapter $db)
    {
    }

    /** @return list<array{row_type: string, record_id: int, relation_id: int, kind_title: string, row_date: int}> */
    public function onMember(int $grantMemberId): array
    {
        return $this->grouped('r.citation_grant_member_id = ?', $grantMemberId)[$grantMemberId] ?? [];
    }

    /**
     * Every citation issued to this milpac, on rows or not, newest first. A regiment grant
     * has no grant members, so it's never here.
     *
     * @return list<array{grant_member_id: int, kind_title: string, grant_date: int, rows: list<array<string, int|string>>}>
     */
    public function picker(int $relationId): array
    {
        $rows = $this->grouped(
            'r.citation_grant_member_id IN (SELECT grant_member_id FROM xf_cav7_citation_grant_member WHERE relation_id = ?)',
            $relationId
        );

        $entries = $this->db->fetchAll(
            'SELECT m.grant_member_id, g.kind_title, g.grant_date
            FROM xf_cav7_citation_grant_member AS m
            INNER JOIN xf_cav7_citation_grant AS g ON g.grant_id = m.grant_id
            WHERE m.relation_id = ?
            ORDER BY g.grant_date DESC, g.grant_id DESC',
            [$relationId]
        );

        return array_map(fn (array $entry): array => [
            'grant_member_id' => (int) $entry['grant_member_id'],
            'kind_title' => $entry['kind_title'],
            'grant_date' => (int) $entry['grant_date'],
            'rows' => $rows[(int) $entry['grant_member_id']] ?? [],
        ], $entries);
    }

    /** @return array<int, list<array<string, int|string>>> By grant member id. */
    private function grouped(string $where, int $param): array
    {
        $grouped = [];
        foreach (RowKind::cases() as $kind) {
            [$kindTable, $kindKey, $dateColumn] = match ($kind) {
                RowKind::Award => ['xf_nf_rosters_award', 'award_id', 'award_date'],
                RowKind::Record => ['xf_nf_rosters_record_type', 'record_type_id', 'record_date'],
            };

            $rows = $this->db->fetchAll(
                "SELECT r.citation_grant_member_id, r.record_id, r.relation_id, r.$dateColumn AS row_date,
                    COALESCE(k.title, '') AS kind_title
                FROM {$kind->table()} AS r
                LEFT JOIN $kindTable AS k ON k.$kindKey = r.$kindKey
                WHERE $where
                ORDER BY r.record_id",
                [$param]
            );

            foreach ($rows as $row) {
                $grouped[(int) $row['citation_grant_member_id']][] = [
                    'row_type' => $kind->value,
                    'record_id' => (int) $row['record_id'],
                    'relation_id' => (int) $row['relation_id'],
                    'kind_title' => $row['kind_title'],
                    'row_date' => (int) $row['row_date'],
                ];
            }
        }

        return $grouped;
    }
}
