<?php

/**
 * Behavioural unit test for PositionIdList::parse — the parse that turns the
 * per-type clerk-position and prefix options (cav7ERStandard/ReenlistClerkPositionIds
 * and cav7ERStandard/ReenlistPrefixIds since issue #144) into their id sets. Pure
 * PHP, no XenForo, so every branch runs for real here rather than being pinned by
 * shape. If it mis-parses, the whole reminder mis-fires: drop every id and
 * QueueReminder aborts the run; keep a 0 or a junk token and the seat or prefix
 * match resolves the wrong ids.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/PositionIdListTest.php
 */

namespace Cav7\EnlistmentReminder\Tests;

require __DIR__ . '/../PositionIdList.php';

use Cav7\EnlistmentReminder\PositionIdList;

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

// The configured default five seats parse to exactly those ids, in order.
check(
    "the default '579,580,751,960,1012' parses to the five seats",
    PositionIdList::parse('579,580,751,960,1012') === [579, 580, 751, 960, 1012]
);

// Empty / whitespace-only parses to [] — the caller reads this as "no clerks",
// which aborts the run rather than reminding the whole queue.
check("'' parses to []", PositionIdList::parse('') === []);
check("whitespace-only '   ' parses to []", PositionIdList::parse('   ') === []);

// A trailing comma leaves no phantom 0 id.
check("trailing comma '579,' parses to [579]", PositionIdList::parse('579,') === [579]);

// A 0 token is dropped (no position has id 0); non-numeric junk is dropped too.
check("'0' parses to [] (no position has id 0)", PositionIdList::parse('0') === []);
check("junk 'abc' parses to []", PositionIdList::parse('abc') === []);
check("'579,0,xyz' keeps only 579", PositionIdList::parse('579,0,xyz') === [579]);

// Whitespace and space separators are tolerated the way the option gets typed.
check("' 579 , 580 ' parses to [579,580]", PositionIdList::parse(' 579 , 580 ') === [579, 580]);
check("space-separated '579 580' parses to [579,580]", PositionIdList::parse('579 580') === [579, 580]);

// Duplicates collapse, first-seen order preserved.
check("'580,579,580' de-dups to [580,579]", PositionIdList::parse('580,579,580') === [580, 579]);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
