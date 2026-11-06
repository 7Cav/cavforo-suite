<?php

namespace Cav7\Citations\Template;

use Cav7\Citations\Render\FontCatalog;

/**
 * The draft editor's forms as `field_definitions` and `input_definitions` entries. Nothing is
 * refused here: whether an entry is well formed is FieldDefinitions' question when it saves,
 * and whether it prints is publish's.
 */
final class FieldForm
{
    public const KINDS = ['text', 'signature', 'rank_art'];

    /**
     * What a new field starts from, every kind's attributes at once so the form can switch kind.
     *
     * @return array<string, mixed>
     */
    public static function blank(int $plateWidth): array
    {
        return [
            'kind' => 'text', 'key' => '', 'x' => 40, 'y' => 0, 'w' => max(1, min(1195, $plateWidth - 80)), 'h' => 44,
            'pattern' => '', 'font' => array_key_first(FontCatalog::FONTS), 'size' => 36, 'legible_size' => 20,
            'leading' => 1.2, 'mode' => 'line', 'align' => 'center', 'caps' => false, 'colour' => '#16161a',
            'billet_id' => 0, 'ink_height' => 36,
        ];
    }

    /**
     * @param array<string, string> $posted
     * @return array<string, mixed>
     */
    public static function definition(array $posted): array
    {
        $box = [
            'key' => $posted['key'] ?? '',
            'x' => (int) ($posted['x'] ?? 0),
            'y' => (int) ($posted['y'] ?? 0),
            'w' => (int) ($posted['w'] ?? 0),
            'h' => (int) ($posted['h'] ?? 0),
        ];
        $type = [
            'font' => $posted['font'] ?? '',
            'size' => (float) ($posted['size'] ?? 0),
            'legible_size' => (float) ($posted['legible_size'] ?? 0),
            'leading' => (float) ($posted['leading'] ?? 0),
            'colour' => strtolower($posted['colour'] ?? ''),
        ];

        return match ($posted['kind'] ?? 'text') {
            'rank_art' => ['kind' => 'rank_art'] + $box,
            'signature' => ['kind' => 'signature'] + $box + [
                'billet_id' => (int) ($posted['billet_id'] ?? 0),
                'ink_height' => (int) ($posted['ink_height'] ?? 0),
            ] + $type,
            default => ['kind' => 'text'] + $box + ['pattern' => $posted['pattern'] ?? ''] + $type + [
                'mode' => empty($posted['wrap']) ? 'line' : 'wrap',
                'align' => $posted['align'] ?? '',
                'caps' => !empty($posted['caps']),
            ],
        };
    }

    /**
     * An edited field keeps its place in the draw order, and a new one goes last. A key taken
     * by another field stays a duplicate for FieldDefinitions to refuse.
     *
     * @param list<array<string, mixed>> $definitions
     * @param array<string, mixed> $definition
     * @return list<array<string, mixed>>
     */
    public static function placed(array $definitions, ?string $originalKey, array $definition): array
    {
        $at = self::index($definitions, $originalKey);
        if ($at === null) {
            $definitions[] = $definition;
        } else {
            $definitions[$at] = $definition;
        }
        return $definitions;
    }

    /**
     * @param list<array<string, mixed>> $definitions
     * @return list<array<string, mixed>>
     */
    public static function without(array $definitions, string $key): array
    {
        return array_values(array_filter($definitions, fn (array $field): bool => $field['key'] !== $key));
    }

    /**
     * One place along the draw order. Stepping off either end changes nothing.
     *
     * @param list<array<string, mixed>> $definitions
     * @param -1|1 $step
     * @return list<array<string, mixed>>
     */
    public static function stepped(array $definitions, string $key, int $step): array
    {
        $at = self::index($definitions, $key);
        $to = $at === null ? null : $at + $step;
        if ($to === null || !isset($definitions[$to])) {
            return $definitions;
        }

        [$definitions[$at], $definitions[$to]] = [$definitions[$to], $definitions[$at]];
        return $definitions;
    }

    /**
     * Rows with no key are the form's spare row and any left blank.
     *
     * @param array<int|string, array<string, string>> $rows
     * @return list<array{key: string, label: string, scope: string, required: bool}>
     */
    public static function inputs(array $rows): array
    {
        $inputs = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['key'] ?? ''));
            if ($key !== '') {
                $inputs[] = [
                    'key' => $key,
                    'label' => trim((string) ($row['label'] ?? '')),
                    'scope' => (string) ($row['scope'] ?? 'grant'),
                    'required' => !empty($row['required']),
                ];
            }
        }
        return $inputs;
    }

    /** @param list<array<string, mixed>> $definitions */
    private static function index(array $definitions, ?string $key): ?int
    {
        foreach ($definitions as $i => $field) {
            if ($field['key'] === $key) {
                return $i;
            }
        }
        return null;
    }
}
