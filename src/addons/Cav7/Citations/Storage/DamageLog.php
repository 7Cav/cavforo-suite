<?php

namespace Cav7\Citations\Storage;

/**
 * Damage goes to the operator in full and to xf_error_log up to LOG_LIMIT entries, so a
 * bad restore can't write tens of thousands of rows there. A null $log writes nothing,
 * which is what a dry run needs.
 */
final class DamageLog
{
    public const LOG_LIMIT = 100;

    public int $total = 0;

    /**
     * @param (\Closure(string): void)|null $log
     * @param \Closure(string): void $say
     */
    public function __construct(private ?\Closure $log, private \Closure $say)
    {
    }

    public function report(string $what, string $path = '', string $row = '', ?string $hash = null): void
    {
        $this->total++;

        $line = sprintf(
            'Cav7/Citations damage: %s path=%s row=%s hash=%s',
            $what,
            $path !== '' ? $path : '-',
            $row !== '' ? $row : '-',
            $hash !== null ? bin2hex($hash) : '-'
        );

        ($this->say)($line);
        if ($this->total <= self::LOG_LIMIT) {
            $this->log($line);
        }
    }

    /**
     * A line about the run itself, outside the cap.
     */
    public function notice(string $line): void
    {
        ($this->say)($line);
        $this->log($line);
    }

    public function finish(): void
    {
        if ($this->total > self::LOG_LIMIT) {
            $this->log(sprintf(
                'Cav7/Citations damage: %d entries this run, the first %d logged',
                $this->total,
                self::LOG_LIMIT
            ));
        }
    }

    private function log(string $line): void
    {
        if ($this->log) {
            ($this->log)($line);
        }
    }
}
