<?php

namespace Cav7\Citations\Storage;

/**
 * Where a sweep has got to and what it has found. Plain values only, because the job
 * carries this between steps.
 */
final class SweepProgress
{
    public const TAKE_IN = 'take_in';
    public const ROWS = 'rows';
    public const ORPHAN_COUNT = 'orphan_count';
    public const ORPHAN_VERDICT = 'orphan_verdict';
    public const ORPHAN_DELETE = 'orphan_delete';
    public const RECOUNT = 'recount';
    public const BLOBS = 'blobs';
    public const ZERO_COUNT = 'zero_count';
    public const DONE = 'done';

    public string $phase = self::TAKE_IN;
    public int $kindIndex = 0;
    public int $cursor = -1;

    public bool $dryRun = false;
    public bool $liftBrake = false;
    public bool $verifyHashes = false;

    public int $tookIn = 0;
    public int $orphans = 0;
    public int $orphansDeleted = 0;
    public int $zeroReference = 0;
    public int $zeroDeleted = 0;
    public int $damage = 0;

    /** @var list<string> */
    public array $braked = [];

    /** @var array<int, int> the stored count of each blob the recount took to zero */
    public array $lastRefCounts = [];

    public function enter(string $phase): void
    {
        $this->phase = $phase;
        $this->kindIndex = 0;
        $this->cursor = -1;
    }

    public function done(): bool
    {
        return $this->phase === self::DONE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $progress = new self();
        foreach ($values as $name => $value) {
            $progress->$name = $value;
        }

        return $progress;
    }
}
