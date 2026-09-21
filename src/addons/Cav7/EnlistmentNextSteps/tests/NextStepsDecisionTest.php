<?php

/**
 * Issue #291. The pure decision behind the next-steps page: does this form
 * submission land on the page, and for which thread? The controller extension
 * that asks it, the route, the template, the option and the phrases all need a
 * live XenForo and are verified on the dev stack, not here. This file exercises
 * the rule itself.
 *
 * Rule under test (Cav7\EnlistmentNextSteps\NextStepsDecision::threadToShow):
 *
 *  - a submission of a triggering form that created a thread shows the page
 *    for that thread, so the caller gets the thread id back.
 *  - a submission of any other form leaves the vendor reply alone, so the
 *    caller gets null.
 *  - an empty option means no form triggers, never every form.
 *  - a token in the option that is not a form id is skipped, and the ids
 *    beside it still count.
 *
 * Every assertion is on the returned value alone. Nothing here reads how the
 * option string is split.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/NextStepsDecisionTest.php
 */

namespace Cav7\EnlistmentNextSteps\Tests;

require __DIR__ . '/../NextStepsDecision.php';

use Cav7\EnlistmentNextSteps\NextStepsDecision;

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

// --- red-to-green slices ---------------------------------------------------

// The Enlistment form (1) is the shipped default. Its submission created thread
// 4242, so the page shows for 4242. Reddened by: always return null.
check(
    'a triggering form whose submission created a thread shows the page for that thread',
    NextStepsDecision::threadToShow('1', 1, 4242) === 4242
);

// The Personal Action Form (6) is not in the option, so the vendor reply stands.
// Reddened by: ignore the list and return the thread id whenever one was given.
check(
    'a form outside the option leaves the vendor reply alone',
    NextStepsDecision::threadToShow('1', 6, 4242) === null
);

// --- design pins, green from birth -----------------------------------------
// Each pins a rule issue #291 states as an acceptance criterion and the option's
// explain phrase repeats to the admin. Neither went red before the code was
// written; each goes red under the one rewrite it names.

// Story 20: an empty option shows the page on no form, never on every form.
// Reddened by: return the thread id when the parsed list is empty.
check(
    'an empty option triggers no form',
    NextStepsDecision::threadToShow('', 1, 4242) === null
);

// Story 21: a bad token beside a valid id is skipped and the id still counts.
// Reddened by: refuse the whole option when any token is not a form id, or
// stop reading at the first bad token.
check(
    'a valid form id still triggers when a neighbouring token is junk',
    NextStepsDecision::threadToShow('1, x, 14', 14, 4242) === 4242
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
