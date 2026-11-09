<?php

namespace Cav7\Citations\Render;

/** What stops a draft publishing. A problem with an empty `field` is the version's own. */
final class VersionRules
{
    public const NO_PLATE = 'no_plate';
    public const NO_KIND = 'no_kind';
    public const KIND_OUTSIDE_GENERATOR = 'kind_outside_generator';
    public const BOX_OFF_PLATE = 'box_off_plate';
    public const NO_BILLET = 'no_billet';
    public const NO_CATALOG_FONT = 'no_catalog_font';
    public const RANK_ART_WITHOUT_SCOPE = 'rank_art_without_scope';
    public const INPUT_SCOPE_CHANGED = 'input_scope_changed';
    public const DOES_NOT_PARSE = 'does_not_parse';
    public const UNDECLARED_INPUT = 'undeclared_input';
    public const CITATION_TEXT_OUTSIDE_WRAP = 'citation_text_outside_wrap';
    public const RANK_WITHOUT_SCOPE = 'rank_without_scope';
    public const NARROW_FONT_VALUE = 'narrow_font_value';

    // The record types the generator covers, by id on this install. Any other type, and any
    // added later, stays out until someone adds it here on purpose. Every award is in.
    public const PROMOTION = 1;
    public const GRADUATION = 9;

    private const RANK_NAMES = ['rank', 'pay_grade', 'rank_abbr'];
    private const DATE_NAMES = ['day', 'day_ordinal', 'month', 'year'];

    /**
     * Plate size 0 means no plate. Duplicate keys never reach here, since a draft can't save them.
     *
     * @param list<string> $kinds As `award:<id>` or `record_type:<id>`.
     * @param list<int> $billetIds Every signing billet that exists.
     * @param array<string, string> $earlierScopes Input key => scope on the template's earlier versions.
     * @return list<array{field: string, rule: string}>
     */
    public static function publishProblems(
        Version $version,
        int $plateWidth,
        int $plateHeight,
        array $kinds,
        array $billetIds,
        array $earlierScopes,
    ): array {
        $problems = [];
        if ($plateWidth === 0) {
            $problems[] = ['field' => '', 'rule' => self::NO_PLATE];
        }
        if ($kinds === []) {
            $problems[] = ['field' => '', 'rule' => self::NO_KIND];
        }
        if (array_filter($kinds, fn (string $kind): bool => !self::generated($kind)) !== []) {
            $problems[] = ['field' => '', 'rule' => self::KIND_OUTSIDE_GENERATOR];
        }

        foreach ($version->fields as $field) {
            foreach (self::fieldRulesBroken($field, $version, $plateWidth, $plateHeight, $billetIds) as $rule) {
                $problems[] = ['field' => $field->key, 'rule' => $rule];
            }
        }

        foreach ($version->inputs as $input) {
            if (($earlierScopes[$input->key] ?? $input->scope) !== $input->scope) {
                $problems[] = ['field' => $input->key, 'rule' => self::INPUT_SCOPE_CHANGED];
            }
        }

        return array_merge($problems, self::patternProblems($version));
    }

    /** Whether a kind is one the generator covers: any award, or a Promotion or Graduation record. */
    public static function generated(string $kind): bool
    {
        return str_starts_with($kind, 'award:')
            || in_array($kind, ['record_type:' . self::PROMOTION, 'record_type:' . self::GRADUATION], true);
    }

    /**
     * @param list<int> $billetIds
     * @return list<string>
     */
    private static function fieldRulesBroken(
        TextField|SignatureSlot|RankArtField $field,
        Version $version,
        int $plateWidth,
        int $plateHeight,
        array $billetIds,
    ): array {
        $rules = [];
        if ($plateWidth > 0 && ($field->x + $field->w > $plateWidth || $field->y + $field->h > $plateHeight)) {
            $rules[] = self::BOX_OFF_PLATE;
        }
        if ($field instanceof RankArtField) {
            return $version->rank_scope === 'none' ? [...$rules, self::RANK_ART_WITHOUT_SCOPE] : $rules;
        }
        if (!isset(FontCatalog::FONTS[$field->font])) {
            $rules[] = self::NO_CATALOG_FONT;
        }
        if ($field instanceof SignatureSlot && !in_array($field->billet_id, $billetIds, true)) {
            $rules[] = self::NO_BILLET;
        }
        return $rules;
    }

    /** @return list<array{field: string, rule: string}> */
    public static function patternProblems(Version $version): array
    {
        $declared = array_map(fn (InputDefinition $input): string => $input->key, $version->inputs);
        $problems = [];

        foreach ($version->fields as $field) {
            if ($field instanceof SignatureSlot && self::isNarrow($field->font)) {
                $problems[] = ['field' => $field->key, 'rule' => self::NARROW_FONT_VALUE];
            }
            if (!$field instanceof TextField) {
                continue;
            }

            try {
                $names = PatternParser::placeholders($field->pattern);
            } catch (PatternError) {
                $problems[] = ['field' => $field->key, 'rule' => self::DOES_NOT_PARSE];
                continue;
            }

            foreach (self::rulesBroken($field, $names, $declared, $version->rank_scope) as $rule) {
                $problems[] = ['field' => $field->key, 'rule' => $rule];
            }
        }

        return $problems;
    }

    /**
     * @param list<string> $names
     * @param list<string> $declared
     * @return list<string>
     */
    private static function rulesBroken(TextField $field, array $names, array $declared, string $rankScope): array
    {
        $rules = [];
        foreach ($names as $name) {
            if (str_starts_with($name, 'input:') && !in_array(substr($name, 6), $declared, true)) {
                $rules[] = self::UNDECLARED_INPUT;
            }
        }
        if (in_array('citation_text', $names, true) && $field->mode !== 'wrap') {
            $rules[] = self::CITATION_TEXT_OUTSIDE_WRAP;
        }
        if ($rankScope === 'none' && array_intersect($names, self::RANK_NAMES) !== []) {
            $rules[] = self::RANK_WITHOUT_SCOPE;
        }
        if (self::isNarrow($field->font) && array_diff($names, self::DATE_NAMES) !== []) {
            $rules[] = self::NARROW_FONT_VALUE;
        }
        return array_values(array_unique($rules));
    }

    private static function isNarrow(string $font): bool
    {
        return FontCatalog::FONTS[$font]['narrow'] ?? false;
    }
}
