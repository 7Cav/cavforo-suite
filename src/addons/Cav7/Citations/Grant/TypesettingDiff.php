<?php

namespace Cav7\Citations\Grant;

use Cav7\Citations\Render\Line;
use Cav7\Citations\Render\SetField;

/** Old rows against new, on what the draw prints. A line's measured width and the version stamps don't count. */
final class TypesettingDiff
{
    /**
     * @param array<string, float> $thresholds Legibility threshold by field key.
     * @return list<FieldChange>
     */
    public static function between(Typesetting $old, Typesetting $new, array $thresholds): array
    {
        $changes = self::fields(0, $old->shared, $new->shared, $thresholds);
        foreach ($new->members as $id => $fields) {
            array_push($changes, ...self::fields($id, $old->members[$id] ?? [], $fields, $thresholds));
        }
        return $changes;
    }

    /**
     * @param array<string, SetField> $old
     * @param array<string, SetField> $new
     * @param array<string, float> $thresholds
     * @return list<FieldChange>
     */
    private static function fields(int $grantMemberId, array $old, array $new, array $thresholds): array
    {
        $changes = [];
        foreach ($new as $key => $field) {
            $was = $old[$key] ?? null;
            if ($was !== null && self::printsAlike($was, $field)) {
                continue;
            }

            $wasBelow = $was?->belowLegibility ?? false;
            $changes[] = new FieldChange(
                $grantMemberId,
                $key,
                $was->size ?? 0.0,
                $field->size,
                count($was->lines ?? []),
                count($field->lines ?? []),
                self::texts($was) !== self::texts($field),
                $wasBelow === $field->belowLegibility ? null : ($field->belowLegibility ? FieldChange::BELOW : FieldChange::ABOVE),
                $thresholds[$key] ?? 0.0,
            );
        }
        return $changes;
    }

    private static function printsAlike(SetField $a, SetField $b): bool
    {
        return $a->size === $b->size
            && $a->belowLegibility === $b->belowLegibility
            && $a->ink == $b->ink
            && $a->art == $b->art
            && self::placed($a) === self::placed($b);
    }

    /** @return list<array{string, float, float, float}> */
    private static function placed(SetField $field): array
    {
        return array_map(fn (Line $line): array => [$line->t, $line->x, $line->y, $line->iw], $field->lines ?? []);
    }

    /** @return list<string> */
    private static function texts(?SetField $field): array
    {
        return array_map(fn (Line $line): string => $line->t, $field->lines ?? []);
    }
}
