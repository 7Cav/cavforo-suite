<?php

namespace Cav7\Citations\Grant;

/** What a retypeset prints. Ids only, since the output lands in terminals and tickets. */
final class RetypesetReport
{
    private int $selected = 0;
    private int $changed = 0;
    private int $fields = 0;
    private int $below = 0;
    private int $above = 0;

    /**
     * @param list<FieldChange> $changes
     * @return list<string> The grant's block, empty when nothing changed.
     */
    public function grant(int $grantId, int $templateVersionId, int $members, array $changes): array
    {
        $this->selected++;
        if ($changes === []) {
            return [];
        }

        $this->changed++;
        $lines = ["grant $grantId, template version $templateVersionId, $members member(s)"];
        foreach ($changes as $change) {
            $this->fields++;
            $this->below += (int) ($change->crossing === FieldChange::BELOW);
            $this->above += (int) ($change->crossing === FieldChange::ABOVE);
            $lines[] = self::line($change);
        }
        return $lines;
    }

    public function failed(): void
    {
        $this->selected++;
    }

    public function summary(bool $dryRun): string
    {
        return sprintf(
            'Selected %d grant(s). %d %s, %d field(s). %d cross(es) below the threshold, %d above.',
            $this->selected,
            $this->changed,
            $dryRun ? 'would change' : 'changed',
            $this->fields,
            $this->below,
            $this->above,
        );
    }

    private static function line(FieldChange $change): string
    {
        $where = $change->grantMemberId === 0 ? 'shared' : "member {$change->grantMemberId}";
        $line = sprintf(
            '  %-12s %s  size %.2f -> %.2f  lines %d -> %d',
            $where,
            $change->fieldKey,
            $change->oldSize,
            $change->newSize,
            $change->oldLines,
            $change->newLines,
        );

        if ($change->breaksMoved) {
            $line .= '  breaks moved';
        }
        if ($change->crossing !== null) {
            $line .= sprintf('  crosses %s threshold %.2f', $change->crossing, $change->threshold);
        }
        return $line;
    }
}
