# 7Cav - Discord Sync Patch

Makes a member's forum user groups authoritative for every Discord role a user
group grants, and gives members a button that asks for that sync themselves.
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

A member with no Discord account linked is turned away first. There is nothing to
sync for them, and the integration would queue a row anyway if it were asked.

Then two guards. A press is refused while a sync is already pending for that
member, and a member may queue at most one resync every five minutes. The
five-minute limit is XenForo's own flood check, which exempts anyone holding
`general:bypassFloodCheck`, and on this forum that permission comes from groups
ordinary members are in, not just staff ones. Configured that way, the cooldown
reaches nobody and the pending check is the only guard left doing anything. If
nothing was queued, the member is told so rather than left waiting on a sync that
will never run, the failure goes to the server error log for staff, and the
cooldown is handed back instead of spent.

The button syncs whether or not anything is actually wrong. The reasoning is in
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
message and the button is gone from the next page render, with no data to unwind:
the addon never writes anything the vendor would not have written.

## Requirements

- XenForo 2.3+
- [NF Discord Integration](https://nixfifty.com/products/discord-integration.7/) 2.12.0+

## Assumptions about vendor internals

Both halves depend on things being true of NF/Discord that the vendor never
promised. Each is checked at the seam where it would break the addon rather than
reimplemented in the test suite, so a vendor upgrade that breaks one is caught
rather than discovered in production. They are listed in the docblocks of
`NF/Discord/ApiMessage/SyncUser.php` and `XF/Pub/Controller/Account.php`.

## Tests

`tools/run-tests.sh DiscordSyncPatch`. The role-set decision lives in `RoleClaim`,
a plain unit with no XenForo dependency, and is exercised for real in
`tests/RoleClaimTest.php`. `tests/WiringTest.php` pins the parts that need a live
stack to run: both class-extension registrations, the method overrides, the resync
action and its two guards, and the template modification and phrases that put the
button on the page.

Two things only a dev-stack run can check. Whether the template modification still
lands, because the vendor template is not in this repo; and the Discord role change
a resync produces, because a local stack has no bot token to drain the queue with.
The queue row landing is the observable outcome, and it is the one the action
itself checks.

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
