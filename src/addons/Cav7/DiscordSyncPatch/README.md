# 7Cav - Discord Sync Patch

Makes a member's forum user groups authoritative for every Discord role a user
group grants, and lets members ask for that sync themselves.
Extends [NF Discord Integration](https://nixfifty.com/products/discord-integration.7/);
it changes no vendor file.

## The problem it fixes

When a member's user groups changed, the Discord integration would sometimes give
back the roles they held before the change. A discharged member could keep a role
marking them as currently serving, and a member whose groups said they should hold
a role could end up without it.

It did not clear up on its own. The integration keeps its own record of which
roles it handed a member and uses that record to decide what it may take away.
When the fault hit, the record was left disagreeing with the member's groups. The
next sync read the wrong record, concluded the role must have been added by hand,
protected it, and rewrote the record still wrong. Every later sync repeated the
decision, so the member stayed wrong until somebody fixed it by hand.

Two things caused it together. The sync runs as queued messages handled by one
long-lived worker that keeps loaded records in memory for its whole run, so a
message could act on a copy of the member that predated an admin's save.
Separately, the rule deciding which roles were removable trusted a record the
first problem could corrupt.

## What it changes

Two overrides on the vendor's per-user sync message, both thin.

The message entry point evicts the member and the integration's per-member record
before delegating, so each queued message reads the member's current state. A
message that runs after an admin changed somebody's groups sees the change.

The role-sync step widens, in memory only, the record the vendor reads when
deciding what it may remove. Alongside the roles the integration recorded
granting, it now also treats any role a user group grants on that guild as its
own. That answer comes from configuration, which the fault cannot corrupt.

Existing bad records correct themselves on the next ordinary sync. There is no
migration step and no mass role update when the addon is enabled.

## The resync button

Members get a button on their connected-accounts page that queues a sync of their
own Discord roles. It replaces the standing instruction to disconnect and reconnect
Discord. That instruction only ever worked by accident, because inserting a
connected-account row makes the integration queue a sync, and it drops any member
whose Discord account is their only login into a password reset.

The button queues the same per-user sync a group change would have queued, for the
member who pressed it and nobody else, across every guild the forum syncs. It
promises no completion time, because the sync drains behind a queue that yields to
Discord's rate limits.

One thing a reconnect does that this does not: the integration treats a reconnect as
a fresh link, and a fresh link re-adds a member to a guild they have left where that
guild joins members automatically. A resync syncs roles and leaves joining alone. A
member who left a Discord server still has to go back to it themselves. The note
under the button says so, because the page a press lands on can still show a join as
pending: the integration builds that indicator from the queue rows a resync writes,
and there is no suppressing it from this side.

Three things are checked before a press costs anything. A member with no Discord
account linked is turned away and told so. So is everyone, if the integration is
missing its Discord credentials, and so is everyone if no Discord server on the forum
is active. Each refusal names its own cause, because the three are fixed in different
places and the member is the one carrying the diagnosis to staff.

The credentials check is the one that has to be there. Without it the queueing call
still lands a row, so the member is told the resync is queued, while the integration's
queue runner bails out before it ever reads the queue. Nothing removes that row, and
the pending guard below then refuses every press after it, indefinitely and silently.

Then two guards. A press made while a sync is already pending for that member is
refused, with a note that one is on its way. Past that, a member may queue at most
one resync every five minutes; that limit is XenForo's own flood check, which does
not apply to anyone holding `general:bypassFloodCheck`. If nothing was queued even
so, the member is told rather than left waiting on a sync that will never run, and
the cooldown is handed straight back. That failure goes to the server error log,
because by then there is no explanation left to offer and staff need to know.

The button syncs whether or not anything is actually wrong. Why it does not check
first, and what the two guards are there to protect, is in
[ADR-0005](docs/adr/0005-honour-a-resync-request-without-checking-divergence.md).

## The behavior change worth knowing about

**A role that a user group grants can no longer be assigned by hand in Discord and
expected to survive.** The next sync takes it back off.

The same mechanism does both jobs. From the sync's position a hand-added role and
a role left behind by the fault look identical, so keeping one while dropping the
other is not available. This was confirmed acceptable before the addon was
specced: no role is administered from both the forum and Discord.

Roles that no user group maps to stay outside the addon entirely. Self-assigned
interest and game roles keep working exactly as they did.

## What it does not touch

Reverse sync (a Discord role granting a forum user group), nickname sync, avatar
sync, the announcement flow, and the join and kick decisions. None of them read
the record this changes. It also never changes which roles a user group grants,
only what the sync may remove.

## Reverting

Disable the addon. Everything it adds is a class extension, a template
modification or a phrase, so the integration is back to stock behavior on the next
message and the button is gone from the next page render.

Two things it writes have no vendor equivalent, and neither needs unwinding. One is
a flood-check row under its own `cav7_discord_resync` key, one per member who has
used the button; XenForo's hourly cleanup prunes flood-check rows a day after they
were last touched, so they age out on their own. The other is a server error-log row
prefixed `Cav7/DiscordSyncPatch:`, written only when a press queued nothing that
nothing accounts for; those age out with the rest of the log, on the
`errorLogLength` option.

## Requirements

- XenForo 2.3+
- [NF Discord Integration](https://nixfifty.com/products/discord-integration.7/) 2.12.0+

## Assumptions about code it does not own

The sync fix and the resync button both depend on things nobody promised would stay
true. Mostly that is NF/Discord: how it queues a per-user sync, what it stores about
one, and what it reads back. The resync action also leans on XenForo core — how the router builds an
action name out of a route's action prefix, how the flood check treats the bypass
permission, and how wide `xf_flood_check.flood_action` is — so a core upgrade can
break it as readily as a vendor one. Each is checked at the seam where it would
break the addon rather than reimplemented in the test suite, so an upgrade on either
side that breaks one is caught rather than discovered in production. They are listed
in the docblocks of `NF/Discord/ApiMessage/SyncUser.php` and
`XF/Pub/Controller/Account.php`.

## Tests

`tools/run-tests.sh DiscordSyncPatch`. The role-set decision lives in `RoleClaim`,
a plain unit with no XenForo dependency, and is exercised for real in
`tests/RoleClaimTest.php`. `tests/WiringTest.php` pins the parts that need a live
stack to run: both class-extension registrations, the method overrides, the resync
action with its three preconditions and two guards, and the template modification and
phrases that put the button on the page.

One check exists only on a dev-stack run: whether the template modification still
lands, because the vendor template is not in this repo for CI to match it against.

And one thing is not observable locally at all. The Discord role change a resync
produces cannot be seen on a dev stack, because that stack has no bot token to drain
the queue with. The queue row landing is the outcome that can be observed, and it is
the one the action itself checks.

Two of the preconditions decide whether a press gets that far. The action refuses
before it queues anything if the integration has no credentials, and again if no
Discord server is active. On a stack in either state, a press exercises those
refusals and never reaches the queueing call.

## Addon info

| Field | Value |
|---|---|
| Addon ID | `Cav7/DiscordSyncPatch` |
| Namespace | `Cav7\DiscordSyncPatch` |
| Version | 1.0.0 (`1000070`) |
| Developer | Cav7 |

## License

See [LICENSE](LICENSE).

## Provenance

Written in this repo for issue #148, with the resync button added for issue #158.
It was not imported from another repository.
