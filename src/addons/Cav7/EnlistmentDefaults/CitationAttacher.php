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
     *
     * May also throw instead of returning false. The vendor validates the source
     * before it reaches any rejecting branch, and a missing or unreadable file
     * raises there — so "rejected" arrives as either a false or a throw, and a
     * caller that only handles the false handles half the contract.
     */
    public function setImage(string $path): bool;

    /**
     * A human-readable reason the last setImage() was rejected. May throw: the
     * vendor's reason is an \XF\Phrase, and rendering one goes through the
     * phrase cache.
     */
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
 *
 * Re-check on a dev stack after an NF/Rosters upgrade, and drive it deliberately
 * rather than waiting for it: move one JPG out of assets/puc-citations/, create a
 * milpac, confirm there is NO award row for that date while the other dates and
 * the enlistment record still apply and the milpac still saves, then put the file
 * back. The vendor rejects a missing or unreadable source by THROWING —
 * Service\AwardRecord\Image::validateImageForRecord() raises before it reaches any
 * branch that returns false — and the award row is already saved by then. If the
 * vendor ever swaps that throw for a plain false, or moves the validation, this
 * rollback is what stops a citationless row surviving, and
 * EnlistmentDecisions::pendingDates() matches on award_date, so a survivor makes
 * that date look granted for good.
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
     * The caller has already saved $award, so from the first statement here
     * onwards there is a row that must not be left behind. Everything that can
     * fail is therefore inside one guarded region — including resolving the
     * image service, which the caller defers to $resolveImage precisely so the
     * resolution happens in here rather than in the window before the guard.
     *
     * @param callable(): CitationImage $resolveImage resolves the vendor image
     *        service for the saved award row. Deferred rather than passed
     *        resolved: it needs the saved record_id, so it cannot happen before
     *        the row exists, and anywhere the caller could run it would be
     *        outside the rollback's reach.
     *
     * @throws \Throwable the original attach failure, after best-effort cleanup
     */
    public function attach(CitationAward $award, callable $resolveImage, string $citationPath): void
    {
        // Null until resolved, and the rollback treats it as "nothing was
        // staged, so there is no file to clean" — which is exactly true when
        // resolving is what failed.
        $image = null;

        try
        {
            $image = $resolveImage();

            if (!$image->setImage($citationPath))
            {
                // Rejected before anything was copied, so there is nothing to
                // clean beyond the saved row. Rolling it back keeps the date
                // pending for a re-run. errorText() is read in here too: it
                // renders a vendor phrase and can fail, and building this
                // message outside the guard would put that failure past the
                // rollback's reach.
                throw new \RuntimeException(
                    'citation image rejected: ' . $image->errorText()
                );
            }

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
     * @param CitationImage|null $image null when the failure was resolving the
     *        image service itself, in which case nothing was ever staged and
     *        there is no copied file to clean.
     *
     * @throws \Throwable always — $original
     */
    private function rollback(CitationAward $award, ?CitationImage $image, \Throwable $original): void
    {
        // File before row: the image service reads the award's citation_date to
        // decide there is a copied file to remove, so clean the file while the
        // row is still intact, then delete the row.
        if ($image !== null)
        {
            try
            {
                $image->deleteFile();
            }
            catch (\Throwable $cleanup)
            {
                // An orphaned data file is harmless (record_id autoincrements,
                // so a re-run never collides with it) but wasteful; leave a
                // breadcrumb and carry on. The original failure still gets
                // re-thrown.
                $this->breadcrumb($cleanup, 'citation file cleanup failed');
            }
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
            $this->breadcrumb($rollback, 'award rollback after citation failure failed');
        }

        throw $original;
    }

    /**
     * Log a cleanup breadcrumb, swallowing a failure of the log seam itself.
     *
     * The applier deliberately leaves its own per-grant logFailure calls
     * unguarded, and that policy is not widened here: there, a broken log seam
     * costs the remaining grants and the enlistment record, which a re-run can
     * still recover, because every failed date was rolled back and so is still
     * pending. Here it would cost the rollback — the award row delete below
     * never runs, and pendingDates() matches on award_date, so the surviving
     * citationless row makes that date look granted from then on and nothing
     * ever retries it. Lost work against unrecoverable state is not the same
     * trade, so these two calls are guarded and the applier's are not.
     *
     * Dropping the breadcrumb costs little on its own: $original is still
     * re-thrown to the applier, which logs it through the same seam, and if that
     * seam is broken too the entity extension's last-resort catch keeps the
     * milpac save.
     */
    private function breadcrumb(\Throwable $e, string $context): void
    {
        try
        {
            ($this->logFailure)($e, $context);
        }
        catch (\Throwable)
        {
        }
    }
}
