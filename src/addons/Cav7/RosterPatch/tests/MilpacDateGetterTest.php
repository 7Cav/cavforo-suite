<?php

/**
 * Behavioural test for the milpac date getters (issue #47). RosterPatch's entity
 * extensions override the vendor getAwardDate()/getRecordDate() so a milpac award
 * date and service-record date render as their stored UTC calendar day whatever
 * the PHP process timezone, instead of the vendor's process-timezone date().
 *
 * The vendor NF\Rosters base classes are not in this repo, so we stand them in
 * with minimal stubs that carry the vendor's own getter — date('Y-m-d', ...),
 * which follows the process timezone. That is the behaviour the override
 * replaces: with the override removed the extension inherits this vendor getter
 * and the non-UTC assertions below fail, which is exactly the regression the
 * fix guards against.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/MilpacDateGetterTest.php
 */

namespace Cav7\RosterPatch\NF\Rosters\Entity {

    /**
     * Stand-in for the vendor NF\Rosters\Entity\RosterUserAward, modelling only
     * what the getter override touches: the date column as a plain property and
     * the vendor's process-timezone getter. isInsert()/isChanged()/_preSave()
     * exist so the extension class is well-formed; they are not exercised here.
     */
    class XFCP_RosterUserAward
    {
        public $award_date;

        public function getAwardDate(): string
        {
            return date('Y-m-d', (int) $this->award_date);
        }

        public function isInsert(): bool { return false; }
        public function isChanged(string $field): bool { return false; }
        protected function _preSave(): void {}
    }

    /** Stand-in for the vendor NF\Rosters\Entity\ServiceRecord (see above). */
    class XFCP_ServiceRecord
    {
        public $record_date;

        public function getRecordDate(): string
        {
            return date('Y-m-d', (int) $this->record_date);
        }

        public function isInsert(): bool { return false; }
        public function isChanged(string $field): bool { return false; }
        protected function _preSave(): void {}
    }
}

namespace Cav7\RosterPatch\Tests {

    use Cav7\RosterPatch\NF\Rosters\Entity\RosterUserAward;
    use Cav7\RosterPatch\NF\Rosters\Entity\ServiceRecord;

    require __DIR__ . '/../MilpacDate.php';
    require __DIR__ . '/../NF/Rosters/Entity/RosterUserAward.php';
    require __DIR__ . '/../NF/Rosters/Entity/ServiceRecord.php';

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

    /** Midnight-UTC epoch for a 'Y-m-d', computed independently of MilpacDate. */
    function midnightUtc(string $ymd): int
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone('UTC'))
            ->getTimestamp();
    }

    $award  = new RosterUserAward();
    $record = new ServiceRecord();

    // The stored value sits exactly at midnight UTC of its calendar day, the
    // canonical form RosterPatch floors to on save.
    $dayTs = midnightUtc('2026-06-29');

    // --- the getters are timezone-independent -------------------------------
    // Whatever the process timezone — far behind UTC, far ahead, or UTC itself —
    // the stored UTC day is what shows. This is the heart of the fix.
    foreach (['America/New_York', 'Australia/Sydney', 'Pacific/Kiritimati', 'Pacific/Pago_Pago', 'UTC'] as $tz) {
        date_default_timezone_set($tz);
        $award->award_date   = $dayTs;
        $record->record_date = $dayTs;
        check(
            "getAwardDate() renders the stored UTC day under process tz $tz",
            $award->getAwardDate() === '2026-06-29',
            'got: ' . $award->getAwardDate()
        );
        check(
            "getRecordDate() renders the stored UTC day under process tz $tz",
            $record->getRecordDate() === '2026-06-29',
            'got: ' . $record->getRecordDate()
        );
    }

    // --- the divergence the override removes --------------------------------
    // Because the value is midnight UTC, a timezone west of UTC renders the day
    // before through the vendor's date() path. The independent yardstick is
    // PHP's own date() under the same process timezone.
    date_default_timezone_set('America/New_York');
    $award->award_date = $dayTs;
    check(
        'the pre-fix vendor date() path would show the previous calendar day west of UTC',
        date('Y-m-d', $dayTs) === '2026-06-28',
        'got: ' . date('Y-m-d', $dayTs)
    );
    check(
        'getAwardDate() shows the stored UTC day, not the process-tz day',
        $award->getAwardDate() === '2026-06-29',
        'got: ' . $award->getAwardDate()
    );
    // A non-midnight instant is a sharper discriminator: 03:00 UTC on 2026-06-29
    // is 23:00 on 2026-06-28 in New York, so the vendor path renders 2026-06-28.
    $award->award_date = $dayTs + 3 * 3600;
    check(
        'getAwardDate() renders the UTC day for a mid-day timestamp, not the process-tz day',
        $award->getAwardDate() === '2026-06-29',
        'got: ' . $award->getAwardDate()
    );

    // --- under a UTC process tz the getters are unchanged -------------------
    // The whole point is that display no longer depends on the process timezone;
    // under UTC the override returns exactly what the vendor date() returned.
    date_default_timezone_set('UTC');
    $award->award_date   = $dayTs;
    $record->record_date = $dayTs;
    check(
        'under a UTC process tz getAwardDate() equals the vendor date() output (unchanged)',
        $award->getAwardDate() === date('Y-m-d', $dayTs),
        'got: ' . $award->getAwardDate()
    );
    check(
        'under a UTC process tz getRecordDate() equals the vendor date() output (unchanged)',
        $record->getRecordDate() === date('Y-m-d', $dayTs),
        'got: ' . $record->getRecordDate()
    );

    // --- an unset/zero value keeps the vendor's UTC-epoch day ---------------
    // The vendor renders date('Y-m-d', 0); render() yields the same UTC day, so
    // the override preserves it under UTC and holds it steady off UTC too.
    date_default_timezone_set('UTC');
    $award->award_date   = 0;
    $record->record_date = 0;
    check(
        'a zero award_date renders as the UTC epoch day, matching the vendor under UTC',
        $award->getAwardDate() === date('Y-m-d', 0) && $award->getAwardDate() === '1970-01-01',
        'got: ' . $award->getAwardDate()
    );
    check(
        'a zero record_date renders as the UTC epoch day, matching the vendor under UTC',
        $record->getRecordDate() === date('Y-m-d', 0) && $record->getRecordDate() === '1970-01-01',
        'got: ' . $record->getRecordDate()
    );
    date_default_timezone_set('America/New_York');
    $award->award_date   = 0;
    $record->record_date = 0;
    check(
        'a zero award_date stays the UTC epoch day whatever the process tz',
        $award->getAwardDate() === '1970-01-01',
        'got: ' . $award->getAwardDate()
    );
    check(
        'a zero record_date stays the UTC epoch day whatever the process tz',
        $record->getRecordDate() === '1970-01-01',
        'got: ' . $record->getRecordDate()
    );

    // --- Summary ------------------------------------------------------------
    if ($failures > 0) {
        echo "\n$failures test(s) FAILED\n";
        exit(1);
    }
    echo "\nAll tests passed\n";
    exit(0);
}
