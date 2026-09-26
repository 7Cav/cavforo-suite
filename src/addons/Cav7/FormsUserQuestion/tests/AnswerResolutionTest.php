<?php

/**
 * Issue #312 (spec #310). The pure rule that decides whether a forum user
 * question's answer is accepted, and which forum users it names. The entity
 * extension that feeds it a real answer, the user finder query behind
 * $findAccount, and the database collation that makes "doe.j" find "Doe.J" all
 * need a live XenForo and are verified on the dev stack, not here.
 *
 * Rule under test (Cav7\FormsUserQuestion\AnswerResolution::resolve):
 *
 *  - the answer is split on commas, each name trimmed, blank names dropped;
 *  - every name must find an account, or the whole answer is refused;
 *  - accounts are deduped by id and kept in the order first named;
 *  - a question that takes one forum user refuses an answer naming two;
 *  - an answer that names nobody is accepted. Whether the question may be left
 *    empty is the caller's business.
 *
 * The slices carry the spec's numbers. Slices 3 and 9 cover the output text and
 * belong to #313.
 *
 * The fixture stands in for the database: "Doe.J" and "doe.j" both find account
 * 12, the second as the case-insensitive collation would, "Smith.A" finds 34,
 * and every other name finds nothing.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/AnswerResolutionTest.php
 */

namespace Cav7\FormsUserQuestion\Tests;

require __DIR__ . '/../AnswerResolution.php';
require __DIR__ . '/../AnswerResolution/Result.php';

use Cav7\FormsUserQuestion\AnswerResolution;
use Cav7\FormsUserQuestion\AnswerResolution\Result;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}

function findAccount(string $name): ?array
{
    $accounts = [
        'Doe.J' => ['id' => 12, 'username' => 'Doe.J'],
        'doe.j' => ['id' => 12, 'username' => 'Doe.J'],
        'Smith.A' => ['id' => 34, 'username' => 'Smith.A'],
    ];

    return $accounts[$name] ?? null;
}

function resolve(string $raw, bool $takesSeveral): Result
{
    return AnswerResolution::resolve($raw, $takesSeveral, __NAMESPACE__ . '\findAccount');
}

/** @return int[] */
function userIds(Result $result): array
{
    return array_map(fn (array $user): int => $user['id'], $result->users());
}

function describe(Result $result): string
{
    return ($result->isAccepted() ? 'accepted' : 'refused') . ' users=' . json_encode(userIds($result));
}

// Slice 1. Reddened by: sorting the users by id.
$result = resolve('Smith.A, Doe.J', true);
check(
    'slice 1: several names come back in the order the filer gave them',
    $result->isAccepted() && userIds($result) === [34, 12],
    describe($result)
);

// Slice 2. Reddened by: looking up blank names instead of dropping them.
$result = resolve(', ,Doe.J, ', true);
check(
    'slice 2: blank names, such as the picker\'s trailing separator, are dropped',
    $result->isAccepted() && userIds($result) === [12],
    describe($result)
);

// Slice 4. Reddened by: finding duplicates by the typed name, not the account id.
$result = resolve('Doe.J, doe.j', true);
check(
    'slice 4: one forum user named twice, in different letter case, appears once',
    $result->isAccepted() && userIds($result) === [12],
    describe($result)
);

// Slice 5. Reddened by: skipping a name that finds no account.
$result = resolve('Doe.J, Nobody.X', true);
check(
    'slice 5: a name that belongs to no forum user refuses the whole answer',
    !$result->isAccepted(),
    describe($result)
);

// Slice 6. Reddened by: ignoring $takesSeveral.
$result = resolve('Doe.J, Smith.A', false);
check(
    'slice 6: a question that takes one forum user refuses two',
    !$result->isAccepted(),
    describe($result)
);

// Slice 7. Reddened by: counting typed names, not distinct forum users, against
// the one-user limit.
$result = resolve('Doe.J, doe.j', false);
check(
    'slice 7: a question that takes one forum user accepts the same one named twice',
    $result->isAccepted() && userIds($result) === [12],
    describe($result)
);

// Slice 8. Reddened by: treating an answer that names nobody as refused.
$result = resolve('', true);
check(
    'slice 8: an empty answer is accepted with no users',
    $result->isAccepted() && userIds($result) === [],
    describe($result)
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
