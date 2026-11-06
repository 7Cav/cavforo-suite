<?php

namespace Cav7\Citations\Storage;

/**
 * Which re-encode log entries a revert covers: one run, one encoded blob, or all runs.
 * Naming a row means the encoded blob that row points at, never that row alone.
 */
final class RevertScope
{
    private function __construct(public readonly bool $all, private string $where, private array $params)
    {
    }

    /**
     * @throws \InvalidArgumentException unless exactly one scope is named, and validly
     */
    public static function parse(?string $run, bool $all, ?string $blob, ?string $row, Rows $rows): self
    {
        $named = array_filter([$run !== null, $all, $blob !== null, $row !== null]);
        if (count($named) !== 1) {
            throw new \InvalidArgumentException('Name exactly one of --run, --all, --blob or --row');
        }

        if ($all) {
            return new self(true, '1 = 1', []);
        }
        if ($run !== null) {
            return new self(false, 'run_id = ?', [self::id($run, '--run')]);
        }
        if ($blob !== null) {
            return self::forBlob(self::id($blob, '--blob'));
        }

        [$kindName, $recordId] = array_pad(explode(':', (string) $row, 2), 2, '');
        $kind = RowKind::tryFrom($kindName)
            ?? throw new \InvalidArgumentException('--row takes award:ID or record:ID');
        $facts = $rows->facts($kind, self::id($recordId, '--row'));
        if ($facts === null || $facts['citation_blob_id'] === null) {
            throw new \InvalidArgumentException("$row has no citation reference");
        }

        return self::forBlob($facts['citation_blob_id']);
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    public function condition(): array
    {
        return [$this->where, $this->params];
    }

    private static function forBlob(int $blobId): self
    {
        return new self(false, 'encoded_blob_id = ?', [$blobId]);
    }

    private static function id(string $value, string $flag): int
    {
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException("$flag takes a number");
        }

        return (int) $value;
    }
}
