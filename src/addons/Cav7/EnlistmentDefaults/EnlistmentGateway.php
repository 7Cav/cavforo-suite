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
     * Record a failure in the XF error log. Everything that can go wrong during
     * enlistment comes through here: a failed grant, a failed record write,
     * anything raised before the grant loop (the entity extension's last-resort
     * guard), and the citation rollback's cleanup breadcrumbs. The first three
     * are swallowed after logging, so enlistment never blocks; a breadcrumb is
     * not swallowed in that sense — the attach failure it accompanies is still
     * re-thrown past it, and the guard around the grant is what absorbs that.
     *
     * The exception is passed whole rather than flattened to its message, so
     * the entry keeps the class and stack trace. $context says what was being
     * done ('failed to grant PUC for 2003-03-18'); the implementation stamps
     * the milpac the failure belongs to, since it is the side that holds the
     * entity — that is what makes a dropped grant traceable to a member.
     *
     * $context is a bare phrase: the implementation supplies the addon prefix
     * and the trailing punctuation, so a caller adding its own double-prefixes
     * the entry.
     */
    public function logFailure(\Throwable $e, string $context): void;
}
