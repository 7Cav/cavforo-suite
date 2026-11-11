<?php

namespace Cav7\EnlistmentDefaults;

/**
 * The entity-world operations the applier needs, behind one seam. RosterUserGateway drives
 * the vendor against a real milpac; tests supply a fake.
 */
interface EnlistmentGateway
{
    public function pucSyncOn(): bool;

    /**
     * Runs the PUC sync on this milpac. A failed row write is logged through logFailure and
     * the rest still run; anything before the writes throws.
     */
    public function syncPucRows(): void;

    /** The configured enlistment record-type id (default the Transfer type). */
    public function enlistmentRecordTypeId(): int;

    /** The milpac's creation timestamp, the enlistment record's fallback date. */
    public function creationDate(): int;

    /** The raw Join Date custom field as submitted ('Y-m-d', or blank). */
    public function joinDate(): string;

    public function writeServiceRecord(int $recordTypeId, string $body, int $recordDate): void;

    /**
     * Records a failure in the XF error log, with the exception whole. $context is a bare
     * phrase ('failed to write enlistment record'); the implementation adds the addon prefix,
     * the milpac it belongs to, and the trailing punctuation.
     */
    public function logFailure(\Throwable $e, string $context): void;
}
