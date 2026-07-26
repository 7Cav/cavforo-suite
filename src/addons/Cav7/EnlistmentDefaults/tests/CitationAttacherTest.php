<?php

/**
 * Issue #24 — the citation-attach rollback: the addon's most intricate piece of
 * entity-world logic, and the one with real failure modes.
 *
 * CitationAttacher attaches a bundled PUC citation to an already-saved award
 * row. If the citation cannot be attached it must roll the grant back cleanly:
 * delete the copied data file (best-effort) AND the award row, so no orphaned
 * file and no citationless row are left, AND always re-throw the ORIGINAL
 * failure so the error log shows the real cause rather than a rollback hiccup.
 * Keeping a grant all-or-nothing per date is what lets a later re-run retry that
 * date — proven here too.
 *
 * The decision is isolated behind two tiny seams (CitationAward, CitationImage),
 * so it is exercised here with hand fakes that record save/delete/setImage/
 * updateImage/deleteFile calls and can be told to fail. No XenForo, no database:
 * the production RosterUserGateway only adapts the vendor RosterUserAward and
 * AwardRecord\Image service to these same seams.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/CitationAttacherTest.php
 */

namespace Cav7\EnlistmentDefaults\Tests;

require __DIR__ . '/../CitationAttacher.php';

use Cav7\EnlistmentDefaults\CitationAttacher;
use Cav7\EnlistmentDefaults\CitationAward;
use Cav7\EnlistmentDefaults\CitationImage;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * A fake award row. Records whether it was deleted; can be told to throw from
 * delete() to model a rollback that itself fails (vendor delete() can throw).
 *
 * It throws an \Error rather than an \Exception on purpose — see the note on
 * FakeCitationImage.
 */
class FakeCitationAward implements CitationAward
{
    public int $deleteCalls = 0;
    public bool $throwOnDelete = false;

    public function delete(): void
    {
        $this->deleteCalls++;
        if ($this->throwOnDelete) {
            throw new \Error('row delete failed');
        }
    }
}

/**
 * A fake citation image service. Mirrors the vendor contract: setImage() stages
 * (or rejects) the source, updateImage() copies-then-saves (and so can throw
 * after staging), deleteFile() is the best-effort copied-file cleanup. Records
 * every call so the rollback decision is observable.
 *
 * The failure paths raise \Error subclasses, not \Exception ones, and that is
 * the point. What the attacher's guards exist for is vendor drift, and vendor
 * drift arrives as an \Error: an NF/Rosters update that renames
 * deleteImageForAwardDelete() is a "Call to undefined method" \Error, and the
 * adapter's setImage(string): bool raises a \TypeError the moment the vendor
 * service hands back null. Since PHP 7 neither is an \Exception, so a fixture
 * throwing \RuntimeException would let every catch (\Throwable) in
 * CitationAttacher be narrowed to catch (\Exception) with the suite still green
 * — quietly abandoning fail-open for exactly the failures it was written for.
 *
 * The mirror image is just as real, which is what $failWithException is for.
 * updateImage()'s everyday failure is \Exception-side (XF's file copy raises
 * XF\PrintableException, the save() behind it raises a DB exception), so
 * proving only the \Error half would leave the same guards narrowable to
 * catch (\Error). Each failure path is driven from both halves of the range,
 * and the messages are identical either way so the assertions do not care which.
 */
class FakeCitationImage implements CitationImage
{
    public int $setImageCalls = 0;
    public int $updateImageCalls = 0;
    public int $deleteFileCalls = 0;
    public bool $rejectImage = false;
    public bool $throwOnUpdate = false;
    public bool $throwOnDeleteFile = false;
    public string $rejectReason = 'not a valid image';

    /**
     * Reject by throwing rather than by returning false. This is the vendor's
     * OTHER way of saying no, and the one the CitationImage docblock used not to
     * admit: NF\Rosters' Image::setImage() delegates to validateImageForRecord(),
     * which raises \InvalidArgumentException for a missing source file and again
     * for an unreadable one, both before any branch that returns false. Modelling
     * only the false left the throwing half uncovered, and it is the half a
     * misdeployed citation asset actually takes.
     */
    public bool $throwOnSetImage = false;

    /**
     * Fail while producing the rejection reason. The vendor hands back an
     * \XF\Phrase and the production adapter renders it, which goes through the
     * phrase cache and can fail — while the award row is already saved.
     */
    public bool $throwOnErrorText = false;

    /** Raise the \Exception-side failures instead of the \Error-side defaults. */
    public bool $failWithException = false;

    public function setImage(string $path): bool
    {
        $this->setImageCalls++;
        if ($this->throwOnSetImage) {
            throw $this->failWithException
                ? new \InvalidArgumentException("Invalid file '$path' passed to image service")
                : new \Error("Invalid file '$path' passed to image service");
        }

        return !$this->rejectImage;
    }

    public function errorText(): string
    {
        if ($this->throwOnErrorText) {
            throw new \RuntimeException('phrase render failed');
        }

        return $this->rejectReason;
    }

    public function updateImage(): void
    {
        $this->updateImageCalls++;
        if ($this->throwOnUpdate) {
            throw $this->failWithException
                ? new \RuntimeException('save after copy failed')
                : new \TypeError('save after copy failed');
        }
    }

    public function deleteFile(): void
    {
        $this->deleteFileCalls++;
        if ($this->throwOnDeleteFile) {
            throw $this->failWithException
                ? new \RuntimeException('file cleanup failed')
                : new \Error('file cleanup failed');
        }
    }
}

/**
 * A logger standing in for the attacher's logFailure seam. The attacher hands it
 * the cleanup failure and a bare context saying what could not be cleaned; the
 * production gateway is what appends the PUC date the grant was for, stamps the
 * milpac identity onto that context, and forwards it to the error log. Recorded
 * here as (context => exception message).
 */
class RecordingLogger
{
    /** @var array<int, array{context: string, message: string}> */
    public array $entries = [];

    /**
     * Refuse the write. The seam reaches \XF::logException, whose own guard
     * catches \Exception only, so an \Error out of the error-log write escapes
     * to the caller — which is why the default here is \Error.
     */
    public bool $throwOnLog = false;

    /**
     * The same refusal from the \Exception half — an XF\Db\Exception on the
     * insert. Named to match FakeCitationImage's flag of the same meaning: the
     * two fakes model different seams but the same both-halves-of-\Throwable
     * idea, and one name for it keeps a reader from looking for a distinction.
     */
    public bool $failWithException = false;

    public function __invoke(\Throwable $e, string $context): void
    {
        if ($this->throwOnLog) {
            throw $this->failWithException
                ? new \RuntimeException('error log write failed')
                : new \Error('error log write failed');
        }

        $this->entries[] = ['context' => $context, 'message' => $e->getMessage()];
    }
}

$path = '/tmp/2003-03-18.jpg';

// --- Happy path: attach succeeds, nothing is rolled back -----------------
$logger = new RecordingLogger();
$award = new FakeCitationAward();
$image = new FakeCitationImage();
$threw = false;
try {
    (new CitationAttacher($logger))->attach($award, fn () => $image, $path);
} catch (\Throwable $e) {
    $threw = true;
}
check('a successful attach does not throw', !$threw);
check('a successful attach stages and commits the image', $image->setImageCalls === 1 && $image->updateImageCalls === 1);
check('a successful attach deletes nothing (no rollback)', $award->deleteCalls === 0 && $image->deleteFileCalls === 0);
check('a successful attach logs no rollback breadcrumb', $logger->entries === []);

// --- updateImage() throws after the copy: file + row rolled back, original surfaces
$logger = new RecordingLogger();
$award = new FakeCitationAward();
$image = new FakeCitationImage();
$image->throwOnUpdate = true;
$caught = null;
try {
    (new CitationAttacher($logger))->attach($award, fn () => $image, $path);
} catch (\Throwable $e) {
    $caught = $e;
}
check('a citation save failure propagates (apply() relies on this to log + retry)', $caught !== null);
check(
    'the ORIGINAL save failure is the one surfaced, not a rollback error',
    $caught !== null && $caught->getMessage() === 'save after copy failed',
    $caught !== null ? $caught->getMessage() : '(no throw)'
);
check('the copied citation file is cleaned up on a save failure (no orphan)', $image->deleteFileCalls === 1);
check('the award row is deleted on a save failure (no citationless row)', $award->deleteCalls === 1);
check('a clean rollback logs no breadcrumb', $logger->entries === []);
check(
    'the rollback is driven by a vendor-drift \Error, which no catch (\Exception) would have seen',
    $caught instanceof \Error && !$caught instanceof \Exception,
    $caught !== null ? get_class($caught) : '(no throw)'
);

// --- setImage() rejects before any copy: row rolled back, reason carried ----
$logger = new RecordingLogger();
$award = new FakeCitationAward();
$image = new FakeCitationImage();
$image->rejectImage = true;
$image->rejectReason = 'provided file is not a valid image';
$caught = null;
try {
    (new CitationAttacher($logger))->attach($award, fn () => $image, $path);
} catch (\Throwable $e) {
    $caught = $e;
}
check('a rejected image propagates a failure', $caught !== null);
check(
    'the rejection reason is carried in the surfaced error',
    $caught !== null && str_contains($caught->getMessage(), 'provided file is not a valid image'),
    $caught !== null ? $caught->getMessage() : '(no throw)'
);
check('a rejected image never commits (updateImage not called)', $image->updateImageCalls === 0);
check('a rejected image still rolls the saved row back', $award->deleteCalls === 1);

// --- Rollback that itself fails must NOT mask the original cause ------------
// Each cleanup step throws an \Error here as well, so narrowing either cleanup
// guard to catch (\Exception) lets that \Error out and mask the original.
$logger = new RecordingLogger();
$award = new FakeCitationAward();
$award->throwOnDelete = true;        // the compensating row delete fails
$image = new FakeCitationImage();
$image->throwOnUpdate = true;        // the real cause
$image->throwOnDeleteFile = true;    // the file cleanup also fails
$caught = null;
try {
    (new CitationAttacher($logger))->attach($award, fn () => $image, $path);
} catch (\Throwable $e) {
    $caught = $e;
}
check(
    'when both the file cleanup and the row rollback fail, the ORIGINAL cause still surfaces',
    $caught !== null && $caught->getMessage() === 'save after copy failed',
    $caught !== null ? $caught->getMessage() : '(no throw)'
);
check('a failed file cleanup is logged as a breadcrumb', count(array_filter(
    $logger->entries,
    fn ($e) => str_contains($e['context'], 'citation file cleanup failed')
)) === 1);
check('a failed row rollback is logged as a breadcrumb', count(array_filter(
    $logger->entries,
    fn ($e) => str_contains($e['context'], 'award rollback after citation failure failed')
)) === 1);
check('both cleanup attempts are still made even though each throws', $image->deleteFileCalls === 1 && $award->deleteCalls === 1);

// --- The same two failures from the \Exception half of the range ------------
// The \Error fixtures above close the vendor-drift blind spot; on their own they
// open its mirror image, because both of these guards could then be narrowed to
// catch (\Error) with the suite green. And \Exception is where the everyday
// failure actually lives: updateImage() copies through XF's filesystem
// abstraction (XF\PrintableException) before a save() that raises a DB
// exception. Driving each path from both halves pins both guards both ways.
$logger = new RecordingLogger();
$award = new FakeCitationAward();
$image = new FakeCitationImage();
$image->failWithException = true;
$image->throwOnUpdate = true;
$caught = null;
try {
    (new CitationAttacher($logger))->attach($award, fn () => $image, $path);
} catch (\Throwable $e) {
    $caught = $e;
}
check(
    'an \Exception-side citation save failure surfaces as the original cause too',
    $caught instanceof \Exception && $caught->getMessage() === 'save after copy failed',
    $caught !== null ? get_class($caught) . ': ' . $caught->getMessage() : '(no throw)'
);
check(
    'an \Exception-side save failure still rolls the file and the row back',
    $image->deleteFileCalls === 1 && $award->deleteCalls === 1,
    "deleteFile: {$image->deleteFileCalls}, delete: {$award->deleteCalls}"
);

// The file-cleanup guard specifically: an \Exception out of deleteFile() has to
// stay a breadcrumb, exactly as the \Error out of it does.
$logger = new RecordingLogger();
$award = new FakeCitationAward();
$image = new FakeCitationImage();
$image->failWithException = true;
$image->throwOnUpdate = true;        // the real cause
$image->throwOnDeleteFile = true;    // the \Exception-side cleanup failure
$caught = null;
try {
    (new CitationAttacher($logger))->attach($award, fn () => $image, $path);
} catch (\Throwable $e) {
    $caught = $e;
}
check(
    'an \Exception-side file cleanup failure does not mask the original cause',
    $caught !== null && $caught->getMessage() === 'save after copy failed',
    $caught !== null ? $caught->getMessage() : '(no throw)'
);
check('an \Exception-side file cleanup failure is logged as a breadcrumb', count(array_filter(
    $logger->entries,
    fn ($e) => str_contains($e['context'], 'citation file cleanup failed')
)) === 1);
check(
    'an \Exception-side file cleanup failure still lets the row be rolled back',
    $award->deleteCalls === 1,
    'delete calls: ' . $award->deleteCalls
);

// --- A re-run retries the date: a healthy attach after a failed one works ---
// The failed attach left no row (it was deleted), so the date is still pending;
// a subsequent attach with a working image service succeeds end to end.
$logger = new RecordingLogger();
$firstAward = new FakeCitationAward();
$firstImage = new FakeCitationImage();
$firstImage->throwOnUpdate = true;
try {
    (new CitationAttacher($logger))->attach($firstAward, fn () => $firstImage, $path);
} catch (\Throwable $e) {
    // expected — first run fails and rolls back
}
check('the failed first run deleted its row, so nothing citationless is left', $firstAward->deleteCalls === 1);

$secondAward = new FakeCitationAward();
$secondImage = new FakeCitationImage();
$reThrew = false;
try {
    (new CitationAttacher($logger))->attach($secondAward, fn () => $secondImage, $path);
} catch (\Throwable $e) {
    $reThrew = true;
}
check('a re-run of the same date succeeds once the image is healthy', !$reThrew);
check('the re-run commits the citation and rolls nothing back', $secondImage->updateImageCalls === 1 && $secondAward->deleteCalls === 0);

// --- Issue #168: rejection arriving as a THROW, not as a false --------------
// The award row is already saved when setImage() runs, so the two ways the
// vendor says no have to end the same way. This is the one a misdeployed
// citation asset takes: a date added to the bundled set without its JPG, or an
// _assets directory that lost its read permission in a deploy.
foreach ([false, true] as $exceptionSide) {
    $half = $exceptionSide ? '\Exception-side' : '\Error-side';

    $logger = new RecordingLogger();
    $award = new FakeCitationAward();
    $image = new FakeCitationImage();
    $image->throwOnSetImage = true;
    $image->failWithException = $exceptionSide;
    $caught = null;
    try {
        (new CitationAttacher($logger))->attach($award, fn () => $image, $path);
    } catch (\Throwable $e) {
        $caught = $e;
    }

    check(
        "a $half throw out of setImage() leaves no citationless row behind",
        $award->deleteCalls === 1,
        'delete calls: ' . $award->deleteCalls
    );
    check(
        "a $half throw out of setImage() never commits the citation",
        $image->updateImageCalls === 0
    );
    check(
        "a $half throw out of setImage() surfaces as the original cause",
        $caught !== null && str_contains($caught->getMessage(), 'passed to image service'),
        $caught === null ? '(no throw)' : $caught->getMessage()
    );
}

// --- Issue #168: the rejection reason itself failing ------------------------
// A plain false from setImage() means errorText() gets asked why, and the
// production adapter answers by rendering a vendor \XF\Phrase. That render can
// fail, and the row is saved by then.
$logger = new RecordingLogger();
$award = new FakeCitationAward();
$image = new FakeCitationImage();
$image->rejectImage = true;
$image->throwOnErrorText = true;
$caught = null;
try {
    (new CitationAttacher($logger))->attach($award, fn () => $image, $path);
} catch (\Throwable $e) {
    $caught = $e;
}
check(
    'a rejection whose reason cannot be rendered still rolls the saved row back',
    $award->deleteCalls === 1,
    'delete calls: ' . $award->deleteCalls
);
check(
    'the render failure is what surfaces, so the log names the real cause',
    $caught !== null && $caught->getMessage() === 'phrase render failed',
    $caught === null ? '(no throw)' : $caught->getMessage()
);

// --- Issue #168: resolving the image service failing ------------------------
// The resolution needs the saved record_id, so it cannot happen before the row
// exists. Handing it to attach() as a closure is what puts it inside the
// rollback's reach; done in the caller it would be in a window nothing covers.
// Raised as an \Error because that is the shape vendor drift takes — a renamed
// or removed NF\Rosters service class.
$logger = new RecordingLogger();
$award = new FakeCitationAward();
$caught = null;
try {
    (new CitationAttacher($logger))->attach(
        $award,
        fn () => throw new \Error('Call to undefined service NF\Rosters:AwardRecord\Image'),
        $path
    );
} catch (\Throwable $e) {
    $caught = $e;
}
check(
    'a failure resolving the image service still rolls the saved row back',
    $award->deleteCalls === 1,
    'delete calls: ' . $award->deleteCalls
);
check(
    'the resolution failure is the one surfaced',
    $caught !== null && str_contains($caught->getMessage(), 'undefined service'),
    $caught === null ? '(no throw)' : $caught->getMessage()
);
check(
    'nothing was staged, so no breadcrumb claims a file cleanup was attempted',
    $logger->entries === [],
    implode(' | ', array_column($logger->entries, 'context'))
);

// --- Issue #168: a broken log seam must not cost the rollback ---------------
// Two faults at once: the file cleanup fails, so a breadcrumb is logged, and the
// log seam itself refuses. The award row delete comes AFTER that breadcrumb, so
// an unguarded logging call takes the delete and the original failure with it —
// leaving the citationless row that pendingDates() then skips forever. Driven
// from both halves of the \Throwable range: \XF::logException's internal guard
// catches \Exception only, so an \Error escapes it, while the everyday failure
// (an XF\Db\Exception on the xf_error_log insert) is \Exception-side.
foreach ([false, true] as $exceptionSide) {
    $half = $exceptionSide ? '\Exception-side' : '\Error-side';

    $logger = new RecordingLogger();
    $logger->throwOnLog = true;
    $logger->failWithException = $exceptionSide;
    $award = new FakeCitationAward();
    $image = new FakeCitationImage();
    $image->throwOnUpdate = true;        // the real cause
    $image->throwOnDeleteFile = true;    // the cleanup failure that gets logged
    $caught = null;
    try {
        (new CitationAttacher($logger))->attach($award, fn () => $image, $path);
    } catch (\Throwable $e) {
        $caught = $e;
    }

    check(
        "a $half log-seam failure on the first breadcrumb still deletes the award row",
        $award->deleteCalls === 1,
        'delete calls: ' . $award->deleteCalls
    );
    check(
        "a $half log-seam failure still re-throws the ORIGINAL attach failure",
        $caught !== null && $caught->getMessage() === 'save after copy failed',
        $caught === null ? '(no throw)' : get_class($caught) . ': ' . $caught->getMessage()
    );
}

// Both breadcrumbs failing to log, with the row delete failing too: the original
// still comes back out rather than a logging error.
$logger = new RecordingLogger();
$logger->throwOnLog = true;
$award = new FakeCitationAward();
$award->throwOnDelete = true;
$image = new FakeCitationImage();
$image->throwOnUpdate = true;
$image->throwOnDeleteFile = true;
$caught = null;
try {
    (new CitationAttacher($logger))->attach($award, fn () => $image, $path);
} catch (\Throwable $e) {
    $caught = $e;
}
check(
    'a log seam that refuses both breadcrumbs still surfaces the original cause',
    $caught !== null && $caught->getMessage() === 'save after copy failed',
    $caught === null ? '(no throw)' : get_class($caught) . ': ' . $caught->getMessage()
);
check(
    'both cleanup attempts are still made when neither breadcrumb can be logged',
    $image->deleteFileCalls === 1 && $award->deleteCalls === 1,
    "deleteFile: {$image->deleteFileCalls}, delete: {$award->deleteCalls}"
);

// --- Summary --------------------------------------------------------------
if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
