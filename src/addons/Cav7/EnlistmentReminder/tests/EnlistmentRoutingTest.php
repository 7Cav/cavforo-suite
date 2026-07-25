<?php

/**
 * Issue #144 — the pure routing behind the enlistment-type alert split, isolated
 * from XenForo so every branch of "which clerks own this thread's type?" runs for
 * real in plain PHP. The queue query, the seat resolution, the alert send and the
 * skip-and-log are XenForo-coupled: they are verified on the dev stack, not in CI.
 * This file exercises the rule itself.
 *
 * Rules under test (Cav7\EnlistmentReminder\EnlistmentRouting):
 *
 *  - a Standard-prefix thread routes to the Standard clerk positions;
 *  - a Re-Enlist-prefix thread routes to the Re-Enlist clerk positions;
 *  - an unrecognized or absent prefix routes to "unrecognized" with no positions,
 *    so the caller skips it rather than mass-alerting;
 *  - a prefix listed under both type sets fail-safes to the union of both, and is
 *    reported as an overlap so the caller can log the misconfig;
 *  - which of a given set of prefix ids are configured type prefixes, so the
 *    caller can refuse to run when a type prefix has been listed as an
 *    in-processing status (issue #186);
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

require __DIR__ . '/../PositionIdList.php';
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

// --- the type prefix / status prefix collision (issue #186) -----------------
// cav7ERInProcessingPrefixIds is free text with no validation_class, and a type
// prefix listed there is the add-on's worst config fault:
// every valid queue thread carries its type prefix in the same link table the
// status is read from, so one entry reads the whole queue as handled and the
// reminder goes permanently, silently dark. This is what the caller asks before
// it decides anything, so it can abort instead.
check(
    'the shipped default status set collides with neither type prefix',
    $routing->typePrefixIdsAmong([53, 54, 55]) === [],
    'got: ' . implode(',', $routing->typePrefixIdsAmong([53, 54, 55]))
);
check(
    'a Standard type prefix in the status set is reported',
    $routing->typePrefixIdsAmong([53, 54, 55, 57]) === [57]
);
check(
    'a Re-Enlist type prefix in the status set is reported',
    $routing->typePrefixIdsAmong([58]) === [58]
);
check(
    'both type prefixes in the status set are both reported',
    $routing->typePrefixIdsAmong([57, 58]) === [57, 58],
    'got: ' . implode(',', $routing->typePrefixIdsAmong([57, 58]))
);
// String ids from a hand-typed option must collide just the same, or the check
// would pass on exactly the input an admin produces.
check(
    'a string type prefix id in the status set is still reported',
    $routing->typePrefixIdsAmong(['53', '57']) === [57]
);
// Nothing configured as a type prefix means nothing can collide; the empty status
// set is the other guard's business, not this one's. BOTH lists blank is what makes
// this answer vacuous, which is why the caller aborts on that and only that.
$noTypePrefixes = new EnlistmentRouting([], $standardPositionIds, [], $reenlistPositionIds);
check(
    'a config with no type prefixes reports no collision',
    $noTypePrefixes->typePrefixIdsAmong([53, 54, 55, 57, 58]) === []
);
// ONE blank list does not make the collision check inert, and this is what the
// caller's guards rest on: the intersection runs against the UNION of both lists,
// so the populated type's ids are still compared and its threads still route. A
// caller that aborted on a single blank list would stop reminding a type whose
// config is entirely healthy, so the asymmetry is pinned here rather than assumed.
$noStandardPrefixes = new EnlistmentRouting([], $standardPositionIds, [58], $reenlistPositionIds);
check(
    'a blank standard list still reports a re-enlist type prefix in the status set',
    $noStandardPrefixes->typePrefixIdsAmong([53, 54, 55, 58]) === [58],
    'got: ' . implode(',', $noStandardPrefixes->typePrefixIdsAmong([53, 54, 55, 58]))
);
check(
    'a blank standard list still routes a re-enlistment to its own clerks',
    $noStandardPrefixes->route(58) === ['type' => EnlistmentRouting::TYPE_REENLIST, 'position_ids' => [579, 960, 1012]],
    'got type=' . $noStandardPrefixes->route(58)['type']
);
$noReenlistPrefixes = new EnlistmentRouting([57], $standardPositionIds, [], $reenlistPositionIds);
check(
    'a blank re-enlist list still reports a standard type prefix in the status set',
    $noReenlistPrefixes->typePrefixIdsAmong([53, 54, 55, 57]) === [57],
    'got: ' . implode(',', $noReenlistPrefixes->typePrefixIdsAmong([53, 54, 55, 57]))
);
check(
    'a blank re-enlist list still routes a standard enlistment to its own clerks',
    $noReenlistPrefixes->route(57) === ['type' => EnlistmentRouting::TYPE_STANDARD, 'position_ids' => [579, 580, 751, 1012]],
    'got type=' . $noReenlistPrefixes->route(57)['type']
);
check(
    'an empty status set collides with nothing',
    $routing->typePrefixIdsAmong([]) === []
);

// --- the union of both clerk sets -------------------------------------------
// Senior (1012) and Lead (579) are in both lists, so the union de-dups them down
// to the five distinct seats RRD staffs the queue with.
check(
    'the union equals both position lists merged and de-duplicated',
    $routing->allClerkPositionIds() === [579, 580, 751, 1012, 960],
    'got: ' . implode(',', $routing->allClerkPositionIds())
);

// --- id robustness: string ids from a hand-typed option still match ---------
// The option parser hands back ints, but a config array can arrive as strings, so
// the constructor normalises its four lists. route() takes `int $prefixId`, so the
// reverse — routing a string id — is a type error rather than a case to cover; the
// asymmetry is the point of normalising on the way in.
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
