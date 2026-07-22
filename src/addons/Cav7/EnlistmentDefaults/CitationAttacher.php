<?php

namespace Cav7\EnlistmentDefaults;

/**
 * The award row a citation is attached to, behind the smallest seam the rollback
 * needs. RosterUserGateway adapts the vendor RosterUserAward to this; tests pass
 * a fake. Only what the rollback decision touches is exposed: deleting the row.
 */
interface CitationAward
{
    /** Delete the saved award row. May throw (the vendor's delete() can). */
    public function delete(): void;
}

/**
 * Attaching a bundled citation image to an award, behind the smallest seam.
 * RosterUserGateway adapts the vendor AwardRecord\Image service to this; tests
 * pass a fake that can be told to fail.
 */
interface CitationImage
{
    /**
     * Stage the citation source file. Returns false if the image is rejected
     * (in which case errorText() explains why); true once staged.
     */
    public function setImage(string $path): bool;

    /** A human-readable reason the last setImage() was rejected. */
    public function errorText(): string;

    /**
     * Commit the staged citation: copy the file into place, stamp citation_date,
     * save. May throw — the copy happens before the save, so a throw here can
     * leave the copied file behind (see CitationAttacher).
     */
    public function updateImage(): void;

    /**
     * Best-effort delete of the citation file copied during updateImage(). Used
     * only on the rollback path; must not throw for "nothing to delete".
     */
    public function deleteFile(): void;
}

/**
 * Attaches a PUC citation to an already-saved award row, and rolls the grant
 * back cleanly if the citation cannot be attached.
 *
 * This is the addon's most intricate piece of entity-world logic, so the
 * decision (what to clean, what to re-throw) is isolated here as plain PHP and
 * exercised with fakes — the production gateway only adapts the vendor types to
 * the seams above, and supplies the logger.
 *
 * The rollback mirrors RosterAudit's AuditableEntity compensating-delete idiom:
 * a failed attach leaves nothing behind (no orphaned data file, no citationless
 * row) and the ORIGINAL failure is always the one re-thrown, so the error log
 * shows the real cause rather than a rollback hiccup. Keeping a grant
 * all-or-nothing per date means a later re-run retries that date cleanly.
 */
class CitationAttacher
{
    /**
     * @param callable(\Throwable, string): void $logFailure records a cleanup
     *        failure during rollback without masking the original error. Called
     *        with the exception and a context saying what could not be cleaned;
     *        the production gateway appends the PUC date the grant was for,
     *        stamps the milpac identity onto it, and forwards to
     *        \XF::logException.
     */
    public function __construct(
        private $logFailure
    ) {}

    /**
     * @throws \Throwable the original attach failure, after best-effort cleanup
     */
    public function attach(CitationAward $award, CitationImage $image, string $citationPath): void
    {
        if (!$image->setImage($citationPath))
        {
            // setImage() rejected the file before anything was copied, so there
            // is nothing to clean beyond the saved row. Roll it back so the date
            // stays pending for a re-run. rollback() always throws; the return
            // keeps the no-fall-through local rather than relying on that.
            $this->rollback($award, $image, new \RuntimeException(
                'citation image rejected: ' . $image->errorText()
            ));
            return;
        }

        try
        {
            // updateImage() copies the JPG into the data path THEN stamps
            // citation_date and save()s. A throw here may be after the copy, so
            // the rollback must clean the file as well as the row.
            $image->updateImage();
        }
        catch (\Throwable $e)
        {
            $this->rollback($award, $image, $e);
        }
    }

    /**
     * Best-effort: delete the copied citation file, then delete the award row,
     * each guarded so a cleanup failure is logged but never masks $original.
     * Always re-throws $original.
     *
     * @throws \Throwable always — $original
     */
    private function rollback(CitationAward $award, CitationImage $image, \Throwable $original): void
    {
        // File before row: the image service reads the award's citation_date to
        // decide there is a copied file to remove, so clean the file while the
        // row is still intact, then delete the row.
        try
        {
            $image->deleteFile();
        }
        catch (\Throwable $cleanup)
        {
            // An orphaned data file is harmless (record_id autoincrements, so a
            // re-run never collides with it) but wasteful; leave a breadcrumb
            // and carry on. The original failure still gets re-thrown.
            ($this->logFailure)($cleanup, 'citation file cleanup failed');
        }

        try
        {
            $award->delete();
        }
        catch (\Throwable $rollback)
        {
            // The row could not be rolled back, so a citationless PUC may stick
            // and the idempotency guard would skip it forever. Record that, but
            // still surface the original attach failure as the cause.
            ($this->logFailure)($rollback, 'award rollback after citation failure failed');
        }

        throw $original;
    }
}
