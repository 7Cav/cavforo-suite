<?php

/**
 * Issue #148 — exercises RoleClaim, the claim decided as a pure function.
 *
 * RoleClaim::claim takes the role ids the sync already recorded granting, every
 * role any user group grants (in "<serverId>:<roleId>" form), the server being
 * synced, and the server a bare id belongs to. It returns the set of role ids the
 * sync treats as its own for that server — the roles it is therefore allowed to
 * take away.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/RoleClaimTest.php
 */

namespace Cav7\DiscordSyncPatch\Tests;

require __DIR__ . '/../RoleScope.php';
require __DIR__ . '/../RoleClaim.php';

use Cav7\DiscordSyncPatch\RoleClaim;

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

/** Order-insensitive set comparison — the claim is a set, not a sequence. */
function sameSet(array $actual, array $expected): bool
{
    sort($actual);
    sort($expected);
    return $actual === $expected;
}

// A role mapped to a different guild is that guild's business. Claiming it here
// would hand the current guild's sync a role id it does not own; the sync would
// then strip it on the next patch. This is the highest-consequence way the claim
// can go wrong, which is why the prefix split lives in the tested unit.
check(
    'a mapped role belonging to another server is not claimed',
    sameSet(RoleClaim::claim([], ['1:100', '2:200'], 1, 1), ['100']),
    'server 2\'s role must not enter server 1\'s claim'
);

// The acceptance side of the same decision, for a NON-default guild. Syncing server
// 2 while server 1 is the default: the role prefixed 2: is claimed, the role
// prefixed 1: (the default server's) is rejected, and the recorded id survives even
// though serverId != defaultServerId. A claim that compared the prefixed branch
// against the default server instead of the server being synced would pass every
// case where serverId == defaultServerId but strip another guild's roles here.
check(
    'a prefixed role is claimed for a non-default synced server while the default-server role is rejected',
    sameSet(RoleClaim::claim(['900'], ['1:100', '2:200'], 2, 1), ['900', '200']),
    'syncing server 2 must claim 2:200, reject 1:100, and keep the recorded 900'
);

// The result is a union, not a replacement. Whatever the sync recorded granting
// stays claimed even when no user group maps to it any more, so a role that is
// cleaned up today does not stop being cleaned up.
check(
    'a recorded role survives into the claim when nothing maps to it',
    sameSet(RoleClaim::claim(['900'], ['1:100'], 1, 1), ['900', '100']),
    'a replacement instead of a union would quietly stop reclaiming stale grants'
);

// A mapped id with no prefix belongs to the default server, matching the parse the
// vendor's own grouping does for the same input. On a single-guild configuration
// every mapped id looks like this, so getting it wrong claims nothing at all.
check(
    'an unprefixed mapped id is claimed when the default server is the one being synced',
    sameSet(RoleClaim::claim([], ['100'], 2, 2), ['100'])
);
check(
    'an unprefixed mapped id is not claimed when the default server is a different one',
    sameSet(RoleClaim::claim([], ['100'], 2, 1), []),
    'the bare id belongs to server 1, so server 2 must not claim it'
);

// A token that is a bare prefix with no role id ("<serverId>:") names no role. The
// empty whole token is filtered before it reaches here, but an empty role id after
// a valid prefix is not, so the unit itself must drop it rather than record a
// phantom empty id the vendor would then compare against real Discord role ids.
check(
    'a "<serverId>:" token with an empty role id contributes nothing to the claim',
    RoleClaim::claim([], ['1:'], 1, 1) === [],
    'an empty role id after a valid prefix must not enter the claim as an empty id'
);

// The claim is a set. The same role reached twice — recorded and mapped, or mapped
// twice — appears once, so the vendor's array_diff against it behaves predictably.
check(
    'a role that is both recorded and mapped appears once',
    RoleClaim::claim(['100'], ['1:100'], 1, 1) === ['100']
);
check(
    'a role mapped twice appears once',
    RoleClaim::claim([], ['1:100', '1:100'], 1, 1) === ['100']
);

// Both inputs empty is the ordinary state of a member with no record and a
// configuration that maps nothing; it must produce an empty claim rather than an
// error or a stray entry.
check(
    'an empty recorded set and an empty mapped set produce an empty claim',
    RoleClaim::claim([], [], 1, 1) === []
);
check(
    'an empty mapped set leaves the recorded set as the whole claim',
    sameSet(RoleClaim::claim(['900', '901'], [], 1, 1), ['900', '901']),
    'with nothing mapped the claim must fall back to exactly what the vendor uses today'
);
check(
    'an empty recorded set still claims the mapped roles',
    sameSet(RoleClaim::claim([], ['1:100', '1:101'], 1, 1), ['100', '101']),
    'a member with no record is not a special case'
);

// The result is fed straight to the vendor as unprefixed ids, the same shape its
// own grouping produces. A prefix surviving into the claim would be compared
// against Discord role ids that never carry one, so it would silently claim
// nothing.
$prefixed = RoleClaim::claim([], ['1:100', '1:101'], 1, 1);
check(
    'no claimed id carries a server prefix',
    $prefixed === array_values(array_filter($prefixed, fn ($id) => !str_contains($id, ':')))
        && count($prefixed) === 2
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
