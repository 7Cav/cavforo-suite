<?php

namespace Cav7\Citations\Grant;

use Cav7\Citations\Render\FieldDependencies;
use Cav7\Citations\Render\Version;

/** What changed between two sets of values, for the log, and which fields that remakes. */
final class Correction
{
    /** @param list<array{what: string, old: mixed, new: mixed, member?: int}> $changes */
    public function __construct(
        public readonly array $changes,
        public readonly RemakePlan $remake,
    ) {
    }

    /**
     * Only fields whose dependencies meet the change are remade. A shared value inside a
     * member-row field remakes that field for every member. A member's value reaches that
     * member alone, even if the fit has moved since issue.
     */
    public static function between(Version $version, GrantValues $old, GrantValues $new): self
    {
        $changes = [];

        $sharedChanged = self::differing($old->facets(), $new->facets());
        foreach ($sharedChanged as $what => [$was, $is]) {
            $changes[] = ['what' => $what, 'old' => $was, 'new' => $is];
        }

        $shared = [];
        $forEveryone = [];
        foreach (FieldDependencies::remadeBy($version, array_keys($sharedChanged)) as $field) {
            if (FieldDependencies::of($field, $version)->member) {
                $forEveryone[] = $field;
            } else {
                $shared[] = $field;
            }
        }

        $members = [];
        foreach ($new->members as $id => $member) {
            $memberChanged = isset($old->members[$id]) ? self::differing($old->members[$id]->facets(), $member->facets()) : [];
            foreach ($memberChanged as $what => [$was, $is]) {
                $changes[] = ['what' => $what, 'old' => $was, 'new' => $is, 'member' => $id];
            }

            $fields = $forEveryone;
            foreach (FieldDependencies::remadeBy($version, array_keys($memberChanged)) as $field) {
                if (!in_array($field, $fields, true)) {
                    $fields[] = $field;
                }
            }
            if ($fields !== []) {
                $members[$id] = $fields;
            }
        }

        return new self($changes, new RemakePlan($shared, $members));
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @return array<string, array{mixed, mixed}>
     */
    private static function differing(array $old, array $new): array
    {
        $differing = [];
        foreach (array_keys($old + $new) as $what) {
            $was = $old[$what] ?? '';
            $is = $new[$what] ?? '';
            if ($was !== $is) {
                $differing[$what] = [$old[$what] ?? null, $new[$what] ?? null];
            }
        }
        return $differing;
    }
}
