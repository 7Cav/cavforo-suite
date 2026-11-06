<?php

namespace Cav7\Citations\Grant;

use Cav7\Citations\Storage\RowKind;

/**
 * What refuses a save on a row, first failure wins. A rule names its phrase.
 *
 * The grant-linked refusal follows the link and ignores the cutover option. The closed-kind
 * refusal follows the kind and needs the option on. The kind checked is the one submitted,
 * so a row refiled in the same save is judged as what it's becoming.
 */
final class RowSaveRules
{
    public const FILE_ON_LINKED_ROW = 'file_on_linked_row';
    public const FILE_ON_CLOSED_KIND = 'file_on_closed_kind';
    public const PICK_BEFORE_CUTOVER = 'pick_before_cutover';
    public const PICK_ON_DISCIPLINARY = 'pick_on_disciplinary';
    public const NO_SUCH_PICK = 'no_such_pick';
    public const PICK_FROM_ANOTHER_MILPAC = 'pick_from_another_milpac';

    // Disciplinary on this install, the one kind that stays open to a file. An id, not an
    // option: reopening another kind is a code change by design.
    public const OPEN_RECORD_TYPE = 4;

    /**
     * @param int $recordTypeId The submitted record type, ignored on an award.
     * @param int $rowMilpac The row's own relation_id, or the URL's for a new row.
     * @param int $pick A grant member id, 0 for none.
     * @param int|null $pickMilpac That grant member's relation_id, null if it's gone.
     */
    public static function refusal(
        RowKind $kind,
        int $recordTypeId,
        bool $rowLinked,
        int $rowMilpac,
        bool $hasFile,
        int $pick,
        ?int $pickMilpac,
        bool $cutover,
    ): ?string {
        $open = $kind === RowKind::Record && $recordTypeId === self::OPEN_RECORD_TYPE;

        return match (true) {
            $hasFile && $rowLinked => self::FILE_ON_LINKED_ROW,
            $hasFile && $cutover && !$open => self::FILE_ON_CLOSED_KIND,
            $pick === 0 => null,
            !$cutover => self::PICK_BEFORE_CUTOVER,
            $open => self::PICK_ON_DISCIPLINARY,
            default => self::pickRefusal($pickMilpac, $rowMilpac),
        };
    }

    public static function pickRefusal(?int $pickMilpac, int $rowMilpac): ?string
    {
        return match ($pickMilpac) {
            null => self::NO_SUCH_PICK,
            $rowMilpac => null,
            default => self::PICK_FROM_ANOTHER_MILPAC,
        };
    }

    /**
     * Attaching onto a linked row is a swap and stamps the date again. The blob reference goes
     * too, which leaves any file to the sweep.
     *
     * @return array<string, int|null>|null The columns to write, null when the row already holds the citation.
     */
    public static function attachValues(?int $heldGrantMemberId, int $pick, int $now): ?array
    {
        return $heldGrantMemberId === $pick ? null : [
            'citation_grant_member_id' => $pick,
            'citation_regiment_grant_id' => null,
            'citation_blob_id' => null,
            'citation_date' => $now,
        ];
    }
}
