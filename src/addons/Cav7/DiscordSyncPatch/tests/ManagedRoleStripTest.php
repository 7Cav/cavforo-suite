<?php

/**
 * Issue #157 — exercises ManagedRoleStrip, the unlinked-holder correction as a pure
 * function.
 *
 * An unlinked holder has no forum user and no connected account, so the vendor's
 * per-user sync cannot run for them; the sweep patches their roles directly. The
 * vendor's patchGuildMemberRoles REPLACES a member's whole role set, so this returns
 * the set to send — what the member should hold afterwards — rather than the roles to
 * take away. Returning removals would leave the subtraction untested in an adapter.
 *
 * null means make no call at all, which is different from an empty set: an empty set
 * is a member whose every role is a managed one, and they must still be stripped.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/ManagedRoleStripTest.php
 */

namespace Cav7\DiscordSyncPatch\Tests;

require __DIR__ . '/../ManagedRoleStrip.php';

use Cav7\DiscordSyncPatch\ManagedRoleStrip;

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

/** Order-insensitive set comparison — the role set is a set, not a sequence. */
function sameSet(?array $actual, array $expected): bool
{
    if ($actual === null) {
        return false;
    }
    sort($actual);
    sort($expected);
    return $actual === $expected;
}

// The whole job: a forum-managed role comes off, and everything the forum never
// granted stays exactly where it is.
check(
    'a managed role is dropped and a self-assigned role is kept',
    sameSet(ManagedRoleStrip::rolesToKeep(['100', '999'], ['100'], []), ['999']),
    'stripping a role no group grants would take game and interest roles off people who never linked'
);

// A member whose every role is forum-managed still gets a call, with nothing left
// in it. This is the case that makes the empty set meaningful.
check(
    'a member holding only managed roles keeps nothing, and is still corrected',
    ManagedRoleStrip::rolesToKeep(['100', '101'], ['100', '101'], []) === [],
    'an empty keep set must not be confused with having nothing to do'
);

// The Nitro-booster role carries Discord's premium_subscriber tag and a bot cannot
// move it. Dropping it from the set sent means asking Discord to take it away, which
// it refuses — and the vendor swallows that refusal, so the whole strip fails
// silently and the unlinked holder keeps every managed role.
//
// The carve-out has to hold when a user group grants the booster role too, which is
// the case this addon's own configuration could produce. Note the vendor's own
// disconnect strip does NOT do this: restrictUserRoles() merges the member's held
// premium roles into the set it removes. That is the bug this backstops, not a
// behaviour to copy.
check(
    'a preserved role is kept even when a user group grants it',
    sameSet(ManagedRoleStrip::rolesToKeep(['100', 'B'], ['100', 'B'], ['B']), ['B']),
    'sending a set without the booster role makes Discord reject the whole call'
);

// Nothing to remove means no call. The sweep sees every member of the guild on every
// run, and the great majority of unlinked members hold no managed role at all;
// patching them would spend the entire Discord budget rewriting people to exactly
// what they already have.
check(
    'a member holding no managed role is left alone entirely',
    ManagedRoleStrip::rolesToKeep(['999'], ['100'], []) === null,
    'returning the held set unchanged would PATCH every unlinked member on every run'
);
check(
    'a member whose only managed role is preserved is left alone entirely',
    ManagedRoleStrip::rolesToKeep(['B'], ['B'], ['B']) === null,
    'a call that would remove nothing is still a call, and there are thousands of them'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
