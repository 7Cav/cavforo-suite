<?php

namespace Cav7\Citations\Template;

use Cav7\Citations\Render\FieldDefinitions;
use Cav7\Citations\Render\SignatureSlot;

/** A signature is frozen once a grant uses it. Retiring it stays allowed either way. */
final class SignatureRules
{
    private const PRINTED_COLUMNS = ['printed_lines', 'ink_hash', 'ink_width', 'ink_height', 'billet_id'];
    private const MAX_LINES = 4;

    /** @param list<string> $changed Columns the save changes. */
    public static function editRefused(bool $frozen, array $changed): bool
    {
        return $frozen && array_intersect($changed, self::PRINTED_COLUMNS) !== [];
    }

    public static function linesValid(mixed $lines): bool
    {
        if (!is_array($lines) || !array_is_list($lines) || $lines === [] || count($lines) > self::MAX_LINES) {
            return false;
        }
        foreach ($lines as $line) {
            if (!is_string($line) || trim($line) === '') {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $fieldDefinitions The `field_definitions` of every version, drafts included. */
    public static function billetInSlots(int $billetId, array $fieldDefinitions): bool
    {
        return isset(self::slotCounts($fieldDefinitions)[$billetId]);
    }

    /**
     * @param list<string> $fieldDefinitions The `field_definitions` of every version, drafts included.
     * @return array<int, int> Slots naming each billet, by billet id.
     */
    public static function slotCounts(array $fieldDefinitions): array
    {
        $counts = [];
        foreach ($fieldDefinitions as $json) {
            foreach (FieldDefinitions::fields($json) as $field) {
                if ($field instanceof SignatureSlot) {
                    $counts[$field->billet_id] = ($counts[$field->billet_id] ?? 0) + 1;
                }
            }
        }
        return $counts;
    }
}
