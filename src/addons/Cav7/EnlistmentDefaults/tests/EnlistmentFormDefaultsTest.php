<?php

/**
 * Issue #32 — the pure form-default rules behind the add-milpac prefill, isolated
 * from the XenForo controller and entity world so the computation is covered
 * without booting XenForo.
 *
 * Rule under test (Cav7\EnlistmentDefaults\EnlistmentFormDefaults::compute):
 * given the configured rank id, the configured position id, the roster's
 * available position ids, the current time and the board timezone, return the
 * add-form's initial state — rank_id, position_id (or null when the roster does
 * not list the configured position), and joinDate and promoDate as 'Y-m-d' in
 * board time.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/EnlistmentFormDefaultsTest.php
 */

namespace Cav7\EnlistmentDefaults\Tests;

require __DIR__ . '/../EnlistmentFormDefaults.php';

use Cav7\EnlistmentDefaults\EnlistmentFormDefaults;

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

// A fixed instant: 2026-06-16 19:30:00 UTC. In America/New_York (UTC-4 in June)
// this is still 2026-06-16 locally; in Australia/Sydney (UTC+10) it is already
// 2026-06-17. The date the form shows must follow the board timezone, not UTC.
$now = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', '2026-06-16 19:30:00', new \DateTimeZone('UTC'))
    ->getTimestamp();

// --- Configured rank and position pass through ---------------------------
$d = EnlistmentFormDefaults::compute(23, 193, [10, 193, 200], $now, 'UTC');
check('the configured rank id passes through', $d['rank_id'] === 23, 'got: ' . var_export($d['rank_id'], true));
check(
    'the configured position id passes through when the roster lists it',
    $d['position_id'] === 193,
    'got: ' . var_export($d['position_id'], true)
);

// --- Position null when the roster does not list it ----------------------
$d = EnlistmentFormDefaults::compute(23, 193, [10, 200, 305], $now, 'UTC');
check(
    'the position is null when the roster does not list the configured position',
    $d['position_id'] === null,
    'got: ' . var_export($d['position_id'], true)
);
check('the rank still passes through on a roster missing the position', $d['rank_id'] === 23);

// An empty position list (a roster with no positions) also yields null.
$d = EnlistmentFormDefaults::compute(23, 193, [], $now, 'UTC');
check(
    'the position is null when the roster lists no positions at all',
    $d['position_id'] === null,
    'got: ' . var_export($d['position_id'], true)
);

// --- Dates are formatted Y-m-d in the supplied timezone ------------------
$d = EnlistmentFormDefaults::compute(23, 193, [193], $now, 'UTC');
check('joinDate is the current date in UTC, Y-m-d', $d['joinDate'] === '2026-06-16', 'got: ' . $d['joinDate']);
check('promoDate is the current date in UTC, Y-m-d', $d['promoDate'] === '2026-06-16', 'got: ' . $d['promoDate']);

// The same instant in a behind-UTC zone (still the 16th locally).
$d = EnlistmentFormDefaults::compute(23, 193, [193], $now, 'America/New_York');
check(
    'joinDate follows a behind-UTC board timezone',
    $d['joinDate'] === '2026-06-16',
    'got: ' . $d['joinDate']
);

// The same instant in an ahead-of-UTC zone has already rolled to the 17th.
$d = EnlistmentFormDefaults::compute(23, 193, [193], $now, 'Australia/Sydney');
check(
    'joinDate follows an ahead-of-UTC board timezone (date has rolled over)',
    $d['joinDate'] === '2026-06-17',
    'got: ' . $d['joinDate']
);
check(
    'promoDate matches joinDate for the same instant and timezone',
    $d['joinDate'] === $d['promoDate'],
    "join={$d['joinDate']} promo={$d['promoDate']}"
);

// --- Summary --------------------------------------------------------------
if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
