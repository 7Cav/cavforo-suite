<?php

/**
 * Issue #157 — exercises RoleDivergence, the Discord-side half of divergence
 * detection as a pure function.
 *
 * It answers one question: do this member's managed roles on Discord disagree with
 * the roles their current forum groups grant? Every id it takes is already scoped to
 * the guild being swept and carries no server prefix (see RoleScope), because
 * Discord never sends one.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/RoleDivergenceTest.php
 */

namespace Cav7\DiscordSyncPatch\Tests;

require __DIR__ . '/../RoleDivergence.php';

use Cav7\DiscordSyncPatch\RoleDivergence;

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

// The two conditions the glossary defines divergence as. A managed role held that
// no group grants is the hand-edit case; a managed role granted but not held is the
// sync that never landed.
check(
    'a managed role held that no group grants is a divergence',
    RoleDivergence::diverges(['100'], [], ['100'], []),
    'a role hand-added in Discord must be found'
);
check(
    'a managed role granted that is not held is a divergence',
    RoleDivergence::diverges([], ['100'], ['100'], []),
    'a promotion the sync never applied must be found'
);

// The ordinary state of a correct member, and the one that keeps the sweep cheap.
check(
    'a member holding exactly what their groups grant is not divergent',
    !RoleDivergence::diverges(['100', '101'], ['101', '100'], ['100', '101'], []),
    'correcting settled members would spend the whole Discord budget on no-ops'
);

// The two sides reach this from different places — Discord's member record against
// the ids stored on a user group — so they can name the same role while disagreeing
// on type. Role ids are too large to hold as ints on every platform, so they travel
// as strings and a comparison that is strict about type reads every member as
// divergent and corrects the entire guild on every run.
check(
    'the same roles as strings and as ints are not a divergence',
    !RoleDivergence::diverges([100, 101], ['100', '101'], ['100', '101'], []),
    'a type-strict comparison would correct the whole guild every quarter-hour'
);

// Managed is a property of the role and the configuration. A role no user group
// grants is outside this addon entirely, so holding one is never a divergence
// however it got there — this is what keeps self-assigned interest and game roles
// working exactly as they did.
check(
    'a role no user group grants is not a divergence',
    !RoleDivergence::diverges(['100', '999'], ['100'], ['100'], []),
    'comparing held against granted without narrowing to managed roles first would flag every member with a game role'
);

// Roles Discord manages itself — the Nitro-booster role carries the
// premium_subscriber tag — cannot be handed out or taken away by a bot. The vendor
// merges them into the set it applies for exactly that reason. Judging them here
// produces a divergence no correction can ever clear: the sweep queues a sync, the
// sync preserves the role, the next sweep finds the same disagreement. Ninety-six
// corrections a day, per booster, forever.
//
// Both directions have to be excluded, and the guild's own configuration decides
// nothing here: the carve-out holds even when a user group grants the role.
check(
    'a preserved role held but not granted is not a divergence',
    !RoleDivergence::diverges(['B'], [], ['B'], ['B']),
    'a booster whose groups do not grant the role would be corrected forever'
);
check(
    'a preserved role granted but not held is not a divergence',
    !RoleDivergence::diverges([], ['B'], ['B'], ['B']),
    'a group granting a role Discord will not let the bot apply would diverge forever'
);

// The carve-out must not swallow the ordinary decision around it: a member with a
// real divergence alongside a preserved role is still divergent.
check(
    'a preserved role does not mask a real divergence on the same member',
    RoleDivergence::diverges(['B', '100'], [], ['B', '100'], ['B']),
    'excluding the preserved role must not exclude everything else with it'
);

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll tests passed\n";
exit(0);
