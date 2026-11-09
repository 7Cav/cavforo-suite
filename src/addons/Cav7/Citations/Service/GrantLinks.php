<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Grant\LinkedRows;
use Cav7\Citations\Grant\RowClearer;
use Cav7\Citations\Grant\RowSaveRules;
use Cav7\Citations\Storage\RowKind;
use XF\Mvc\Entity\Entity;
use XF\Phrase;
use XF\PrintableException;
use XF\Service\AbstractService;

/**
 * Grant links on roster rows: the check a row save runs, attaching, swapping and removing.
 * The caller checks permissions, and passes the cutover option where it's wanted, so nothing
 * here reads it.
 *
 * Assumes the vendor's image services take the row, and that deleteImage() zeroes
 * citation_date and saves through the entity, which is what clears the link. Removal from a
 * member and the "Delete current image" checkbox are that one path, and it also deletes a
 * restored legacy file. Re-check on a dev stack after a vendor upgrade.
 */
class GrantLinks extends AbstractService implements RowClearer
{
    /**
     * Runs before the vendor's save, so a refusal writes no row and no file. The row is found
     * the way the vendor finds it, by record_id alone, and otherwise a new row lands on the
     * URL's milpac, so that's the milpac a pick is held to.
     *
     * @param int $pick A grant member id, 0 for none.
     */
    public function saveRefusal(
        RowKind $kind,
        int $recordId,
        int $urlMilpac,
        int $recordTypeId,
        bool $hasFile,
        int $pick,
        bool $cutover,
    ): ?Phrase {
        $row = $recordId !== 0 ? $this->row($kind, $recordId) : null;
        $picked = $pick !== 0 ? $this->em()->find('Cav7\Citations:GrantMember', $pick) : null;

        $rule = RowSaveRules::refusal(
            $kind,
            $recordTypeId,
            (bool) $row?->hasGrantLink(),
            $row?->relation_id ?? $urlMilpac,
            $hasFile,
            $pick,
            $picked?->relation_id,
            $cutover,
        );

        return $rule === null ? null : \XF::phrase("cav7_cit_refused_$rule");
    }

    /**
     * Also swaps, and attaching what the row holds does nothing. The pick is checked against
     * the row's own milpac, whatever URL the save came in on.
     *
     * @throws PrintableException
     */
    public function attach(RowKind $kind, int $recordId, int $grantMemberId): void
    {
        $row = $this->rowOrRefuse($kind, $recordId);

        $this->db()->executeTransaction(function () use ($row, $grantMemberId): void {
            // The grant's row lock is the one removal holds, so a member can't go mid-attach
            // and leave a link to nothing.
            $milpac = $this->db()->fetchOne(
                'SELECT m.relation_id
                FROM xf_cav7_citation_grant_member AS m
                INNER JOIN xf_cav7_citation_grant AS g ON g.grant_id = m.grant_id
                WHERE m.grant_member_id = ?
                FOR UPDATE',
                $grantMemberId
            );

            $rule = RowSaveRules::pickRefusal($milpac === false ? null : (int) $milpac, (int) $row->relation_id);
            if ($rule !== null) {
                throw new PrintableException(\XF::phrase("cav7_cit_refused_$rule"));
            }

            $values = RowSaveRules::attachValues($row->citation_grant_member_id, $grantMemberId, \XF::$time);
            if ($values !== null) {
                $row->bulkSet($values);
                $row->save(true, false);
            }
        });
    }

    /** The grant member a row holds, null for a new, missing or unlinked row. */
    public function heldPick(RowKind $kind, int $recordId): ?int
    {
        return $recordId !== 0 ? $this->row($kind, $recordId)?->citation_grant_member_id : null;
    }

    /** @throws PrintableException */
    public function remove(RowKind $kind, int $recordId): void
    {
        $service = match ($kind) {
            RowKind::Award => 'NF\Rosters:AwardRecord\Image',
            RowKind::Record => 'NF\Rosters:ServiceRecord\Image',
        };

        $this->service($service, $this->rowOrRefuse($kind, $recordId))->deleteImage();
    }

    public function clear(int $grantMemberId): array
    {
        $rows = $this->linkedRows()->onMember($grantMemberId);
        foreach ($rows as $row) {
            $this->remove(RowKind::from($row['row_type']), $row['record_id']);
        }

        return $rows;
    }

    /**
     * The picker's entries with what the form prints beside each.
     *
     * @see LinkedRows::picker()
     * @return list<array<string, mixed>>
     */
    public function picker(int $relationId): array
    {
        $entries = $this->linkedRows()->picker($relationId);
        $members = $this->em()->findByIds('Cav7\Citations:GrantMember', array_column($entries, 'grant_member_id'), 'Grant');

        return array_map(function (array $entry) use ($members): array {
            $member = $members[$entry['grant_member_id']];

            return $entry + [
                'day' => $member->Grant->getDay(),
                'generic' => $member->Grant->citation_text === null,
                'render_url' => $member->renderUrl(),
                'row_labels' => $this->labels($entry['rows']),
            ];
        }, $entries);
    }

    /**
     * The rows holding this member's citation, as a removal lists them.
     *
     * @return list<Phrase>
     */
    public function rowLabels(int $grantMemberId): array
    {
        return $this->labels($this->linkedRows()->onMember($grantMemberId));
    }

    /**
     * @param list<array<string, int|string>> $rows
     * @return list<Phrase>
     */
    private function labels(array $rows): array
    {
        return array_map(fn (array $row): Phrase => \XF::phrase("cav7_cit_row_{$row['row_type']}", [
            'title' => $row['kind_title'],
            'date' => gmdate('Y-m-d', $row['row_date']),
        ]), $rows);
    }

    private function linkedRows(): LinkedRows
    {
        return new LinkedRows($this->db());
    }

    private function row(RowKind $kind, int $recordId): ?Entity
    {
        return $this->em()->find(match ($kind) {
            RowKind::Award => 'NF\Rosters:RosterUserAward',
            RowKind::Record => 'NF\Rosters:ServiceRecord',
        }, $recordId);
    }

    /** @throws PrintableException */
    private function rowOrRefuse(RowKind $kind, int $recordId): Entity
    {
        return $this->row($kind, $recordId) ?? throw new PrintableException(\XF::phrase('cav7_cit_refused_no_such_row'));
    }
}
