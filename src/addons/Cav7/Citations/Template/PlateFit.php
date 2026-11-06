<?php

namespace Cav7\Citations\Template;

use Cav7\Citations\Render\PatternError;
use Cav7\Citations\Render\PatternParser;
use Cav7\Citations\Render\RankArtField;
use Cav7\Citations\Render\SetField;
use Cav7\Citations\Render\SignatureSlot;
use Cav7\Citations\Render\TextField;
use Cav7\Citations\Render\Version;

/** What the template preview says about each field, drawn or not. Sets nothing itself. */
final class PlateFit
{
    public const BAD_PATTERN = 'bad_pattern';
    public const NO_SIGNATURE = 'no_signature';
    public const NO_ART = 'no_art';
    public const NOTHING_TO_PRINT = 'nothing_to_print';

    /** @return array{message: string, at: int}|null Where the pattern breaks, counted from 1. */
    public static function patternError(string $pattern): ?array
    {
        try {
            PatternParser::parse($pattern);
            return null;
        } catch (PatternError $e) {
            return ['message' => $e->getMessage(), 'at' => $e->charOffset + 1];
        }
    }

    /**
     * A draft may hold a pattern that doesn't parse, and the typesetter can't take one.
     *
     * @return Version The design less those fields.
     */
    public static function settable(Version $design): Version
    {
        $fields = array_filter(
            $design->fields,
            fn (object $field): bool => !$field instanceof TextField || self::patternError($field->pattern) === null
        );

        return new Version(array_values($fields), $design->inputs, $design->rank_scope);
    }

    /**
     * @param array<string, SetField> $rows By field key, every field of the design.
     * @return list<array{key: string, size: float, legible: float, below: bool, undrawn: string, error: array{message: string, at: int}|null}>
     */
    public static function report(Version $design, array $rows): array
    {
        $report = [];
        foreach ($design->fields as $field) {
            $row = $rows[$field->key];
            $error = $field instanceof TextField ? self::patternError($field->pattern) : null;

            $report[] = [
                'key' => $field->key,
                'size' => $row->size,
                'legible' => $field instanceof RankArtField ? 0.0 : (float) $field->legible_size,
                'below' => $row->belowLegibility,
                'undrawn' => self::undrawn($field, $row, $error !== null),
                'error' => $error,
            ];
        }
        return $report;
    }

    private static function undrawn(TextField|SignatureSlot|RankArtField $field, SetField $row, bool $broken): string
    {
        return match (true) {
            $broken => self::BAD_PATTERN,
            $field instanceof RankArtField => $row->art === null ? self::NO_ART : '',
            $field instanceof SignatureSlot => $row->ink === null ? self::NO_SIGNATURE : '',
            default => $row->lines === [] ? self::NOTHING_TO_PRINT : '',
        };
    }

    /**
     * The legibility list's threshold for each entry, which the version holds and the row doesn't.
     *
     * @param list<array<string, mixed>> $entries Rows of TypesettingRows::belowLegibility().
     * @param array<int, Version> $designs By template version id.
     * @return list<array<string, mixed>> The entries, each with its `legible`.
     */
    public static function withThresholds(array $entries, array $designs): array
    {
        return array_map(function (array $entry) use ($designs): array {
            $entry['legible'] = 0.0;
            foreach ($designs[(int) $entry['template_version_id']]->fields ?? [] as $field) {
                if ($field->key === $entry['field_key'] && !$field instanceof RankArtField) {
                    $entry['legible'] = (float) $field->legible_size;
                }
            }
            return $entry;
        }, $entries);
    }
}
