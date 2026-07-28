# 7Cav - Discord Sync Patch

Makes a member's forum user groups authoritative for every Discord role a user
group grants, lets members ask for that sync themselves, and reconciles the guild
on a schedule.
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
one resync every five minutes. That limit is XenForo's flood check, keyed to this
action alone and called as the service rather than through the controller helper, so
it binds every member — including anyone holding `general:bypassFloodCheck`, whom the
helper would exempt. Why that permission is not the staff exemption it looks like on
this forum is in
[ADR-0007](docs/adr/0007-bind-the-resync-cooldown-without-the-flood-bypass-permission.md).
If nothing was queued even so, the member is told rather than left waiting on a sync
that will never run, and the cooldown is handed straight back. That failure goes to
the server error log, because by then there is no explanation left to offer and staff
need to know.

The button syncs whether or not anything is actually wrong. Why it does not check
first, and what the two guards are there to protect, is in
[ADR-0005](docs/adr/0005-honour-a-resync-request-without-checking-divergence.md) —
read alongside
[ADR-0007](docs/adr/0007-bind-the-resync-cooldown-without-the-flood-bypass-permission.md),
which corrects what ADR-0005 assumed about who holds the flood-bypass permission.

The two outcomes that are not refusals — the resync was queued, one was already
pending — reach the member as a flash message, which is a JavaScript path. The
renderer a browser gets discards a redirect's message, and does so for every redirect
in the product. With JavaScript off a press still queues, and the page it lands on
still shows the pending line beside the button, but the message naming what happened
is gone. Why that ships rather than getting a mechanism of its own is in
[ADR-0006](docs/adr/0006-let-the-resync-reply-follow-xenforos-own-flash-message-behaviour.md).

## The reconciliation sweep

A cron entry puts right the members who have quietly fallen out of step, without
touching anyone who has not. Once running it fires every quarter-hour. Whether it
runs at all, and how often, are the only knobs and both live on the XenForo cron
admin page, so the addon still ships no options.

**It ships disabled.** A fresh install lands the entry inactive, so nothing sweeps
until an admin turns it on — one toggle on that same cron admin page. The toggle then
sticks: XenForo treats a cron entry's active flag as admin-owned once the row exists,
so later upgrades re-import the schedule and leave the flag alone. Why the default is
off, and why that mechanism is what makes shipping it off safe, are in
[ADR-0008](docs/adr/0008-the-sweep-ships-disabled.md).

It looks in two places, because the two kinds of divergence leave different traces.
A group change the sync never applied is visible in the forum's own records: the
group set stored on the member's last sync disagrees with their groups now, or that
sync is recorded inactive or errored. A role edited by hand in Discord leaves
nothing forum-side at all, and is found by reading the guild's members.

Members found divergent are corrected by queueing the vendor's own per-user sync for
them — the same path this addon already patches, so a correction inherits the widened
record and cannot drift from the event-driven fix. Members already in agreement are
left alone entirely, which is the point: re-syncing three thousand correct members
every quarter-hour would spend the guild's whole Discord rate budget writing back
what was already there.

The same member read also finds **unlinked holders**: people in the guild holding
forum-managed roles with no connected account to justify them. A clean disconnect
deletes their forum-side records, so nothing but Discord knows they exist. The
vendor does try to strip those roles when someone unlinks, but that strip has no
retry and several ordinary failures drop it for good, and some unlink paths never
fire it at all. The sweep is the backstop. It strips managed roles and nothing else —
self-assigned, game and Nitro-booster roles all stay — and it never removes anyone
from the guild, whatever the vendor's disconnect-kick option says.

**One run makes at most 100 strip calls per guild**, and its log line says when it
stopped at that bound and how many unlinked holders it left for the next run. Calls
rather than strips, because Discord decides whether a call removes anything: a run that
spent the whole bound having every call refused stripped nobody.

Drift does not build a batch that size — the live guild's 80 were six years of it,
mostly from a race condition since fixed. Two things do. A user group can be pointed at
a role hundreds of members already hold, which brings every one of them inside the
addon at once, and most of the guild holds no link to justify keeping it. A mass unlink
is the same shape and worse, because then every strip is wrong. The bound is what makes
either visible while it is still 100 calls rather than after all of them
([#249](https://github.com/7Cav/cavforo-suite/issues/249)).

It bounds the calls only: the run still walks to the end of the member list, because
deciding which linked members have diverged costs no Discord calls at all.

It also vetoes NF/Discord's own scheduled reconciler, so nothing else is driving the
same roles on a schedule. That cron is dormant today, gated behind an option the
vendor defines nowhere, but defining it is one row and the veto does not depend on
its staying dormant.

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

The vendor's dissociation handler is left exactly as it ships. The sweep backstops
the role strips that handler drops rather than repairing it. And while the addon
vetoes the vendor's `SyncUsersFromDiscord` cron, it does not touch, enable or
reimplement the reverse-direction code that cron's option also gates.

## Known gaps

**A throttled read is detected and reported, but not paced around.** The sweep tells a
throttled read from a refused one and names which it was, so a `429` no longer reads as
a revoked privileged intent. What it does not do is stop early: a throttle part-way
through the strip leaves the rest of that run's calls to be made anyway, each earning
its own `429`. What limits the damage is the per-run call bound above, not any response
to the throttle itself — so the worst case is the bound rather than the whole guild.

A live-guild pass walked 8,568 members over 9 pages in 10.4s and issued 80 role patches
in 28.5s without tripping a rate limit, so it does not bite at this guild's present
scale.

**The throttle signal is only as fresh as the last request that wrote it.** The vendor
records the retry-after per call but does not clear it on the path a connect failure or
a Discord 5xx takes, so the previous call's value used to stand — a 429 partway through
a guild was reported again by every following failure until one reached the vendor's
own reset, which the per-run log line counted as that many throttled strips. Closed in
1.1.1 by an `NF\Discord\Api` extension that clears the field before delegating, asked
for once per guild by the sweep ([#248](https://github.com/7Cav/cavforo-suite/issues/248)).
Two things follow that are worth knowing: the extension is **load-bearing**, and a
board that deactivated its class-extension row gets a cron that fatals rather than one
quietly back to mis-reporting; and the reset is per HTTP request, so a vendor method
spending several would report its last.

**The sweep does not check `nfDiscordEnableSync` before queueing a correction.** It
guards two preconditions of exactly this class — absent credentials, and a server row
with no guild id — because both make a queued correction incapable of succeeding. The
integration's master sync switch is a third. With it off, the vendor's `dispatch()`
never reaches `syncRoles()`: it spends a `getGuildMember` call, returns `true`, and the
queue archives the row with `fail_count 0` and `error NULL`. A clean success that moved
no role. The sweep would then find the same divergence on its next run, forever, with
nothing in any log.

It is a latent hole rather than an active one. Turning that option off stops every
other NF/Discord path too — the resync button, the join flow, the event-driven sync —
so a board in that state has bigger news than a churning cron, and the option is on in
production. Unlike the two guarded preconditions, ordinary operation does not reach it.
Worth knowing before someone toggles it while debugging something else.

## Reverting

Disable the addon. Everything it adds is a class extension, a cron entry, a template
modification or a phrase, so the integration is back to stock behavior on the next
message, the button is gone from the next page render, and the sweep stops running.
The vendor's own cron returns to its still-dormant, still-option-gated behavior when
the veto goes with it.

Two things it writes have no vendor equivalent, and neither needs unwinding. One is
a flood-check row under its own `cav7_discord_resync` key, one per member who has
used the button; XenForo's hourly cleanup prunes flood-check rows a day after they
were last touched, so they age out on their own. The other is a server error-log row
prefixed `Cav7/DiscordSyncPatch:`, written when a press queued nothing that nothing
accounts for, when the sweep cannot read a guild's members, and once per run in which
the sweep stripped an unlinked holder; those age out with the rest of the log, on the
`errorLogLength` option.

What the sweep did to Discord is not unwound by disabling it, because it is not the
addon's state: a member whose roles were corrected keeps the corrected roles, and an
unlinked holder whose managed roles were stripped stays stripped. Both are the state
the forum said was right at the time.

## Requirements

- XenForo 2.3+
- [NF Discord Integration](https://nixfifty.com/products/discord-integration.7/) 2.12.0+
- The bot must hold Discord's **`GUILD_MEMBERS` privileged intent**, granted in the
  Discord developer portal on the account that owns the bot. The reconciliation
  sweep reads the guild's member list, and that endpoint refuses without it. Nothing
  else in the addon needs it: without the intent the sweep still corrects everything
  visible from the forum side, logs that it could not read the guild, and leaves
  hand-edited roles and unlinked holders unnoticed.

## Assumptions about code it does not own

The sync fix and the resync button both depend on things nobody promised would stay
true. Mostly that is NF/Discord: how it queues a per-user sync, what it stores about
one, and what it reads back. The resync action also leans on XenForo core — how the router builds an
action name out of a route's action prefix, the signature and the atomicity of
`FloodCheckService::checkFlooding()`, and how wide `xf_flood_check.flood_action` is —
so a core upgrade can break it as readily as a vendor one. They are listed in the docblocks of
`NF/Discord/ApiMessage/SyncUser.php` and `XF/Pub/Controller/Account.php`.

`NF/Discord/Api.php` carries its own, and they are the sharpest here because they are
about a vendor **field** rather than a method: that `Api::request()` is what every call
goes through, that `$retryAfter` keeps that name and stays writable by a subclass, and
that `assertNotRateLimited()` is the only other thing that writes it. A vendor upgrade
that moves any of the three leaves the override compiling and doing nothing. What
catches that is re-running the pass named below, not CI.

One of them is a rule a new caller has to know before writing the call, so it is
stated here rather than in the docblock of an action they have no reason to open.

### Never queue a per-user sync for a member with no linked Discord account

The integration will not refuse one, and establishing the link is the caller's job.

`Repository\Sync::queueSyncJobsForUser()` builds a `SyncUser` message per guild in the
server map and calls `setupFromUser()` on each. For a member with no `nfDiscord`
connected account that method returns a separate no-op message, and it returns before
it records the user id. The repository drops that return value and queues the original
message, whose user id is still null. The queue table's `user_id` column accepts null,
so the insert succeeds.

One row therefore lands per guild, each with a null user id. Nothing keyed on the
member's user id can see them, which is what makes them worse than queueing nothing:
the resync action's own pending guard reads that column, so it goes blind to work it
just queued. The rows are not inert either. Each is picked up by the queue runner in
the ordinary way, fails to resolve a user, records
`nf_discord_sync_err.xenforo_user_not_found` and is archived. The message reports
success while doing it, so the row archives with no failure count and nothing in the
error log. Nobody finds out.

This addon has one per-user sync call, the resync action in
`XF/Pub/Controller/Account.php`, and it checks the link ahead of every other
precondition for this reason.

Be clear about what the test suite can do with them: nothing. A run with no XenForo
and no vendor on the include path cannot observe either side of these seams. Rename
`queueSyncJobsForUser()`, drop `getDiscordConfiguration()`, edit the vendor template,
or narrow `xf_flood_check.flood_action`, and CI stays green. Those are found on a dev
stack, which is why the list below exists.

## Tests

`tools/run-tests.sh DiscordSyncPatch`. Every decision this addon makes lives in a
plain unit with no XenForo dependency:

| Unit | Decides | Covered by |
|---|---|---|
| `RoleClaim` | which roles one sync run may remove | its own tests |
| `SyncRecordStaleness` | whether a member's sync record still describes a correct sync | its own tests |
| `RoleDivergence` | whether a member's managed roles disagree with their groups | its own tests |
| `ManagedRoleStrip` | what an unlinked holder should be left holding | its own tests |
| `MemberCursor` | where the guild member walk goes next, and when it stops | its own tests |
| `RoleScope` | which of the configured roles belong to the guild in hand | `RoleClaim`'s tests, through its caller |
| `RoleReach` | which roles Discord will not let this bot move | its own tests |

That is the whole of what CI covers here. Everything that needs a live stack — the
four class-extension registrations, the method overrides, the cron entry, the resync
action with its preconditions and guards, the sweep's own guards, and the template
modification and phrases that put the button on the page — is checked on a dev stack
using the list below.

### Re-run these on a dev stack after an NF/Discord or XenForo upgrade

CI cannot see any of them, and each one fails silently in production if it breaks.

1. The button renders on the connected-accounts page for a linked member. This is the
   template modification still finding its anchor in the vendor template, which is the
   check most likely to break and the one CI is blindest to.
2. A press lands a per-user sync row in `xf_nf_discord_queue` for that member.
3. A second press inside five minutes is refused, naming the time remaining — for a
   member holding `general:bypassFloodCheck` as well as one without it.
4. The pending note renders beside the button once a row is queued.
5. With the integration's credentials blank, a sweep enqueues nothing. With them set
   but no server row carrying a non-empty guild id, likewise.
6. A member whose recorded group set is edited to disagree with their groups is
   corrected on the next sweep; one whose set agrees, with an active and error-free
   record, is not.
7. That correction is queued against the guild that has an id — with a second,
   guild-less server row present as well — and its stored message does not carry
   `skipLoggingChanges`.
8. A second sweep does not stack a second correction on a member whose first one is
   still queued.
9. The vendor's `SyncUsersFromDiscord` queues nothing **with its
   `nfDiscordEnableReverseSync` option made to read true**, fired the way the schedule
   fires it (resolved through `extendClass`, not the control panel's Run button, which
   calls the raw class). Without forcing the option the check proves nothing: the
   vendor's own early return is indistinguishable from the veto, so confirm the same
   call queues rows when made past the override.
10. The cron entry appears on the cron admin page, and the sweep runs when it fires.
11. A fresh install lands that entry **inactive**, and an entry an admin has enabled is
    still enabled after the add-on's data is imported again.
12. The per-user sync keeps an out-of-reach role, on **both** sides of its arithmetic,
    which needs two separate members (#242). One **holds** a role above the bot that no
    group grants: that role must survive and their reachable roles must
    still be corrected. One is in a group **granting** a role above the bot which they
    do not hold: their sync must succeed and simply not ask for it. A fix applied to
    only one side passes whichever member matches it, so run both and confirm each goes
    red on its own when the other side's filter is removed.

13. The `NF\Discord\Api` extension still does its job. Three things fail silently and
    none is visible to CI: that the class-extension row imports and is **active**, that
    `Api::factory()` therefore returns the composite, and that clearing `$retryAfter`
    before delegating still has an effect over the vendor's own `request()`. The last
    is the one an upgrade moves. Its check is also the authority for the hand-written
    parent in `tests/ThrottleSignalFreshnessTest.php`: if the vendor ever clears on the
    `ServerException | ConnectException` branch itself, that test stays **green while
    proving nothing**, and only re-running this catches it.

Items 5 to 11 were run for the 1.1.0 release and the result is recorded in
[docs/verification/reconciliation-sweep-guards.md](docs/verification/reconciliation-sweep-guards.md).
Item 13 was run for 1.1.1 and is recorded in the same file.

### Before a release, against the real guild

Nothing above reaches Discord: a dev stack's bot token is blank *and* its `fpm`
container is on a network marked `internal: true`, so the integration returns before
any call and no green run says anything about these. They need a real guild, the
`GUILD_MEMBERS` intent, and egress granted deliberately. They are release blockers, not
follow-ups.

**Items 1 to 6 were run on 2026-07-27 and passed**; the method and the numbers are in
[docs/verification/reconciliation-sweep-guards.md](docs/verification/reconciliation-sweep-guards.md).
Checks 2 to 6 ran in a throwaway guild at full write privilege, using a second bot
application invited nowhere else — the token is one global option while the guild is
per server row, so that is a structural boundary rather than a promise. The Discord
behaviour item 7 rests on was measured separately on 2026-07-28 and is recorded in the
same file. Check 1 and the
refusal half of check 5 ran against the live guild with that same bot holding no
`Manage Roles`, so every patch it issued was refused and no role moved.

1. The member fetch pages through the whole guild and terminates, reading a member
   count that matches the guild's own.
2. A managed role hand-added in Discord to a member whose groups do not grant it is
   taken back off within one cycle, and their self-assigned roles survive it.
3. A member whose groups changed while their sync was failing is brought into line
   within one cycle.
4. An unlinked holder loses their managed roles and keeps every other role.
5. The strip succeeds for a member holding the Nitro-booster role. This is the one
   that decides whether Discord refuses a role set omitting a role it manages; if it
   does, a set built without the booster role fails silently for every booster.
   Run it both ways round: the sweep reads what `patchGuildMemberRoles` returns and
   counts a refusal apart from a strip, so a deliberately bad set must be reported as
   refused rather than as a strip. Nothing short of a real guild can show that — a
   dev stack fails the guild-roles read first and never reaches the call.
6. Corrections the sweep makes appear in the corrected member's change log.
7. An out-of-reach role is reported rather than retried, and the printed remedy works
   (#242). Position a managed role above the bot's own role, run the sweep, and confirm
   the run names that role. Then **carry out the remedy the line prints** — move the
   bot's role above it — and re-read the affected member **from Discord**, confirming
   their managed roles now match what their groups grant. That the report stops naming
   them is not the check: stale or memoized state satisfies that without any role
   having moved.

`tools/discord-resync-cooldown-check.sh` does 2 and 3 unattended, for both kinds of
member. It builds its own user group, its own members and its own credentials, lets
XenForo build the permission cache from the group rather than editing the cache, and
removes all of it afterwards. 1 and 4 are what a browser is still needed for.

Getting as far as 2 by hand takes setup: the integration needs its credentials and at
least one server row that is both active and carries a guild id, or the action refuses
at a precondition and never reaches the queueing call. Put whatever you changed back
afterwards.

The role change itself is a different matter. A resync only moves roles once the
queue drains, and draining it means real calls to Discord against a real guild, which
no test run can count on reaching. What a run does show is the queue row landing, and
that is the outcome the action itself checks.

Two of the preconditions decide whether a press gets that far. The action refuses
before it queues anything if the integration has no credentials, and again if no
Discord server is active. On a stack in either state, a press exercises those
refusals and never reaches the queueing call.

## Addon info

| Field | Value |
|---|---|
| Addon ID | `Cav7/DiscordSyncPatch` |
| Namespace | `Cav7\DiscordSyncPatch` |
| Version | 1.1.1 (`1010170`) |
| Developer | Cav7 |

## License

See [LICENSE](LICENSE).

## Provenance

Written in this repo for issue #148, with the resync button added for issue #158 and
the reconciliation sweep for issue #157.
It was not imported from another repository.
