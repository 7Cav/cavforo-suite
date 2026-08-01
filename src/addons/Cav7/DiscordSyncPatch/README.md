# 7Cav - Discord Sync Patch

Makes a member's forum user groups authoritative for every Discord role a user group
grants, lets members ask for that sync themselves, and reconciles the guild on a
schedule.
Extends [NF Discord Integration](https://nixfifty.com/products/discord-integration.7/);
it changes no vendor file.

Without it, a member's Discord roles could stay wrong after their user groups changed
— a discharged member keeping a role that marks them as currently serving — and stay
wrong until somebody fixed it by hand. This addon makes each queued sync read the
member's current groups, and widens what that sync is allowed to take back off, so a
member already in that state corrects themselves on their next ordinary sync. There is
no migration step and no mass role update when it is enabled.

Terms used below — managed role, divergence, unlinked holder — are defined in
[CONTEXT.md](CONTEXT.md). The decisions behind the behaviour are in
[docs/adr/](docs/adr/).

## The resync button

Members get a button on their connected-accounts page that queues a sync of their own
Discord roles, for themselves and nobody else, across every guild the forum syncs. It
replaces the standing instruction to disconnect and reconnect Discord, which drops any
member whose Discord account is their only login into a password reset. It promises no
completion time, because the sync drains behind a queue that yields to Discord's rate
limits, and it runs whether or not anything is actually wrong.

A press is refused, naming its cause, when the member has no Discord account linked,
when the integration is missing its Discord credentials, when no Discord server on the
forum is active, when a sync is already pending for that member, or when they queued
one less than five minutes ago. That cooldown binds every member, including anyone
holding `general:bypassFloodCheck`.

A resync does not re-join a guild the member has left, where a reconnect would; the
note under the button says so. Its reply is a flash message, so with JavaScript off the
press still queues but the member is not told what happened.

## The reconciliation sweep

A cron entry corrects the members who have quietly fallen out of step and leaves
everyone already in agreement alone. It fires every quarter-hour once running.

**It ships disabled.** A fresh install lands the cron entry inactive; enable it on the
XenForo cron admin page. Whether it runs and how often are the only knobs, and both
live on that page, so the addon ships no options. Once an admin has set the toggle,
later upgrades leave it alone.

It corrects two populations, both found from one read of the guild's members:

- **Members whose roles disagree with their groups**, whether the sync never applied a
  group change or somebody edited a role by hand in Discord. They are corrected by
  queueing the vendor's own per-user sync.
- **Unlinked holders** — people in the guild holding forum-managed roles with no
  connected account to justify them, usually a disconnect whose role strip failed or
  never fired. The sweep strips managed roles and nothing else; self-assigned, game and
  Nitro-booster roles all stay, and it never removes anyone from the guild, whatever
  the vendor's disconnect-kick option says.

**One run makes at most 100 strip calls per guild**, and its log line says when it
stopped at that bound and how many holders it left for the next run. Ordinary
drift never reaches that bound. Two things do, and both are worth expecting: pointing a
user group at a role hundreds of members already hold, and a mass unlink, where every
strip would then be wrong. The bound makes either visible at 100 calls rather than
after all of them.

The sweep also vetoes NF/Discord's own scheduled reconciler, so nothing else drives the
same roles on a schedule.

## A role assigned by hand in Discord will not survive

**If a user group grants a role, that role can no longer be handed out in Discord and
expected to stick.** The next sync takes it back off. From the sync's position a
hand-added role and one left behind by the bug look identical, so keeping one while
dropping the other is not available.

Roles that no user group maps to stay outside the addon entirely, so self-assigned
interest and game roles keep working exactly as they did.

## What it does not touch

Reverse sync (a Discord role granting a forum user group), nickname sync, avatar sync,
the announcement flow, and the join and kick decisions. It never changes which roles a
user group grants — only what the sync may remove.

## Requirements

- XenForo 2.3+
- [NF Discord Integration](https://nixfifty.com/products/discord-integration.7/) 2.12.0+
- The bot must hold Discord's **`GUILD_MEMBERS` privileged intent**, granted in the
  Discord developer portal on the account that owns the bot. The sweep reads the
  guild's member list and that endpoint refuses without it. Nothing else needs it:
  without the intent the sweep still corrects everything visible from the forum side,
  logs that it could not read the guild, and leaves hand-edited roles and unlinked
  holders unnoticed.

## Installation

1. Copy `src/addons/Cav7/DiscordSyncPatch` into your XenForo installation at the same
   path.
2. Install the addon: `php cmd.php xf-addon:install Cav7/DiscordSyncPatch`.
3. Enable the reconciliation sweep's cron entry if you want it; it installs inactive.

Run the tests with `tools/run-tests.sh DiscordSyncPatch`. They need only `php` and
`pdo_sqlite`. Everything that needs a live XenForo is verified by hand on a dev stack
before a release, per [CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## Removing it

Disable the addon. Everything it adds is a class extension, a cron entry, a template
modification or a phrase, so the integration is back to stock behaviour on the next
message, the button is gone from the next page render, and the sweep stops.

Nothing needs cleaning up afterwards. The two rows it writes with no vendor equivalent
— a flood-check row per member who used the button, and error-log rows prefixed
`Cav7/DiscordSyncPatch:` — both age out on their own. Roles the sweep already corrected
in Discord stay as they are; they were the state the forum said was right at the time.

## License

See [LICENSE](LICENSE).

## Provenance

Written in this repo for issue #148, with the resync button added for issue #158 and
the reconciliation sweep for issue #157. It was not imported from another repository.
