<?php

/**
 * Issue #24 — the EnlistmentApplier orchestration: what happens, in what order,
 * and what is resilient to failure, when a new milpac is enlisted.
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
 *    body, dated from the milpac's submitted Join Date (falling back to the
 *    creation date when the Join Date is blank or unparseable).
 *  - Fail-open: a grant that throws is logged and the remaining grants and the
 *    record still proceed; apply() never throws.
 *  - A failing record write is logged and does not abort; apply() never throws.
 *  - Idempotency: dates the milpac already carries are skipped (no re-grant).
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
 * to throw on a specific PUC date's grant or on the record write to exercise
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

    /** @var string[] */
    public array $loggedErrors = [];

    public ?string $throwOnDate = null;
    public bool $throwOnRecord = false;

    public function __construct(
        public int $pucAwardId = 61,
        public int $recordTypeId = 3,
        public int $visitorUserId = 42,
        public int $systemFallbackUserId = 1,
        public int $creationDate = 1600000000,
        public string $joinDate = '',
        public string $boardTimezone = 'UTC'
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
    public function boardTimezone(): string { return $this->boardTimezone; }

    public function grantAward(int $awardId, int $awardDate, int $fromUserId, string $citationPath): void
    {
        $date = gmdate('Y-m-d', $awardDate);
        if ($this->throwOnDate !== null && $date === $this->throwOnDate) {
            throw new \RuntimeException("boom on $date");
        }
        $this->granted[] = new GrantedAward($awardId, $awardDate, $fromUserId, $citationPath);
    }

    public function writeServiceRecord(int $recordTypeId, string $body, int $recordDate): void
    {
        if ($this->throwOnRecord) {
            throw new \RuntimeException('boom on record');
        }
        $this->records[] = new WrittenRecord($recordTypeId, $body, $recordDate);
    }

    public function logError(string $message): void
    {
        $this->loggedErrors[] = $message;
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
check(
    'with no Join Date submitted, the record falls back to the creation date',
    count($gw->records) === 1 && $gw->records[0]->recordDate === 1600000000,
    var_export($gw->records, true)
);

// --- The enlistment record follows the submitted Join Date ---------------
$gw = new FakeGateway(joinDate: '2012-05-18', boardTimezone: 'UTC');
(new EnlistmentApplier($gw))->apply();
$expected = EnlistmentDecisions::enlistmentRecordDate('2012-05-18', 1600000000, 'UTC');
check(
    'a submitted Join Date dates the enlistment record (not the creation moment)',
    count($gw->records) === 1
        && $gw->records[0]->recordDate === $expected
        && $gw->records[0]->recordDate !== 1600000000,
    var_export($gw->records, true)
);

// A non-UTC board timezone is carried through to the record date: the record is
// dated to the Join Date at that zone's midnight, distinct from the UTC instant.
// This pins the gateway-timezone -> decision wiring at the applier level.
$gw = new FakeGateway(joinDate: '2012-05-18', boardTimezone: 'America/New_York');
(new EnlistmentApplier($gw))->apply();
$expectedNy = EnlistmentDecisions::enlistmentRecordDate('2012-05-18', 1600000000, 'America/New_York');
$expectedUtc = EnlistmentDecisions::enlistmentRecordDate('2012-05-18', 1600000000, 'UTC');
check(
    'the board timezone is passed through to the record date (non-UTC midnight, distinct from UTC)',
    count($gw->records) === 1
        && $gw->records[0]->recordDate === $expectedNy
        && $gw->records[0]->recordDate !== $expectedUtc,
    var_export($gw->records, true)
);

// An unparseable Join Date still produces a valid record, dated to creation.
$gw = new FakeGateway(joinDate: 'not-a-date');
(new EnlistmentApplier($gw))->apply();
check(
    'an unparseable Join Date falls back to the creation date for the record',
    count($gw->records) === 1 && $gw->records[0]->recordDate === 1600000000,
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
$gw->throwOnDate = '2009-08-10';
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
check('the failing grant is logged to the error log', count($gw->loggedErrors) >= 1);
check('the enlistment record is still written after a failed grant', count($gw->records) === 1);

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
check('the failing record write is logged to the error log', count($gw->loggedErrors) >= 1);

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
