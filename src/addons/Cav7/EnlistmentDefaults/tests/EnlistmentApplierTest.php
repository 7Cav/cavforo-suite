<?php

/**
 * Issues #24, #45 and #62 — the EnlistmentApplier orchestration: what happens,
 * in what order, and what is resilient to failure, when a new milpac is enlisted.
 *
 * The applier is the deep module that turns the pure decisions into concrete
 * grants and the enlistment record. Its collaborators (the entity world: award
 * creation, citation attachment, record writing, error logging, the visitor and
 * options) are behind a small Gateway seam so the orchestration is exercised
 * here with no XenForo. The real RosterUser entity extension wires the same
 * applier to the vendor's factories and services.
 *
 * Covered (acceptance criteria expressible without a database):
 *  - A fresh milpac gets one PUC grant per bundled date, each with its citation
 *    attached, in earned order, all attributed to the resolved user.
 *  - Exactly one Transfer-typed enlistment record is written, with the canonical
 *    body, dated from the milpac's submitted Join Date at midnight UTC (falling
 *    back to midnight UTC of the creation day when the Join Date is blank or
 *    unparseable).
 *  - Fail-open: a grant that throws is logged and the remaining grants and the
 *    record still proceed; apply() never throws.
 *  - A failing record write is logged and does not abort; apply() never throws.
 *  - Idempotency: dates the milpac already carries are skipped (no re-grant).
 *  - A dropped grant is diagnosable: the applier hands the gateway the original
 *    exception (so its class and trace survive) plus a context naming the date
 *    that dropped. The milpac identity is stamped by the gateway, which holds
 *    the entity — see tests/FailureLoggingTest.php.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/EnlistmentApplierTest.php
 */

namespace Cav7\EnlistmentDefaults\Tests;

require __DIR__ . '/../PucSet.php';
require __DIR__ . '/../EnlistmentDecisions.php';
require __DIR__ . '/../EnlistmentGateway.php';
require __DIR__ . '/../EnlistmentApplier.php';

use Cav7\EnlistmentDefaults\EnlistmentApplier;
use Cav7\EnlistmentDefaults\EnlistmentDecisions;
use Cav7\EnlistmentDefaults\EnlistmentGateway;
use Cav7\EnlistmentDefaults\PucSet;

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
 * A record of one granted award, as the gateway saw it.
 */
class GrantedAward
{
    public function __construct(
        public int $awardId,
        public int $awardDate,
        public int $fromUserId,
        public ?string $citationPath
    ) {}
}

class WrittenRecord
{
    public function __construct(
        public int $recordTypeId,
        public string $body,
        public int $recordDate
    ) {}
}

/**
 * A fake entity world. Records what the applier asked it to do, and can be told
 * to fail the grants for any set of PUC dates, or the record write, to exercise
 * fail-open.
 */
class FakeGateway implements EnlistmentGateway
{
    /** @var int[] award_date timestamps already on the milpac for the PUC award */
    public array $existingAwardDates = [];

    /** @var GrantedAward[] */
    public array $granted = [];

    /** @var WrittenRecord[] */
    public array $records = [];

    /** @var array<int, array{context: string, exception: \Throwable}> */
    public array $loggedFailures = [];

    /** The exception instances this fake threw, so a test can prove the same
     *  object reached the log seam rather than a flattened message.
     *  @var \Throwable[] */
    public array $thrown = [];

    /** @var string[] PUC dates whose grant throws; empty means every grant lands */
    public array $throwOnDates = [];

    /**
     * PUC dates whose grant raises an \Error rather than an \Exception — what a
     * vendor rename or a changed return type actually looks like from inside
     * grantAward(). Kept apart from $throwOnDates so a test can say which of the
     * two it is exercising.
     *
     * @var string[]
     */
    public array $errorOnDates = [];

    public bool $throwOnRecord = false;

    public function __construct(
        public int $pucAwardId = 61,
        public int $recordTypeId = 3,
        public int $visitorUserId = 42,
        public int $systemFallbackUserId = 1,
        public int $creationDate = 1600000000,
        public string $joinDate = ''
    ) {}

    public function existingPucAwardDates(): array
    {
        return $this->existingAwardDates;
    }

    public function pucAwardId(): int { return $this->pucAwardId; }
    public function enlistmentRecordTypeId(): int { return $this->recordTypeId; }
    public function visitorUserId(): int { return $this->visitorUserId; }
    public function systemFallbackUserId(): int { return $this->systemFallbackUserId; }
    public function creationDate(): int { return $this->creationDate; }
    public function joinDate(): string { return $this->joinDate; }

    public function grantAward(int $awardId, int $awardDate, int $fromUserId, string $citationPath): void
    {
        $date = gmdate('Y-m-d', $awardDate);
        if (in_array($date, $this->throwOnDates, true)) {
            $e = new \DomainException("boom on $date");
            $this->thrown[] = $e;
            throw $e;
        }
        if (in_array($date, $this->errorOnDates, true)) {
            $e = new \Error("Call to undefined method NF\\Rosters\\Entity\\RosterUser::getNewAward() on $date");
            $this->thrown[] = $e;
            throw $e;
        }
        $this->granted[] = new GrantedAward($awardId, $awardDate, $fromUserId, $citationPath);
    }

    public function writeServiceRecord(int $recordTypeId, string $body, int $recordDate): void
    {
        if ($this->throwOnRecord) {
            $e = new \DomainException('boom on record');
            $this->thrown[] = $e;
            throw $e;
        }
        $this->records[] = new WrittenRecord($recordTypeId, $body, $recordDate);
    }

    public function logFailure(\Throwable $e, string $context): void
    {
        $this->loggedFailures[] = ['context' => $context, 'exception' => $e];
    }
}

// --- Happy path: full set granted, one record written --------------------
$gw = new FakeGateway();
(new EnlistmentApplier($gw))->apply();

check(
    'a fresh milpac gets one grant per bundled PUC date',
    count($gw->granted) === count(PucSet::dates()),
    'granted: ' . count($gw->granted)
);

$grantedDates = array_map(fn (GrantedAward $g) => gmdate('Y-m-d', $g->awardDate), $gw->granted);
check(
    'grants land on exactly the bundled dates, in earned order',
    $grantedDates === PucSet::dates(),
    'got: ' . implode(', ', $grantedDates)
);

$allPuc = array_reduce(
    $gw->granted,
    fn ($carry, GrantedAward $g) => $carry && $g->awardId === 61,
    true
);
check('every grant is the configured PUC award (61)', $allPuc);

$allAttributed = array_reduce(
    $gw->granted,
    fn ($carry, GrantedAward $g) => $carry && $g->fromUserId === 42,
    true
);
check('every grant is attributed to the acting visitor', $allAttributed);

$allCitations = array_reduce(
    $gw->granted,
    fn ($carry, GrantedAward $g) =>
        $carry && $g->citationPath === PucSet::citationPath(gmdate('Y-m-d', $g->awardDate)),
    true
);
check('every grant carries its date-matched bundled citation', $allCitations);

check('exactly one service record is written', count($gw->records) === 1);
check(
    'the record is Transfer-typed with the canonical body, dated from the gateway',
    count($gw->records) === 1
        && $gw->records[0]->recordTypeId === 3
        && $gw->records[0]->body === EnlistmentDecisions::ENLISTMENT_RECORD_BODY,
    var_export($gw->records, true)
);
$creationDayMidnight = \DateTimeImmutable::createFromFormat('!Y-m-d', gmdate('Y-m-d', 1600000000), new \DateTimeZone('UTC'))
    ->getTimestamp();
check(
    'with no Join Date submitted, the record falls back to midnight UTC of the creation day',
    count($gw->records) === 1 && $gw->records[0]->recordDate === $creationDayMidnight,
    var_export($gw->records, true)
);

// --- The enlistment record follows the submitted Join Date, at midnight UTC ---
$gw = new FakeGateway(joinDate: '2012-05-18');
(new EnlistmentApplier($gw))->apply();
$expected = EnlistmentDecisions::enlistmentRecordDate('2012-05-18', 1600000000);
check(
    'a submitted Join Date dates the enlistment record (not the creation moment)',
    count($gw->records) === 1
        && $gw->records[0]->recordDate === $expected
        && $gw->records[0]->recordDate !== 1600000000,
    var_export($gw->records, true)
);

// Issue #45: the record is stamped at midnight UTC of the Join Date's day,
// independent of the board timezone (not consulted for the stamp). It lands on
// the same instant PucSet stamps a PUC grant for the same calendar day, so
// EnlistmentDefaults and RosterPatch agree on any board.
check(
    'the Join Date stamps midnight UTC, matching the PUC grant convention',
    count($gw->records) === 1
        && $gw->records[0]->recordDate === PucSet::awardDateTimestamp('2012-05-18'),
    var_export($gw->records, true)
);

// An unparseable Join Date still produces a valid record, dated to midnight UTC
// of the creation day (issue #45: the fallback floors, it is not the raw instant).
$gw = new FakeGateway(joinDate: 'not-a-date');
(new EnlistmentApplier($gw))->apply();
check(
    'an unparseable Join Date falls back to midnight UTC of the creation day',
    count($gw->records) === 1
        && $gw->records[0]->recordDate === $creationDayMidnight
        && $gw->records[0]->recordDate !== 1600000000,
    var_export($gw->records, true)
);

// --- CLI attribution falls back to the system user -----------------------
$gw = new FakeGateway(visitorUserId: 0, systemFallbackUserId: 7);
(new EnlistmentApplier($gw))->apply();
$allFallback = array_reduce(
    $gw->granted,
    fn ($carry, GrantedAward $g) => $carry && $g->fromUserId === 7,
    true
);
check('with no visitor (CLI), grants are attributed to the system fallback user', $allFallback);

// --- Fail-open: a grant that throws is logged, the rest still proceed -----
$gw = new FakeGateway();
$gw->throwOnDates = ['2009-08-10'];
$threw = false;
try {
    (new EnlistmentApplier($gw))->apply();
} catch (\Throwable $e) {
    $threw = true;
}
check('apply() never throws even when a grant fails', !$threw);
check(
    'the five grants other than the failing date still land',
    count($gw->granted) === count(PucSet::dates()) - 1,
    'granted: ' . count($gw->granted)
);
check('the failing grant is logged to the error log', count($gw->loggedFailures) === 1);

// Issue #62: the log entry has to be actionable. The applier owns the half of
// that it can see — which date dropped, and the failure itself — while the
// gateway stamps the milpac identity from the entity it holds.
check(
    'the dropped grant is logged with a context naming the date that dropped',
    count($gw->loggedFailures) === 1
        && str_contains($gw->loggedFailures[0]['context'], '2009-08-10'),
    var_export(array_column($gw->loggedFailures, 'context'), true)
);
check(
    'the original exception object reaches the log seam, so its class and trace survive',
    count($gw->loggedFailures) === 1
        && count($gw->thrown) === 1
        && $gw->loggedFailures[0]['exception'] === $gw->thrown[0],
    count($gw->loggedFailures) === 1 ? get_class($gw->loggedFailures[0]['exception']) : ''
);
check('the enlistment record is still written after a failed grant', count($gw->records) === 1);

// --- Fail-open covers \Error, not only \Exception ------------------------
// The failure the guard around a grant is really there for is vendor drift: an
// NF/Rosters update renames getNewAward(), and PHP raises "Call to undefined
// method" — an \Error, which since PHP 7 is not an \Exception. If the grant
// guard only saw \Exception, that update would take the throw all the way out
// of _postSave() and nobody could be enlisted at all, while a suite whose
// fixtures throw \RuntimeException reported every test passing.
$gw = new FakeGateway();
$gw->errorOnDates = ['2009-08-10'];
$threw = false;
try {
    (new EnlistmentApplier($gw))->apply();
} catch (\Throwable $e) {
    $threw = true;
}
check('apply() never throws when a grant fails with a vendor-drift \Error', !$threw);
check(
    'the \Error is the one logged, so no catch (\Exception) could have produced this',
    count($gw->loggedFailures) === 1
        && $gw->loggedFailures[0]['exception'] instanceof \Error
        && !$gw->loggedFailures[0]['exception'] instanceof \Exception,
    count($gw->loggedFailures) === 1 ? get_class($gw->loggedFailures[0]['exception']) : 'nothing logged'
);
check(
    'the grants after the \Error still land, and the record is still written',
    count($gw->granted) === count(PucSet::dates()) - 1 && count($gw->records) === 1,
    'granted: ' . count($gw->granted) . ', records: ' . count($gw->records)
);

// --- The worst case: every grant fails -----------------------------------
// The milpac is still enlisted, and the log carries one entry per dropped date
// rather than one entry for the whole set, so a reader can see which dates the
// member is short of.
$gw = new FakeGateway();
$gw->throwOnDates = PucSet::dates();
$threw = false;
try {
    (new EnlistmentApplier($gw))->apply();
} catch (\Throwable $e) {
    $threw = true;
}
check('apply() never throws even when every grant fails', !$threw);
check('the enlistment record is still written when every grant fails', count($gw->records) === 1);
$loggedDates = array_map(
    fn (array $entry) => substr($entry['context'], -10),
    $gw->loggedFailures
);
check(
    'every dropped date gets its own log entry, naming that date',
    $loggedDates === PucSet::dates(),
    implode(', ', $loggedDates)
);

// --- Fail-open: a failing record write is logged, does not abort ----------
$gw = new FakeGateway();
$gw->throwOnRecord = true;
$threw = false;
try {
    (new EnlistmentApplier($gw))->apply();
} catch (\Throwable $e) {
    $threw = true;
}
check('apply() never throws even when the record write fails', !$threw);
check('all grants still land when the record write fails', count($gw->granted) === count(PucSet::dates()));
check('the failing record write is logged to the error log', count($gw->loggedFailures) === 1);
check(
    'the failed record write is logged with a context naming the record, and the original exception',
    count($gw->loggedFailures) === 1
        && str_contains($gw->loggedFailures[0]['context'], 'enlistment record')
        && count($gw->thrown) === 1
        && $gw->loggedFailures[0]['exception'] === $gw->thrown[0],
    var_export(array_column($gw->loggedFailures, 'context'), true)
);

// --- Idempotency: already-carried dates are skipped -----------------------
$gw = new FakeGateway();
$gw->existingAwardDates = [
    PucSet::awardDateTimestamp('2003-03-18'),
    PucSet::awardDateTimestamp('2010-09-18'),
    PucSet::awardDateTimestamp('2021-05-16'),
];
(new EnlistmentApplier($gw))->apply();
$grantedDates = array_map(fn (GrantedAward $g) => gmdate('Y-m-d', $g->awardDate), $gw->granted);
check(
    'a re-run grants only the dates the milpac does not already carry',
    $grantedDates === ['2004-09-01', '2009-08-10', '2011-06-02'],
    'got: ' . implode(', ', $grantedDates)
);

// A milpac already carrying the full set gets no new grants, but the applier
// path is only reached on insert; here we prove the grant loop itself is inert.
$gw = new FakeGateway();
$gw->existingAwardDates = array_map([PucSet::class, 'awardDateTimestamp'], PucSet::dates());
(new EnlistmentApplier($gw))->apply();
check('a milpac already carrying the full set gets no new grants', $gw->granted === []);

// --- Summary --------------------------------------------------------------
if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
