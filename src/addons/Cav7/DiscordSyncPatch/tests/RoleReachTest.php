<?php

/**
 * Issue #242 — exercises RoleReach, which decides that Discord will not let this bot
 * move a role.
 *
 * A bot may only add or remove roles below its own highest one. Discord's constraint
 * is on the roles MOVED, never on the member holding them: a member whose top role is
 * above the bot still takes a 200 as long as that role stays in the set sent. That was
 * measured against real Discord rather than reasoned about, and the measurement is in
 * docs/verification/reconciliation-sweep-guards.md.
 *
 * The consequence is that an out-of-reach role behaves exactly like a preserved one —
 * keep it, and judge no divergence on it — which is why both come out of this class.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/RoleReachTest.php
 */

namespace Cav7\DiscordSyncPatch\Tests;

require __DIR__ . '/../RoleReach.php';

use Cav7\DiscordSyncPatch\RoleReach;

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

/** Order-insensitive set comparison — these are sets, not sequences. */
function sameSet(array $actual, array $expected): bool
{
    sort($actual);
    sort($expected);

    return $actual === $expected;
}

/** A role as Discord reports one on the guild-roles endpoint. */
function role(string $id, int $position, bool $managed = false, array $tags = []): array
{
    return ['id' => $id, 'position' => $position, 'managed' => $managed, 'tags' => $tags];
}

const GUILD_ID = '654549694789320706';

// The whole rule: the bot reaches below itself and no further. A role level with the
// bot counts as out of reach — Discord breaks that tie on an id ordering its own docs
// do not state, so the safe answer is the one whose error is visible.
$roles = [
    role('300', 12),
    role('200', 11),
    role('100', 10),
];

check(
    'a role above the bot is out of reach and one below it is not',
    sameSet(RoleReach::outOfReach($roles, ['200'], GUILD_ID), ['300', '200']),
    'the bot sits at 11, so 12 cannot be moved, 11 ties, and 10 is reachable'
);

// @everyone sits at position 0, so the position rule alone calls it reachable, and
// Discord refuses to add or remove it from anybody. It is recognised by its id, which
// is the guild's own — verified on a real guild, not assumed.
//
// Driven with two different guild ids over the SAME roles, so what is under test is
// the recognition rather than the presence of a parameter: the id that names the guild
// is immovable and the identical role under another guild is not.
$withEveryone = [
    role(GUILD_ID, 0),
    role('100', 10),
    role('200', 11),
];

check(
    'the role whose id is the guild id is out of reach whatever its position',
    sameSet(RoleReach::outOfReach($withEveryone, ['200'], GUILD_ID), ['200', GUILD_ID]),
    '@everyone is at position 0 and no bot can move it'
);

check(
    'the same role under a different guild is reachable',
    sameSet(RoleReach::outOfReach($withEveryone, ['200'], '999999999999999999'), ['200']),
    'if this fails the guild id is being ignored, or every low role is being called immovable'
);

// What both call sites actually consume. The two reasons a role cannot be moved are
// reported differently and decided identically, so the union is what the strip and the
// divergence judgement are handed.
$mixed = [
    role(GUILD_ID, 0),
    role('100', 10),
    role('200', 11),
    role('300', 12),
    role('900', 3, true),
];

check(
    'immovable covers both reasons at once',
    sameSet(RoleReach::immovable($mixed, ['200'], GUILD_ID), ['900', '200', '300', GUILD_ID]),
    'the booster and integration roles, the roles at or above the bot, and @everyone'
);

// The fail-open branch, and the reason it takes null rather than an empty array. Both
// callers hand null here when the bot's own guild member record could not be read.
check(
    'an unreadable bot position leaves only the reason that does not depend on position',
    sameSet(RoleReach::immovable($mixed, null, GUILD_ID), ['900']),
    'the position-based reasons cannot be judged, so they are not asserted'
);

check(
    'a bot holding no role reaches nothing, which is not the same as an unreadable one',
    sameSet(RoleReach::immovable($mixed, [], GUILD_ID), ['900', '100', '200', '300', GUILD_ID]),
    'passing [] where null was meant would call the whole guild reachable-by-nobody;'
        . ' passing null where [] was meant would silently enforce roles Discord refuses'
);

exit($failures === 0 ? 0 : 1);
