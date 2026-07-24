<?php

/**
 * Issue #144 — the pure routing behind the enlistment-type alert split, isolated
 * from XenForo so every branch of "which clerks own this thread's type?" runs for
 * real in plain PHP. The queue query, the seat resolution, the alert send and the
 * skip-and-log are XenForo-coupled and pinned by ScanWiringTest instead; this
 * file exercises the rule itself.
 *
 * Rules under test (Cav7\EnlistmentReminder\EnlistmentRouting):
 *
 *  - a Standard-prefix thread routes to the Standard clerk positions;
 *  - a Re-Enlist-prefix thread routes to the Re-Enlist clerk positions;
 *  - an unrecognized or absent prefix routes to "unrecognized" with no positions,
 *    so the caller skips it rather than mass-alerting;
 *  - a prefix listed under both type sets fail-safes to the union of both, and is
 *    reported as an overlap so the caller can log the misconfig;
 *  - the union of both position lists is merged and de-duplicated, so the
 *    caller's "is any seat held at all" guard sees every configured seat;
 *  - id robustness: the string ids the DB hands back and the ints the option
 *    parser yields compare as the same id.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/EnlistmentRoutingTest.php
 */

namespace Cav7\EnlistmentReminder\Tests;

require __DIR__ . '/../EnlistmentRouting.php';

use Cav7\EnlistmentReminder\EnlistmentRouting;

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

// The configured defaults: 57 is Standard, 58 is Re-Enlistment. Standard is
// worked by Enlistment (580), Processing Clerk IT (751), Senior (1012) and Lead
// (579); Re-Enlistment by Re-Enlistment (960), Senior (1012) and Lead (579).
// Senior and Lead sit in both, so the union of the two sets is five seats.
$standardPrefixIds   = [57];
$standardPositionIds = [579, 580, 751, 1012];
$reenlistPrefixIds   = [58];
$reenlistPositionIds = [579, 960, 1012];

$routing = new EnlistmentRouting(
    $standardPrefixIds,
    $standardPositionIds,
    $reenlistPrefixIds,
    $reenlistPositionIds
);

// --- routing by type -------------------------------------------------------
$standard = $routing->route(57);
check(
    'a Standard-prefix (57) thread routes to the Standard clerk positions',
    $standard === ['type' => EnlistmentRouting::TYPE_STANDARD, 'position_ids' => [579, 580, 751, 1012]],
    'got type=' . $standard['type'] . ' positions=' . implode(',', $standard['position_ids'])
);

$reenlist = $routing->route(58);
check(
    'a Re-Enlist-prefix (58) thread routes to the Re-Enlist clerk positions',
    $reenlist === ['type' => EnlistmentRouting::TYPE_REENLIST, 'position_ids' => [579, 960, 1012]],
    'got type=' . $reenlist['type'] . ' positions=' . implode(',', $reenlist['position_ids'])
);

// --- the unrecognized case -------------------------------------------------
$unknown = $routing->route(99);
check(
    'a prefix in neither set is unrecognized with no positions',
    $unknown === ['type' => EnlistmentRouting::TYPE_UNRECOGNIZED, 'position_ids' => []]
);
$none = $routing->route(0);
check(
    'an absent prefix (0, a thread with no prefix) is unrecognized',
    $none === ['type' => EnlistmentRouting::TYPE_UNRECOGNIZED, 'position_ids' => []],
    'a queue thread not made by an intake form has no type prefix'
);

// --- the overlap misconfig fail-safe ---------------------------------------
// A prefix listed under BOTH sets must never silently drop a responsible clerk:
// it routes to the union of both sets, and is reported as an overlap so the
// caller can log the misconfig.
$overlapRouting = new EnlistmentRouting([57, 58], [579, 580, 751, 1012], [58], [579, 960, 1012]);
$both = $overlapRouting->route(58);
check(
    'a prefix in both sets routes to the union of both clerk positions (fail-safe)',
    $both === ['type' => EnlistmentRouting::TYPE_BOTH, 'position_ids' => [579, 580, 751, 1012, 960]],
    'got type=' . $both['type'] . ' positions=' . implode(',', $both['position_ids'])
);
check(
    'the overlapping prefix is reported so the caller can log the misconfig',
    $overlapRouting->overlappingPrefixIds() === [58]
);
check(
    'a healthy config reports no overlapping prefixes',
    $routing->overlappingPrefixIds() === []
);
// A prefix that is only in the standard set of the overlap config still routes
// to standard alone — the union is only for the genuinely-ambiguous prefix.
$stillStandard = $overlapRouting->route(57);
check(
    'a prefix in only one set of an overlap config routes to that set alone',
    $stillStandard['type'] === EnlistmentRouting::TYPE_STANDARD
        && $stillStandard['position_ids'] === [579, 580, 751, 1012]
);

// --- the union of both clerk sets -------------------------------------------
// Senior (1012) and Lead (579) are in both lists, so the union de-dups them down
// to the five distinct seats RRD staffs the queue with.
check(
    'the union equals both position lists merged and de-duplicated',
    $routing->allClerkPositionIds() === [579, 580, 751, 1012, 960],
    'got: ' . implode(',', $routing->allClerkPositionIds())
);

// --- id robustness: string ids from the DB still match ---------------------
// The option parser hands back ints; a raw DB prefix column arrives as a string.
// Constructing from string ids and routing an int (and vice versa) must agree.
$stringIdRouting = new EnlistmentRouting(['57'], ['579', '580'], ['58'], ['579', '960']);
check(
    'a string-configured Standard prefix routes an int prefix id',
    $stringIdRouting->route(57) === ['type' => EnlistmentRouting::TYPE_STANDARD, 'position_ids' => [579, 580]]
);
check(
    'the union of string-configured positions is a de-duplicated int list',
    $stringIdRouting->allClerkPositionIds() === [579, 580, 960]
);

// --- a recognized type whose clerk set is empty ----------------------------
// A per-type clerk list left blank (or drifted to all-vacant seats) must still
// report its TYPE with an empty audience — NOT fall through to unrecognized. The
// caller leans on that distinction: unrecognized means "not an enlistment, skip
// quietly", whereas a recognized type with no one to alert is a misconfig the
// caller logs and retries. route() surfaces the emptiness; handling it is the
// caller's job (QueueReminder's recognized-but-empty guard).
$emptyStandardClerks = new EnlistmentRouting([57], [], [58], [579, 960, 1012]);
check(
    'a recognized prefix whose type has no clerk positions still reports the type, with an empty audience',
    $emptyStandardClerks->route(57) === ['type' => EnlistmentRouting::TYPE_STANDARD, 'position_ids' => []]
);
check(
    'the other type still routes normally when only one type has no clerks',
    $emptyStandardClerks->route(58) === ['type' => EnlistmentRouting::TYPE_REENLIST, 'position_ids' => [579, 960, 1012]]
);
// Symmetric case: a blank re-enlistment clerk set reports TYPE_REENLIST with an
// empty audience just the same, so the caller's guard covers both types alike.
$emptyReenlistClerks = new EnlistmentRouting([57], [579, 580, 751, 1012], [58], []);
check(
    'a recognized Re-Enlist prefix whose type has no clerk positions still reports the type, with an empty audience',
    $emptyReenlistClerks->route(58) === ['type' => EnlistmentRouting::TYPE_REENLIST, 'position_ids' => []]
);

// A blank/junk config resolves nothing and routes everything to unrecognized,
// which the caller reads as "skip" — mirroring PositionIdList dropping junk.
$emptyRouting = new EnlistmentRouting([], [], [], []);
check(
    'an empty config routes every prefix to unrecognized',
    $emptyRouting->route(57)['type'] === EnlistmentRouting::TYPE_UNRECOGNIZED
        && $emptyRouting->allClerkPositionIds() === []
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
