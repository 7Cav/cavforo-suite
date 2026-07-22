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

    public function setImage(string $path): bool
    {
        $this->setImageCalls++;
        return !$this->rejectImage;
    }

    public function errorText(): string
    {
        return $this->rejectReason;
    }

    public function updateImage(): void
    {
        $this->updateImageCalls++;
        if ($this->throwOnUpdate) {
            throw new \TypeError('save after copy failed');
        }
    }

    public function deleteFile(): void
    {
        $this->deleteFileCalls++;
        if ($this->throwOnDeleteFile) {
            throw new \Error('file cleanup failed');
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

    public function __invoke(\Throwable $e, string $context): void
    {
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
    (new CitationAttacher($logger))->attach($award, $image, $path);
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
    (new CitationAttacher($logger))->attach($award, $image, $path);
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
    (new CitationAttacher($logger))->attach($award, $image, $path);
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
    (new CitationAttacher($logger))->attach($award, $image, $path);
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

// --- A re-run retries the date: a healthy attach after a failed one works ---
// The failed attach left no row (it was deleted), so the date is still pending;
// a subsequent attach with a working image service succeeds end to end.
$logger = new RecordingLogger();
$firstAward = new FakeCitationAward();
$firstImage = new FakeCitationImage();
$firstImage->throwOnUpdate = true;
try {
    (new CitationAttacher($logger))->attach($firstAward, $firstImage, $path);
} catch (\Throwable $e) {
    // expected — first run fails and rolls back
}
check('the failed first run deleted its row, so nothing citationless is left', $firstAward->deleteCalls === 1);

$secondAward = new FakeCitationAward();
$secondImage = new FakeCitationImage();
$reThrew = false;
try {
    (new CitationAttacher($logger))->attach($secondAward, $secondImage, $path);
} catch (\Throwable $e) {
    $reThrew = true;
}
check('a re-run of the same date succeeds once the image is healthy', !$reThrew);
check('the re-run commits the citation and rolls nothing back', $secondImage->updateImageCalls === 1 && $secondAward->deleteCalls === 0);

// --- Summary --------------------------------------------------------------
if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
