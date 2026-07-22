# ADR-0002: Reconcile by detecting divergence, not by re-syncing everyone

- **Status:** Accepted
- **Date:** 2026-07-21
- **Issues:** design decision for #157

## Context

#157 asks for a scheduled pass that keeps a member's Discord roles in step with
their forum groups and catches the divergences the event-driven syncs miss.
NF/Discord already ships a dormant cron for roughly this shape,
`Cron\SyncUsersFromDiscord`, but it re-runs the full per-user sync for every
connected member on a rotation: a member fetch and an unconditional role write
each. That is thousands of Discord calls per pass to correct a guild where a
handful of members have actually diverged, and the endpoint that would let it read
members in bulk is paginated by an `offset` the Discord API does not honour, so it
was never finished.

Divergence has two origins with very different detection costs. A forum-side change
the sync never applied is visible in the database: the member's current groups
disagree with the group set recorded on their last sync, or that sync errored. A
hand edit made in Discord is invisible to the database and can only be found by
asking Discord.

## Decision

The reconciliation sweep detects divergence cheaply, then corrects only the members
found divergent.

- Forum-side divergence is found by a database scan, at no Discord cost.
- Discord-side divergence is found by one bulk member fetch per run, paginated the
  way Discord actually pages (an `after` cursor, not the vendor's `offset`), then
  comparing each member's managed roles against the roles their current groups
  grant.
- Correction is delegated to the vendor's per-user sync message, enqueued only for
  the divergent members, so every fix still runs through the authoritative path
  this addon already patches.

The divergence test is a pure unit in the spirit of `RoleClaim`: the rule lives in
one place that runs without XenForo, and the cron stays a thin adapter that gathers
inputs and enqueues corrections.

## Consequences

- A pass costs a database scan plus a few member-list calls, not thousands of
  writes, so it can run every quarter-hour rather than crawling. Idempotent
  re-writes of already-correct members are gone.
- The bulk fetch needs the bot to hold Discord's `GUILD_MEMBERS` privileged intent.
  The addon will not ship until that intent is granted. The dev stack cannot
  exercise this path at all, because its bot token is blanked and the integration
  bails before any call, so the Discord-side half is confirmed against a real guild
  rather than in CI.
- Corrections run through the existing sync message with change logging left on, so
  each correction the sweep makes is recorded against the member. This differs from
  the vendor cron, which suppressed those entries.
