# ADR-0003: One scheduled reconciler for roles; neutralize the vendor's sync cron

- **Status:** Accepted
- **Date:** 2026-07-21
- **Issues:** design decision for #157

## Context

With the reconciliation sweep (ADR-0002) this addon becomes the one thing that
keeps a member's Discord roles in step with their forum groups. NF/Discord ships
its own scheduled reconciler, the `Cron\SyncUsersFromDiscord` cron, gated behind an
option, `nfDiscordEnableReverseSync`, that is defined nowhere in the vendor's data
and so reads false. It sits dormant.

Its name suggests it pulls roles from Discord, but it does not: it enqueues the same
forward per-user sync we do, blindly, for every connected member. The genuinely
reverse-direction code the option also gates (`Job\ReverseSync`,
`Service\ReverseSync`) is never enqueued or called from anywhere in the vendor and
is dead.

Left in place, that cron is a second reconciler over the same members. If the
option were ever defined and turned on, two schedules would drive the same roles: at
best redundant churn, and if the vendor's logic ever diverged from ours (a change to
what its cron enqueues, or to the sync message itself) two owners pushing different
answers at the same roles, back and forth.

## Decision

Class-extend `NF\Discord\Cron\SyncUsersFromDiscord` and override `syncUsers()` to do
nothing, never calling the parent. XenForo resolves a cron entry's class through
`extendClass` before invoking it, the same seam this addon already uses for the sync
message, so the override runs wherever the entry fires. While this addon is
installed the vendor's cron cannot reconcile roles, whatever its option says.
Scheduled reconciliation has exactly one owner: the sweep.

## Consequences

- There is one scheduled role reconciler, not a race between two. Nobody can turn a second,
  blind one on by defining a hidden option.
- This is a third override on vendor internals, on a different vendor class from the
  sync-message fix, so ADR-0001's "one class extension" holds for what it was about
  (the two halves of that fix sharing a registration) and does not reach this.
- `Job\ReverseSync` and `Service\ReverseSync` are left alone: nothing reaches them
  today, and the cron was not their trigger. If a future vendor version wires them
  up, that reverse direction is a separate decision, not something this override
  covers.
- Reverting is disabling the addon, which returns the vendor cron to its own still
  dormant, still option-gated behaviour.
- `tests/WiringTest.php` pins the extension registration and that `syncUsers()` is
  overridden to a no-op, so losing the veto fails the build.
