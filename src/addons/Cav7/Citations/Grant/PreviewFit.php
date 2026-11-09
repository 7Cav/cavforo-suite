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
     * Sizes are in points, as the clerk reads them. The threshold check runs in pixels.
     *
     * @param list<array{key: string, size: float, legible: float, text: bool}> $below Every field under its threshold.
     * @param array{size: float, largest: bool}|null $text The citation text's size, once any is set.
     */
    public function __construct(
        public readonly array $below,
        public readonly ?array $text,
    ) {
    }

    /**
     * A slot with no signature yet sets nothing, and neither does anything printing a date not
     * yet whole. The issue itself refuses both, so only a preview ever gets here.
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

    /**
     * The empty signature slots and citation text a preview outlines, as the prototype does.
     *
     * @param array<string, SetField> $rows By field key, every field of the version.
     * @return list<TextField|SignatureSlot>
     */
    public static function placeholders(Version $version, array $rows): array
    {
        return array_values(array_filter($version->fields, fn (object $field): bool => match (true) {
            $field instanceof SignatureSlot => $rows[$field->key]->ink === null,
            $field instanceof TextField => $rows[$field->key]->lines === []
                && in_array('citation_text', FieldDependencies::of($field, $version)->uses, true),
            default => false,
        }));
    }

    /** @param array<string, SetField> $rows By field key. */
    public static function of(Version $version, array $rows, int $plateWidth, int $plateHeight): self
    {
        // The plate's short side prints 8.5 inches, whichever way up it is.
        $short = min($plateWidth, $plateHeight);
        $pt = fn (float $px): float => $short > 0 ? $px * 72 * 8.5 / $short : 0.0;

        $below = [];
        $text = null;

        foreach ($version->fields as $field) {
            if ($field instanceof RankArtField) {
                continue;
            }
            $row = $rows[$field->key];
            $isText = in_array('citation_text', FieldDependencies::of($field, $version)->uses, true);

            if ($isText && $row->size > 0) {
                $text = ['size' => $pt($row->size), 'largest' => $row->size >= $field->size];
            }
            if ($row->belowLegibility) {
                $below[] = ['key' => $field->key, 'size' => $pt($row->size), 'legible' => $pt((float) $field->legible_size), 'text' => $isText];
            }
        }

        return new self($below, $text);
    }
}
