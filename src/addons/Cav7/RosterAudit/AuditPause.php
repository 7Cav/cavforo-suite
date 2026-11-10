<?php

namespace Cav7\RosterAudit;

/**
 * Leaves an addon's own bulk writes out of the audit log, for writes another record already
 * covers. Cav7/EnlistmentDefaults' PUC sync logs on the regiment grant instead. Writes still go
 * through entities, so vendor and addon save hooks run as usual.
 */
final class AuditPause
{
    private static int $depth = 0;

    /**
     * @template T
     * @param \Closure(): T $write
     * @return T
     */
    public static function during(\Closure $write): mixed
    {
        self::$depth++;
        try {
            return $write();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }
}
