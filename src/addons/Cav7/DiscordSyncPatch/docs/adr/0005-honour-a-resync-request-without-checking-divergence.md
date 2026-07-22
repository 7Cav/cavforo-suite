# ADR-0005: Honour a member's resync request without checking for divergence

- **Status:** Accepted
- **Date:** 2026-07-22
- **Issues:** design decision for #158

## Context

#158 gives a member a button that resyncs their own Discord roles, replacing the
standing instruction to disconnect and reconnect their Discord account. Reconnecting
only works because inserting a connected-account row makes NF/Discord queue a
per-user sync; the button asks for that sync directly.

The obvious question is whether the button should check first. ADR-0002 decided the
reconciliation sweep detects divergence before correcting anyone, and a reader who
finds that decision and then finds a button that syncs unconditionally will assume
one of the two is wrong.

Checking is not free here. Forum-side divergence is cheap to read from the database,
but a member pressing this button is usually pressing it because something is wrong
that the forum side already agrees about, or because a role was edited by hand in
Discord. The Discord-side half of the test needs a guild member fetch, which is one
rate-limited call against Discord. The sync it would avoid costs a member fetch and,
when nothing has changed, no writes at all. So for a single member the check costs
roughly what it saves, and it buys a "you were already correct" message the member
did not ask for.

## Decision

A resync runs blind. The button queues the vendor's per-user sync for the member who
pressed it, with no prior divergence test.

The economics that justified ADR-0002 do not reach it. That decision was about a
scheduled pass over thousands of connected members, where skipping the ones already
in agreement is the difference between a pass that can run every quarter-hour and one
that crawls. One member who asked is not a population, and the saving does not exist
at that scale.

Nor does this make a second reconciler in the sense ADR-0003 was protecting against.
That decision is about two schedules pushing at the same roles without knowing about
each other. A resync decides nothing: it is an input to the same per-user sync that
group changes and the sweep already drive, and the **claim** rules that sync applies
are unchanged.

Because it is unconditional, it needs guards the sweep does not. A resync is refused
while one is already pending for that member, and a member may queue at most one
every five minutes. Neither guard is about correctness. Both exist so that one
member cannot spend the forum's Discord rate limit.

## Consequences

- A member who is already correct can queue a sync that changes nothing. That is the
  accepted cost of not checking, and it is bounded by the two guards.
- The guards are the only thing bounding request volume, so they are load-bearing in
  a way the sweep's batching is not. Staff usually hold XenForo's flood-bypass
  permission, which is why the pending check exists separately from the cooldown
  rather than as a refinement of it.
- The sync a member requests is the same one everything else enqueues, so a resync
  can remove a **managed role** their groups do not grant, exactly as a group change
  would. The button does not create that behaviour and does not warn about it.
- Adding a divergence check later is contained: it would sit in front of the queueing
  call and change nothing else. This is recorded because the contrast with ADR-0002
  is surprising, not because it would be expensive to revisit.
