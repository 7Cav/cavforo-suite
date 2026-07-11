<?php

/**
 * Issue #24 — the bundled PUC set: the six dated Presidential Unit Citation
 * grants every milpac receives, and the citation JPG that ships with each.
 *
 * PucSet is pure, XenForo-free data: it owns the six dates (2003-03-18,
 * 2004-09-01, 2009-08-10, 2010-09-18, 2011-06-02, 2021-05-16), maps each to its
 * bundled citation image under _assets/puc-citations/, and converts each date to
 * the unix timestamp the addon stamps on the matching RosterUserAward.award_date.
 *
 * The set is bundled, not derived at runtime (ADR-0001): these assertions pin
 * the exact dates and that every citation file is present on disk, so a missing
 * or renamed asset fails CI rather than silently shipping fewer grants.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/PucSetTest.php
 */

namespace Cav7\EnlistmentDefaults\Tests;

require __DIR__ . '/../PucSet.php';

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

// --- The six bundled dates, in earned order -------------------------------
$expectedDates = [
    '2003-03-18',
    '2004-09-01',
    '2009-08-10',
    '2010-09-18',
    '2011-06-02',
    '2021-05-16',
];

check(
    'PucSet exposes exactly the six standing PUC dates, in earned order',
    PucSet::dates() === $expectedDates,
    'got: ' . implode(', ', PucSet::dates())
);

// --- Each date maps to a bundled citation JPG that exists on disk ----------
$allExist = true;
$missing = [];
foreach ($expectedDates as $date) {
    $path = PucSet::citationPath($date);
    if (substr($path, -strlen("/$date.jpg")) !== "/$date.jpg") {
        $allExist = false;
        $missing[] = "$date -> wrong filename: $path";
        continue;
    }
    if (!is_file($path)) {
        $allExist = false;
        $missing[] = "$date -> missing file: $path";
    }
}
check(
    'every PUC date maps to a bundled citation JPG present under _assets/puc-citations/',
    $allExist,
    implode('; ', $missing)
);

// --- The citation path lives inside the addon, named by its date ----------
check(
    'citation path is the date-named asset bundled in the addon',
    str_ends_with(PucSet::citationPath('2021-05-16'), '_assets/puc-citations/2021-05-16.jpg'),
    PucSet::citationPath('2021-05-16')
);

// --- Dates convert to the right midnight-UTC timestamps -------------------
// award_date is an int column; the timestamp must round-trip back to the date.
$tsOk = true;
$tsDetail = [];
foreach ($expectedDates as $date) {
    $ts = PucSet::awardDateTimestamp($date);
    $roundTrip = gmdate('Y-m-d', $ts);
    if ($roundTrip !== $date) {
        $tsOk = false;
        $tsDetail[] = "$date -> $ts -> $roundTrip";
    }
}
check(
    'awardDateTimestamp() round-trips each date through a unix timestamp',
    $tsOk,
    implode('; ', $tsDetail)
);

// A concrete anchor: 2003-03-18 00:00:00 UTC.
check(
    '2003-03-18 maps to its midnight-UTC timestamp',
    PucSet::awardDateTimestamp('2003-03-18') === gmmktime(0, 0, 0, 3, 18, 2003),
    (string) PucSet::awardDateTimestamp('2003-03-18')
);

// --- An unknown date is rejected (the set is closed, bundled data) ---------
$threw = false;
try {
    PucSet::citationPath('1999-01-01');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('citationPath() rejects a date outside the bundled set', $threw);

// --- A malformed date is rejected by awardDateTimestamp() ------------------
// The award_date column is an int; a date that does not parse must throw rather
// than silently stamp a wrong (or zero) timestamp on a grant.
$threwMalformed = false;
try {
    PucSet::awardDateTimestamp('not-a-date');
} catch (\InvalidArgumentException $e) {
    $threwMalformed = true;
}
check('awardDateTimestamp() rejects a malformed date', $threwMalformed);

// An empty string is a plainly malformed value too, and must throw.
$threwEmpty = false;
try {
    PucSet::awardDateTimestamp('');
} catch (\InvalidArgumentException $e) {
    $threwEmpty = true;
}
check('awardDateTimestamp() rejects an empty string', $threwEmpty);

// --- An out-of-range date is rejected, not silently rolled over (issue #48) -
// createFromFormat('!Y-m-d', ...) is lenient: '2024-15-01' does not fail, it
// rolls the 15th month over into 2025-03-01 and returns a valid timestamp. A
// typo in a bundled DATES entry must throw loudly rather than stamp a silently
// wrong award_date, so the round-trip guard rejects any value that does not
// format back to the exact input.
$threwRollover = false;
try {
    PucSet::awardDateTimestamp('2024-15-01');
} catch (\InvalidArgumentException $e) {
    $threwRollover = true;
}
check('awardDateTimestamp() rejects an out-of-range date instead of rolling it over', $threwRollover);

// A day-overflow value rolls over the same way: createFromFormat('!Y-m-d', ...)
// turns '2024-02-30' into '2024-03-01'. The round-trip guard must reject it too.
$threwDayOverflow = false;
try {
    PucSet::awardDateTimestamp('2024-02-30');
} catch (\InvalidArgumentException $e) {
    $threwDayOverflow = true;
}
check('awardDateTimestamp() rejects a day-overflow date instead of rolling it over', $threwDayOverflow);

// The case the guard exists for: createFromFormat accepts a single-digit
// '2024-1-1' and parses it to a real date, so '!Y-m-d' does not fail. Only the
// format('Y-m-d') !== $date round-trip guard rejects it — proof the guard is
// load-bearing and not redundant with createFromFormat's own validation.
$threwSingleDigit = false;
try {
    PucSet::awardDateTimestamp('2024-1-1');
} catch (\InvalidArgumentException $e) {
    $threwSingleDigit = true;
}
check('awardDateTimestamp() rejects a single-digit date the format guard alone catches', $threwSingleDigit);

// --- The bundled default record-type option is the Transfer type id --------
// This addon exists to stop the historical drift where the first record was
// written under the wrong type. Pin the shipped default so a stray edit to
// _data/options.xml fails CI. CONTEXT.md fixes the Transfer type id at 3.
$optionsXml = simplexml_load_file(__DIR__ . '/../_data/options.xml');
check(
    'options.xml could be read',
    $optionsXml !== false
);
$recordTypeDefault = null;
if ($optionsXml !== false) {
    foreach ($optionsXml->option as $option) {
        if ((string) $option['option_id'] === 'cav7EnlistDefRecordTypeId') {
            $recordTypeDefault = (string) $option->default_value;
        }
    }
}
check(
    'the default enlistment record-type option is the Transfer type id (3)',
    $recordTypeDefault === '3',
    'got: ' . var_export($recordTypeDefault, true)
);

// --- Summary --------------------------------------------------------------
if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
