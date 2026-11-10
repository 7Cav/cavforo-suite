<?php

namespace Cav7\Citations\Service;

use Cav7\Citations\Grant\LinkedRows;
use Cav7\Citations\Grant\RowClearer;
use Cav7\Citations\Grant\RowSaveRules;
use Cav7\Citations\Render\FieldDependencies;
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
        bool $clears,
        int $pick,
        bool $cutover,
    ): ?Phrase {
        $row = $recordId !== 0 ? $this->row($kind, $recordId) : null;
        $picked = $pick !== 0 ? $this->em()->find('Cav7\Citations:GrantMember', $pick) : null;

        $rule = RowSaveRules::refusal(
            $kind,
            $recordTypeId,
            (bool) $row?->hasGrantLink(),
            $row?->citation_regiment_grant_id !== null,
            $row?->relation_id ?? $urlMilpac,
            $hasFile,
            $clears,
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
        $grantId = $this->pickGrant($grantMemberId);

        $this->db()->executeTransaction(function () use ($row, $grantMemberId, $grantId): void {
            $milpac = $this->lockPick($grantId, $grantMemberId);

            $rule = RowSaveRules::pickRefusal($milpac, (int) $row->relation_id);
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

    /** Whether a save with this record id makes a row: none, or one that has gone. */
    public function makesRow(RowKind $kind, int $recordId): bool
    {
        return $recordId === 0 || $this->row($kind, $recordId) === null;
    }

    /**
     * Runs a save that makes a row in one transaction with the pick locked, so a refused attach
     * inside it takes the new row back too.
     *
     * @template T
     * @param \Closure(): T $save
     * @return T
     */
    public function holdingPick(int $grantMemberId, \Closure $save): mixed
    {
        $grantId = $this->pickGrant($grantMemberId);

        return $this->db()->executeTransaction(function () use ($grantId, $grantMemberId, $save): mixed {
            $this->lockPick($grantId, $grantMemberId);

            return $save();
        });
    }

    /** The grant member a row holds, null for a new, missing or unlinked row. */
    public function heldPick(RowKind $kind, int $recordId): ?int
    {
        return $recordId !== 0 ? $this->row($kind, $recordId)?->citation_grant_member_id : null;
    }

    /**
     * Lists rows by a locking read of the link itself, so a row swapped to another citation
     * since the removal page loaded is left alone. Call it before any plain read in the
     * transaction: MariaDB fails a locking read of a row changed since the first one.
     */
    public function clear(int $grantMemberId): array
    {
        $held = [];
        foreach (RowKind::cases() as $kind) {
            $locked = $this->db()->fetchAll(
                "SELECT record_id, citation_date, citation_blob_id, citation_grant_member_id, citation_regiment_grant_id
                FROM {$kind->table()} WHERE citation_grant_member_id = ? FOR UPDATE",
                $grantMemberId
            );
            foreach ($locked as $row) {
                $held[$kind->value][(int) $row['record_id']] = $row;
            }
        }

        $rows = array_values(array_filter(
            $this->linkedRows()->onMember($grantMemberId),
            fn (array $row): bool => isset($held[$row['row_type']][$row['record_id']])
        ));
        foreach ($rows as $row) {
            $kind = RowKind::from($row['row_type']);
            $entity = $this->rowOrRefuse($kind, $row['record_id']);
            foreach ($held[$kind->value][$row['record_id']] as $column => $value) {
                $entity->setAsSaved($column, $value);
            }
            $this->service(match ($kind) {
                RowKind::Award => 'NF\Rosters:AwardRecord\Image',
                RowKind::Record => 'NF\Rosters:ServiceRecord\Image',
            }, $entity)->deleteImage();
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
        $members = $this->em()->findByIds('Cav7\Citations:GrantMember', array_column($entries, 'grant_member_id'), 'Grant.Version');

        return array_map(function (array $entry) use ($members): array {
            $member = $members[$entry['grant_member_id']];

            return $entry + [
                'day' => $member->Grant->getDay(),
                'generic' => FieldDependencies::generic($member->Grant->Version->renderVersion()),
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

    /** Read before the transaction begins, so lockPick() can come first in it. */
    private function pickGrant(int $grantMemberId): int
    {
        return (int) $this->db()->fetchOne('SELECT grant_id FROM xf_cav7_citation_grant_member WHERE grant_member_id = ?', $grantMemberId);
    }

    /**
     * Grant row, then member row, the order removal locks in, so a member can't go mid-attach
     * and leave a link to nothing. Call it first in the transaction: MariaDB fails a locking
     * read of a row changed since the transaction's first plain read.
     *
     * @return int|null The member's milpac, null once they've gone.
     */
    private function lockPick(int $grantId, int $grantMemberId): ?int
    {
        $this->db()->fetchOne('SELECT grant_id FROM xf_cav7_citation_grant WHERE grant_id = ? FOR UPDATE', $grantId);
        $milpac = $this->db()->fetchOne(
            'SELECT relation_id FROM xf_cav7_citation_grant_member WHERE grant_member_id = ? FOR UPDATE',
            $grantMemberId
        );

        return $milpac === false ? null : (int) $milpac;
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
