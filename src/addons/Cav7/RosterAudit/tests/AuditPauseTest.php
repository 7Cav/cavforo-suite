<?php

/**
 * AuditPause turns auditing back on when the wrapped write ends, including when it throws or
 * nests, so one failed PUC sync batch can't leave every later roster save unaudited.
 *
 * Run:
 *   php tests/AuditPauseTest.php
 */

namespace Cav7\RosterAudit\Tests;

use Cav7\RosterAudit\AuditPause;

require __DIR__ . '/../AuditPause.php';

$failures = 0;

function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
    $failures += $ok ? 0 : 1;
}

check('off by default', !AuditPause::active());

$seen = AuditPause::during(fn (): bool => AuditPause::active());
check('on inside the write', $seen);
check('off after the write', !AuditPause::active());

$inner = AuditPause::during(function (): bool {
    AuditPause::during(fn () => null);
    return AuditPause::active();
});
check('still on after a nested write ends', $inner);

try {
    AuditPause::during(function (): never {
        throw new \RuntimeException('batch failed');
    });
} catch (\RuntimeException) {
}
check('off after a write that throws', !AuditPause::active());

exit($failures === 0 ? 0 : 1);
