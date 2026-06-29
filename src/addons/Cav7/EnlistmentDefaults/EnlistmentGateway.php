<?php

namespace Cav7\EnlistmentDefaults;

/**
 * The entity-world operations the applier needs, behind one seam. The
 * production implementation (RosterUserGateway) drives the NF/Rosters vendor
 * factories and services against a real milpac; tests supply a fake.
 *
 * Keeping every XenForo touchpoint here lets EnlistmentApplier hold the
 * orchestration and the fail-open policy as plain, testable PHP.
 */
interface EnlistmentGateway
{
    /**
     * The award_date timestamps already on the milpac for the PUC award. Used
     * for the idempotency guard so a re-run does not duplicate a grant.
     *
     * @return int[]
     */
    public function existingPucAwardDates(): array;

    /** The configured PUC award id (default 61). */
    public function pucAwardId(): int;

    /** The configured enlistment record-type id (default the Transfer type). */
    public function enlistmentRecordTypeId(): int;

    /** The acting visitor's user id, 0 when there is no session (CLI/cron). */
    public function visitorUserId(): int;

    /** The configured system fallback user id for sessionless attribution. */
    public function systemFallbackUserId(): int;

    /**
     * The milpac's creation timestamp. The enlistment record falls back to
     * midnight UTC of the day this timestamp falls on when the Join Date is
     * blank or unparseable.
     */
    public function creationDate(): int;

    /**
     * The raw Join Date custom field as submitted ('Y-m-d', or blank). The
     * enlistment record is dated from this when it is a usable date.
     */
    public function joinDate(): string;

    /**
     * Grant one PUC award on the milpac: create the award row (award id,
     * award_date, from_user_id) and attach the bundled citation JPG, which
     * stamps citation_date.
     */
    public function grantAward(int $awardId, int $awardDate, int $fromUserId, string $citationPath): void;

    /**
     * Write the single enlistment service record on the milpac.
     */
    public function writeServiceRecord(int $recordTypeId, string $body, int $recordDate): void;

    /**
     * Log a message to the XF error log. A grant or record-write failure is
     * logged here and swallowed, so enlistment never blocks.
     */
    public function logError(string $message): void;
}
