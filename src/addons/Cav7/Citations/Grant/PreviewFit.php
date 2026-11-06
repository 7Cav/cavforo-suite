<?php

namespace Cav7\Citations\Grant;

use Cav7\Citations\Render\FieldDependencies;
use Cav7\Citations\Render\RankArtField;
use Cav7\Citations\Render\SetField;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\TextField;
use Cav7\Citations\Render\Version;

/** What a half-filled grant form can print, and how what it printed fit. Sets nothing itself. */
final class PreviewFit
{
    /**
     * @param list<array{key: string, size: float, legible: float, text: bool}> $below Every field under its threshold.
     * @param array{size: float, largest: bool}|null $text The citation text's size, once any is set.
     */
    public function __construct(
        public readonly array $below,
        public readonly ?array $text,
    ) {
    }

    /**
     * A slot with no signature yet draws blank, and so does anything printing a date not yet
     * whole. The issue itself refuses both, so only a preview ever gets here.
     *
     * @param array<string, mixed> $signatures By slot key.
     * @return list<TextField|SignatureSlot|RankArtField>
     */
    public static function printable(Version $version, string $date, array $signatures): array
    {
        $dated = GrantRules::day($date) !== null;

        return array_values(array_filter($version->fields, function (object $field) use ($version, $dated, $signatures): bool {
            if ($field instanceof SignatureSlot) {
                return isset($signatures[$field->key]);
            }
            return $dated || !in_array('date', FieldDependencies::of($field, $version)->uses, true);
        }));
    }

    /**
     * @param array<string, SetField> $set By field key, what the typesetter made of the printable fields.
     * @return array<string, SetField> Every field, blank where nothing was set.
     */
    public static function rows(Version $version, array $set): array
    {
        $rows = [];
        foreach ($version->fields as $field) {
            $rows[$field->key] = $set[$field->key]
                ?? new SetField($field->key, 0.0, false, $field instanceof RankArtField ? null : []);
        }
        return $rows;
    }

    /** @param array<string, SetField> $rows By field key. */
    public static function of(Version $version, array $rows): self
    {
        $below = [];
        $text = null;

        foreach ($version->fields as $field) {
            if ($field instanceof RankArtField) {
                continue;
            }
            $row = $rows[$field->key];
            $isText = in_array('citation_text', FieldDependencies::of($field, $version)->uses, true);

            if ($isText && $row->size > 0) {
                $text = ['size' => $row->size, 'largest' => $row->size >= $field->size];
            }
            if ($row->belowLegibility) {
                $below[] = ['key' => $field->key, 'size' => $row->size, 'legible' => (float) $field->legible_size, 'text' => $isText];
            }
        }

        return new self($below, $text);
    }
}
