# ADR-0003: One scheduled reconciler for roles; neutralize the vendor's sync cron

- **Status:** Accepted
- **Date:** 2026-07-21
- **Issues:** design decision for #157
- **Note (2026-07-25):** the `tests/WiringTest.php` named below was deleted as a
  source-text change detector. Do not reinstate it or write another like it; see
  ["What belongs in CI, and what does not"](../../../../../../CONTRIBUTING.md).

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

**Correction (2026-07-27, during implementation):** this ADR previously said the
override "runs wherever the entry fires", which is not true.
`XF\Admin\Controller\CronEntryController::actionRun` invokes
`call_user_func([$entry->cron_class, $entry->cron_method])` on the raw class with no
`extendClass`, so an admin pressing "Run" in the control panel reaches the vendor's
own method. The decision stands as written — what it is about is a second reconciler
running *on a schedule*, and a control-panel button is a deliberate human act, with
the vendor's own undefined option still gating it. But the veto is not absolute, and
anything verifying it must fire through the scheduled path or it is testing the
vendor's early return instead.

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
- `tests/WiringTest.php` pinned the extension registration and that `syncUsers()` is
  overridden to a no-op. That file has since been removed (see the note above): it
  matched source text, so it would have stayed green on a veto that no longer took
  effect. Losing the veto shows up as the vendor cron reconciling again, which is a
  dev-stack check.
