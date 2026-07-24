<?php

/**
 * Behavioural unit test for PositionIdList — the parse that turns the per-type
 * clerk-position and prefix options (cav7ERStandard/ReenlistClerkPositionIds and
 * cav7ERStandard/ReenlistPrefixIds since issue #144) into their id sets, and the
 * normalize the other seams share for ids that arrive already split. Pure PHP, no
 * XenForo, so every branch runs for real here rather than being pinned by shape. If
 * it mis-shapes an id set, the whole reminder mis-fires: drop every id and
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

// --- normalize(): the same id-list shaping applied to an already-split array ---
// parse() ends in it, EnlistmentRouting shapes its four constructor lists with it,
// and ProcessingStatus shapes the configured status set with it, so the id an
// option parsed to and the id the DB hands back as a string compare as the same
// id. One home for that rule, exercised here.

// Strings and ints normalise to the same ints — the DB hands prefix ids back as
// strings, PositionIdList parses them to ints, and both must match.
check(
    "normalize(['57', 58]) yields [57, 58]",
    PositionIdList::normalize(['57', 58]) === [57, 58]
);

// A 0 — the "no prefix" sentinel — and junk tokens drop out, so neither can ever
// match a real id.
check(
    'normalize drops a 0 and a junk token',
    PositionIdList::normalize([53, 0, 'abc', '54']) === [53, 54]
);
check('normalize([]) yields []', PositionIdList::normalize([]) === []);
check(
    'normalize of nothing but junk yields []',
    PositionIdList::normalize(['', 'abc', 0, '0']) === []
);

// Duplicates collapse and first-seen order survives, including across the
// string/int boundary, and the result is a list (re-indexed from 0) so it can be
// json-encoded or compared as one.
check(
    'normalize de-dups across the string/int boundary, first-seen order kept',
    PositionIdList::normalize(['55', 53, '53', 55, 54]) === [55, 53, 54]
);
check(
    'normalize re-indexes to a list after dropping a middle entry',
    array_keys(PositionIdList::normalize([53, 'abc', 54])) === [0, 1]
);

// A negative token survives as an inert id: no prefix or position id is ever
// negative, so it matches nothing, and dropping it here is not this seam's job.
// Pinned so a later positivity filter is a deliberate change, not a silent one.
check(
    'normalize keeps a negative token as an inert id',
    PositionIdList::normalize(['-3', 53]) === [-3, 53]
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
