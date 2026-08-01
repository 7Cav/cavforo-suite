# 0001 - Correct the reset threshold, not the clock or the timezone

## Status

Accepted.

## Context

Siropu Donations decides a recurring goal is due for reset by comparing now
against a threshold built by advancing the cycle start by N months and, for a
goal set to reset on the 1st, snapping the date to the 1st of that month. The
snap changes the date and preserves the time of day, seconds included, so the
threshold carries the hour, minute and second of whenever the previous reset ran.

XenForo's next-run calculation never zeroes the seconds — it seeds from the
actual execution instant and applies unit rollovers — and with no external job
runner the cron executes on the first page view after its scheduled minute. The
fire second is therefore arbitrary and re-seeded every run, and whether a given
month's reset happens is decided by which of two seconds is larger. Against goal
5's stored `start_date` of `00:30:43`, 17 of the 60 possible fire seconds would
have reset it.

The cost of a miss is not a delayed reset. The goal keeps accumulating into the
previous cycle, so the progress bar shows two months summed, and when the reset
lands a day late the donations made on the 1st fall outside the new window and
appear on no month's bar at all. Confirmed end to end on a dev stack: $890
counted before a late reset, $0 after.

Three separable defects sit in that one expression, and a fourth thing that looks
like one but is not.

## Decision

Override `canResetRecurringGoal()` from this add-on via a class extension, for
goals configured to reset on the 1st only, and derive the threshold as **midnight
UTC on the 1st of the month N months after the cycle start's month**.

Specifically:

1. **Normalise to midnight.** The threshold becomes a property of the calendar
   rather than of the previous reset's clock time, so any cron fire on the due
   day satisfies it and the race disappears. It also aligns the cycle boundary
   with the window `Cron/GoalAmount.php` sums over, so the two can no longer
   disagree about which month a donation belongs to.
2. **Compute the target month arithmetically, not by `+N months`.** PHP resolves
   31 January plus one month as 3 March, so snapping to the 1st lands in March
   and February never becomes due. Asking directly for the 1st of a computed
   month has no day to overflow. This is a second defect, not a consequence of
   the first, and it is fixed here because the same expression is being replaced.
3. **Read `\XF::$time` rather than the wall clock.** The vendor compares against
   `new \DateTime('now')` while its own `resetRecurringGoal()` stores
   `\XF::$time`, so the instant the decision is made against is not the instant
   recorded as the new cycle start. Reading `\XF::$time` makes them the same.
4. **Hand every other configuration back to the vendor.** A goal on a rolling
   anniversary has no day boundary to race, so there is nothing here to fix and
   the override defers.

## Considered options

**Editing the vendor entity.** Rejected: the file is clobbered on every Siropu
upgrade, and this repo ships no vendor code.

**A cron that repairs `start_date` after the fact.** Rejected. It would run after
the damage, could not recover donations already excluded from a cycle, and would
leave two mechanisms writing the same column.

**Honouring the board's `guestTimeZone`, as the original bug report suggested.**
Rejected, and this is the thing that looks like a defect but is not one to fix
here. The option is entirely inert in the vendor's comparison: the
`@timestamp` constructor pins the `DateTime` to UTC and silently ignores the
timezone argument, and the comparison is on absolute timestamps. Four different
zones were checked and produced a byte-identical threshold. So "fixing" the
timezone would not restore an intended behaviour that had broken — it would move
the month boundary off UTC midnight for any board not already on UTC, which is a
behaviour change wearing a cleanup's clothes. `RecurringSchedule` fixes UTC
explicitly and internally instead, which preserves today's answers exactly.

**Clamping a `months` of 0 to 1.** Rejected as scope creep. The goal form's input
carries `min="1"`, which is client-side only, so a `months` of 0 is reachable by a
crafted POST but not by normal use — and with it the vendor already resets on
every cron run, so omitting the clamp introduces no regression. Closing that gap
is server-side validation in vendor territory and its own decision.

## Consequences

- The override owns one vendor method for one configuration. Every other shape,
  including a goal with no recurring settings, is answered by the vendor.
- The seam is the returned verdict, never whether the parent was entered. A
  refactor that consults the parent first and overrides afterwards is invisible
  to the tests, deliberately: pinning the call would be pinning structure.
- The threshold arithmetic lives in `RecurringSchedule`, a pure function with no
  XenForo dependency, so it is reachable by `tools/run-tests.sh`. The override
  itself is reachable over a stubbed `XFCP_Goal`. Neither needs an install.
- Decision 3 costs nothing at the current schedule, but it does add a
  constraint. `\XF::$time` is frozen at request start, so in principle a request
  beginning before midnight on the 1st and executing the cron after it would
  read the pre-midnight value and defer the reset a full cycle — a 24-hour slip,
  because of the midnight alignment.
  That cannot happen as things stand. `canResetRecurringGoal()` has exactly one
  caller, the vendor's `siropuDonationsRecurring` cron, and XenForo runs an entry
  only once `\XF::$time` has reached its `next_run`. That entry is scheduled at
  `00:30`, so the earliest `\XF::$time` this method can be reached with on the
  1st is already half an hour past the threshold. The window is closed, not
  merely narrow.
  **The constraint is therefore on the schedule, not the code**: move that cron
  to `00:00` and the margin disappears, at which point a request starting at
  `23:59:5x` could defer a day. Anyone rescheduling it should read this first, or
  switch the comparison back to the wall clock, which is a one-line change.
- Decision 2 changes behaviour for a cycle start on the 29th to 31st, which the
  reported bug did not involve. A missed reset only pushes `start_date` to the
  2nd, so the board is unlikely to reach those days; the fix is preventative.
- If the vendor stops calling `canResetRecurringGoal()` from
  `Cron\Goal::resetRecurringGoals()`, or the extension's `from_class` stops
  naming a real class, the override becomes inert with no error and the reset
  silently returns to missing. Neither is visible to CI; both are on the
  add-on README's post-upgrade checklist.
