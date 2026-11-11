<?php

/**
 * The PUC sync's plan for one milpac, and the counts a run of it produces.
 *
 * Run:
 *   php tests/PucPlanTest.php
 */

namespace Cav7\EnlistmentDefaults\Tests;

require __DIR__ . '/../PucCounts.php';
require __DIR__ . '/../PucPlan.php';

use Cav7\EnlistmentDefaults\PucCounts;
use Cav7\EnlistmentDefaults\PucPlan;

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

const PUC = 61;
const OTHER_REGIMENT_AWARD = 99;
const MSM = 40;
const D2003 = 1047945600;
const D2009 = 1249862400;
const D2021 = 1621123200;

function row(int $recordId, int $awardId, int $awardDate, ?int $grantId, string $details = ''): array
{
    return ['record_id' => $recordId, 'award_id' => $awardId, 'award_date' => $awardDate, 'details' => $details, 'citation_regiment_grant_id' => $grantId];
}

/** @return array{PucCounts, list<string>} */
function runPlan(PucPlan $plan, ?\Closure $create = null): array
{
    $counts = new PucCounts();
    $failed = [];
    $none = function (): void {
    };
    $plan->run($counts, $create ?? $none, $none, $none, function (\Throwable $e, string $context) use (&$failed): void {
        $failed[] = $context;
    });
    return [$counts, $failed];
}

$twoGrants = [10 => ['award_id' => PUC, 'grant_date' => D2003], 11 => ['award_id' => PUC, 'grant_date' => D2021]];
$awardIds = [PUC, OTHER_REGIMENT_AWARD];

$cases = [
    'a milpac with no rows gets one per grant' => [
        $twoGrants, [],
        ['create' => [10, 11], 'reset' => [], 'delete' => [], 'counts' => [10 => [1, 0, 0], 11 => [1, 0, 0]], 'unlinked' => 0],
    ],
    'a milpac already in step is left alone' => [
        $twoGrants, [row(1, PUC, D2003, 10), row(2, PUC, D2021, 11)],
        ['create' => [], 'reset' => [], 'delete' => [], 'counts' => [], 'unlinked' => 0],
    ],
    'an edited date, award or details is reset' => [
        $twoGrants + [13 => ['award_id' => PUC, 'grant_date' => D2009]],
        [row(1, PUC, D2003 + 86400, 10), row(2, MSM, D2021, 11), row(3, PUC, D2009, 13, 'note')],
        ['create' => [], 'reset' => [1 => 10, 2 => 11, 3 => 13], 'delete' => [], 'counts' => [10 => [0, 1, 0], 11 => [0, 1, 0], 13 => [0, 1, 0]], 'unlinked' => 0],
    ],
    'of duplicates the lowest record id is kept, whatever the order' => [
        [10 => $twoGrants[10]], [row(7, PUC, D2003 + 86400, 10), row(5, PUC, D2003, 10), row(9, PUC, D2003, 10)],
        ['create' => [], 'reset' => [], 'delete' => [7 => 10, 9 => 10], 'counts' => [10 => [0, 0, 2]], 'unlinked' => 0],
    ],
    'a row linked to a grant that is not live is removed on that grant' => [
        [10 => $twoGrants[10]], [row(1, PUC, D2003, 10), row(2, PUC, D2021, 12)],
        ['create' => [], 'reset' => [], 'delete' => [2 => 12], 'counts' => [12 => [0, 0, 1]], 'unlinked' => 0],
    ],
    'an unlinked row is removed, never adopted, even on the grant date' => [
        [10 => $twoGrants[10]], [row(1, PUC, D2003, null)],
        ['create' => [10], 'reset' => [], 'delete' => [1 => null], 'counts' => [10 => [1, 0, 0]], 'unlinked' => 1],
    ],
    'an unlinked row of any regiment award is removed' => [
        [10 => $twoGrants[10]], [row(1, PUC, D2003, 10), row(2, OTHER_REGIMENT_AWARD, D2021, null)],
        ['create' => [], 'reset' => [], 'delete' => [2 => null], 'counts' => [], 'unlinked' => 1],
    ],
    'a row of another award is left alone' => [
        [10 => $twoGrants[10]], [row(1, PUC, D2003, 10), row(2, MSM, D2003, null)],
        ['create' => [], 'reset' => [], 'delete' => [], 'counts' => [], 'unlinked' => 0],
    ],
    'with no live grant every PUC row goes' => [
        [], [row(1, PUC, D2003, 10), row(2, PUC, D2021, null)],
        ['create' => [], 'reset' => [], 'delete' => [1 => 10, 2 => null], 'counts' => [10 => [0, 0, 1]], 'unlinked' => 1],
    ],
];

foreach ($cases as $label => [$grants, $rows, $expect]) {
    $plan = PucPlan::for($grants, $awardIds, $rows);
    [$counts] = runPlan($plan);
    $expectCounts = array_map(fn (array $c): array => ['added' => $c[0], 'reset' => $c[1], 'removed' => $c[2]], $expect['counts']);

    $got = ['create' => $plan->create, 'reset' => $plan->reset, 'delete' => $plan->delete, 'counts' => $counts->grants, 'unlinked' => $counts->unlinked];
    $want = ['create' => $expect['create'], 'reset' => $expect['reset'], 'delete' => $expect['delete'], 'counts' => $expectCounts, 'unlinked' => $expect['unlinked']];
    foreach (['reset', 'delete', 'counts'] as $map) {
        ksort($got[$map]);
        ksort($want[$map]);
    }
    check($label, $got === $want, json_encode($got));
}

check(
    'PUC awards are the option ids and every live grant\'s award, once each',
    PucPlan::awardIds([PUC, OTHER_REGIMENT_AWARD], [10 => ['award_id' => PUC, 'grant_date' => D2003], 11 => ['award_id' => 70, 'grant_date' => D2021]]) === [PUC, OTHER_REGIMENT_AWARD, 70]
);

$plan = PucPlan::for($twoGrants, $awardIds, []);
[$counts, $failed] = runPlan($plan, function (int $grantId): void {
    if ($grantId === 10) {
        throw new \RuntimeException('save refused');
    }
});
check(
    'a failed write is reported and uncounted, and the next one still runs',
    $counts->grants === [11 => ['added' => 1, 'reset' => 0, 'removed' => 0]] && count($failed) === 1 && str_contains($failed[0], 'regiment grant 10'),
    json_encode([$counts->grants, $failed])
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
