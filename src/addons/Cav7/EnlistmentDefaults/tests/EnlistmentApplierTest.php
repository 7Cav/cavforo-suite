<?php

/**
 * What a new milpac gets from EnlistmentApplier, and what survives a failure, against a fake
 * gateway. The milpac identity on each log entry is tests/FailureLoggingTest.php's.
 *
 * Run:
 *   php tests/EnlistmentApplierTest.php
 */

namespace Cav7\EnlistmentDefaults\Tests;

require __DIR__ . '/../EnlistmentDecisions.php';
require __DIR__ . '/../EnlistmentGateway.php';
require __DIR__ . '/../EnlistmentApplier.php';

use Cav7\EnlistmentDefaults\EnlistmentApplier;
use Cav7\EnlistmentDefaults\EnlistmentDecisions;
use Cav7\EnlistmentDefaults\EnlistmentGateway;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

class FakeGateway implements EnlistmentGateway
{
    public int $syncs = 0;

    /** @var list<array{int, string, int}> */
    public array $records = [];

    /** @var list<array{context: string, exception: \Throwable}> */
    public array $logged = [];

    public ?\Throwable $syncThrows = null;
    public bool $recordThrows = false;
    public bool $logThrows = false;

    public function __construct(
        public bool $syncOn = true,
        public string $joinDate = '',
        public int $creationDate = 1600000000,
    ) {}

    public function pucSyncOn(): bool { return $this->syncOn; }
    public function enlistmentRecordTypeId(): int { return 3; }
    public function creationDate(): int { return $this->creationDate; }
    public function joinDate(): string { return $this->joinDate; }

    public function syncPucRows(): void
    {
        if ($this->syncThrows) {
            throw $this->syncThrows;
        }
        $this->syncs++;
    }

    public function writeServiceRecord(int $recordTypeId, string $body, int $recordDate): void
    {
        if ($this->recordThrows) {
            throw new \TypeError('vendor returned the wrong type');
        }
        $this->records[] = [$recordTypeId, $body, $recordDate];
    }

    public function logFailure(\Throwable $e, string $context): void
    {
        if ($this->logThrows) {
            throw new \Error('error log write failed');
        }
        $this->logged[] = ['context' => $context, 'exception' => $e];
    }
}

function applied(FakeGateway $gw): bool
{
    try {
        (new EnlistmentApplier($gw))->apply();
    } catch (\Throwable) {
        return false;
    }
    return true;
}

$creationDayMidnight = 1599955200; // 2020-09-13, the day 1600000000 falls on

$gw = new FakeGateway();
applied($gw);
check('with the sync on, a new milpac gets the PUC sync', $gw->syncs === 1);
check(
    'and one Transfer record with the canonical body, dated to the creation day when there is no Join Date',
    $gw->records === [[3, EnlistmentDecisions::ENLISTMENT_RECORD_BODY, $creationDayMidnight]],
    json_encode($gw->records)
);

$gw = new FakeGateway(syncOn: false);
applied($gw);
check('with the sync off, a new milpac gets no PUC rows, and still its record', $gw->syncs === 0 && count($gw->records) === 1);

$gw = new FakeGateway(joinDate: '2012-05-18');
applied($gw);
check('the record follows a submitted Join Date', ($gw->records[0][2] ?? null) === 1337299200, json_encode($gw->records));

$gw = new FakeGateway(joinDate: 'not-a-date');
applied($gw);
check('an unparseable Join Date falls back to the creation day', ($gw->records[0][2] ?? null) === $creationDayMidnight);

// Vendor drift arrives as an \Error, which no catch (\Exception) would see.
foreach ([new \RuntimeException('db gone'), new \Error('Call to undefined method getNewAward()')] as $thrown) {
    $gw = new FakeGateway();
    $gw->syncThrows = $thrown;
    $ok = applied($gw);
    check(
        'a failed PUC sync (' . get_class($thrown) . ') is logged whole and the record is still written',
        $ok && count($gw->records) === 1 && count($gw->logged) === 1 && $gw->logged[0]['exception'] === $thrown
            && $gw->logged[0]['context'] === 'failed to sync PUC rows',
        json_encode(array_column($gw->logged, 'context'))
    );
}

$gw = new FakeGateway();
$gw->recordThrows = true;
$ok = applied($gw);
check(
    'a failed record write is logged and the PUC sync still ran',
    $ok && $gw->syncs === 1 && count($gw->logged) === 1 && $gw->logged[0]['exception'] instanceof \TypeError
        && $gw->logged[0]['context'] === 'failed to write enlistment record'
);

// A broken error log costs the rest of the defaults, not the milpac save: the throw escapes
// apply(), and the entity extension's last-resort catch holds the save.
$gw = new FakeGateway();
$gw->syncThrows = new \RuntimeException('db gone');
$gw->logThrows = true;
$ok = applied($gw);
check('a broken log seam escapes apply() and costs the record', !$ok && $gw->records === []);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
