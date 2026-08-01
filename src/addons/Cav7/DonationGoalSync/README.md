# 7Cav - Donation Goal Sync

Keeps the recurring donation goal honest, in two independent ways. A cron
recomputes `xf_siropu_donations_goal.donation_amount` from the donations table
instead of trusting the running total the
[Siropu Donations](https://xenforo.com/community/resources/donations.5069/)
add-on maintains, so nobody has to open the goal form and correct the number by
hand. And a class extension corrects the vendor's monthly reset, which
otherwise misses roughly three months in four.

It ships none of the vendor's code and changes no vendor file. It reads the
vendor's entities through XenForo's entity manager, writes one column, and
overrides one method.

## The problem it fixes

Siropu Donations increments the goal's `donation_amount` as each donation
completes. A donation that never gets attributed to the goal never reaches that
counter, and the goal under-reports for the rest of the cycle. Two ways that
happens on this board:

- **`donation_goal_id = 0`** — the donate flow completed without attaching a
  goal.
- **A stale goal id** — legacy recurring PayPal subscriptions. The renewal IPN
  clones the original subscription donation and reuses its goal id verbatim, so
  a renewal against a goal that has since been deleted carries an id matching no
  goal row.

Both are money the board actually received against the current recurring drive,
and neither shows on the progress bar.

## What it does

Every hour at `:00` and `:30`, `Cron/GoalAmount.php` sums the completed
donations dated on or after the goal's cycle start and writes the total to
`donation_amount` — but only when the recomputed figure differs from what is
stored, so a steady state writes nothing.

A donation counts toward the recurring goal when it is `status = 'completed'`,
`donation_date >= start_date`, and either

- its `donation_goal_id` is the recurring goal's, or
- its `donation_goal_id` matches **no existing goal row** — the orphan case
  above, covering both `0` and a deleted id.

Donations attached to a goal row that still exists are left alone **even when
that goal is disabled**, so a closed campaign — a Member-in-Need drive, say — is
never swept into the recurring total. Goal ids are `AUTO_INCREMENT` and never
reused, so a deleted id stays a permanent orphan and cannot later be reclaimed
by an unrelated campaign.

The recurring goal is identified by its `settings.recurring.enabled` flag among
the enabled goals, not by a hardcoded id, so recreating the goal does not
require a code change.

## The monthly reset, and why it was missing

The vendor's reset threshold inherited the *time of day* of the previous reset,
down to the second, so a goal that last reset at `00:30:43` was not due again
until `00:30:43` on the 1st. XenForo's cron fires at an arbitrary second within
its scheduled minute, so whether a month's reset happened came down to which of
two seconds was larger. On this board that was 17 chances in 60 — the reset
missed roughly three months in four.

A miss does not correct itself cleanly. The goal keeps accumulating into the
previous cycle, so the progress bar shows two months added together, and when
the reset lands a day late the donations made on the 1st fall outside the new
window and are dropped from the cycle entirely — they survive in
`recurring_amount` as a lifetime figure but appear on no month's bar.

`Siropu/Donations/Entity/Goal.php` overrides `canResetRecurringGoal()` for goals
configured to reset on the 1st, so the threshold is **midnight on the 1st**. Any
cron fire on the due day now satisfies it, so the reset lands on the 1st instead
of slipping to the 2nd and taking a day's donations out of both cycles. The
arithmetic is in `RecurringSchedule`, which also settles a month-overflow case
the vendor walked into and fixes UTC internally rather than reading the board's
`guestTimeZone`.

**One boundary is still not exact, deliberately.** The vendor's
`resetRecurringGoal()` — which this add-on does not override — stores
`\XF::$time` as the new cycle start, so the cycle begins at the moment the cron
fired (about `00:30`) rather than at the midnight the threshold used. Donations
made between midnight and the cron fire on the 1st are therefore counted in the
outgoing month rather than the new one. Across this board's entire donation
history that window contains **one donation, of $10**, against 328 donations
totalling $4,634 made on a 1st overall — so the exposure is the half-hour, not
the day the bug used to cost. Closing it would mean overriding a second vendor
method to write the threshold instead of the clock; that has not been judged
worth it.

### Why it does not use the board's timezone

This looks like a bug and is not one, so it is worth stating plainly before
somebody fixes it.

The vendor passes the board's `guestTimeZone` into a `DateTime` built from a
`@timestamp`, and PHP **silently ignores the timezone argument in that form** —
the object is UTC whatever you asked for. The comparison is then on absolute
timestamps, which no timezone affects. Four zones were checked and produced a
byte-identical threshold, so the option has no effect on this decision at all.

Honouring it would therefore not restore some intended behaviour that had
broken. It would move the month boundary from UTC midnight to board-local
midnight, changing which cycle a donation near the boundary is counted in, on
every board not already on UTC. That is a behaviour change, and it belongs to
whoever wants that behaviour — not to a fix for the seconds race.
`RecurringSchedule` fixes UTC explicitly and internally, which preserves the
answers this board gets today.

## What it does not do

**It resets nothing itself.** `start_date`, `recurring_amount`,
`donation_count`, `last_donor_user_id` and `last_donation_id` are still the
vendor's to write, and the vendor's own `siropuDonationsRecurring` cron still
performs the reset. This add-on changes only the vendor's answer to *"is it due
yet?"*, and writes exactly one column of its own, `donation_amount`.

**It leaves goals on a rolling anniversary alone.** A goal that is not
configured to reset on the 1st has no day boundary to race, so the question is
handed straight back to the vendor. The override owns one configuration.

**It grants and checks no permission**, adds no table, option, field, route,
phrase or template. `Setup.php` exists only so XenForo has a setup class to
call; it declares no install, upgrade or uninstall steps.

## Fail-safe behaviour

The cron aborts without writing when any of these hold:

- `Siropu/Donations` is not in the active add-on cache.
- Either vendor entity structure is missing, or has lost one of the columns the
  query depends on (`donation_goal_id`, `start_date`, `donation_amount`,
  `enabled`, `settings` on the goal; `donation_goal_id`,
  `primary_currency_amount`, `donation_date`, `status` on the donation).
- No enabled goal carries `settings.recurring.enabled`.
- The goal's id or `start_date` is non-positive.
- The computed sum is null, non-numeric, or negative.

Table names are taken from the entity structures rather than spelled in the SQL,
so a vendor table rename cannot silently point the query at the wrong place.

Anything that still throws is caught, sent to the XenForo error log through
`\XF::logException()`, and swallowed. A schema change or a transient database
error therefore leaves the goal at its last good value rather than breaking the
scheduled run — but a persistent fault is **silent apart from the error log**.
Reading `xf_error_log` is how you find out; there is no other symptom beyond the
goal total going stale.

## If two recurring goals are enabled

The add-on takes the first enabled goal whose `settings.recurring.enabled` is
set, in whatever order the finder returns, and ignores the rest. The board has
only ever had one, and the vendor's own reset cron has the same
one-recurring-goal assumption, but nothing enforces it — enable a second and
which one gets maintained is not defined.

## Requirements

- XenForo 2.3.0+
- Siropu Donations 1.6.1+ (declared in `addon.json`; the floor is the version
  this was written and verified against, not the earliest that would work)

## Installation

1. Copy `src/addons/Cav7/DonationGoalSync` into your XenForo installation at the
   same path.
2. Install it: `php cmd.php xf-addon:install Cav7/DonationGoalSync`.

The cron entry `cav7DonationGoalSync` is created by the install and is active
immediately. Note that XenForo runs cron entries off page views unless an
external runner invokes `job.php`, so the actual fire time drifts within the
scheduled minute.

## Tests

`tools/run-tests.sh DonationGoalSync`. Two files, both covering the reset fix.

- `tests/RecurringScheduleTest.php` exercises the threshold arithmetic as a pure
  function, including two rows that probe the ambient timezone.
- `tests/GoalResetDecisionTest.php` runs the class extension itself over a
  stubbed `XFCP_Goal` and a pinned `\XF::$time`.

Each file's own docblock states what it pins and how it avoids pinning the wrong
thing; that is not repeated here.

What neither covers: the vendor's own seconds-preserving body, which lives in a
file we neither ship nor can load without an install. These are a specification
pin on the arithmetic that replaces it. The defect itself was confirmed
separately, by mutation control against a live dev stack.

**`Cron/GoalAmount.php` remains uncovered.** Every branch of
`recomputeRecurringGoal()` runs through `\XF::app()`, the entity manager and the
vendor's entity structures. The orphan-sweep query and the guard ladder are both
reachable from a stubbed `\XF` harness of the kind
`GoalResetDecisionTest.php` now demonstrates; that work has not been done. Stated
rather than papered over — asserting on source text instead would check nothing,
per [CONTRIBUTING.md](../../../../CONTRIBUTING.md#what-belongs-in-ci-and-what-does-not).

The `_data`/`_output` agreement, the `addon.json` shape and the class-extension
row order are covered repo-wide by `tools/check-data-consistency.php` and
`tools/validate-addon.php`.

### Re-run on a dev stack after a XenForo or Siropu Donations upgrade

1. **Confirm the class extension still resolves.** `get_class()` on a goal
   entity must report `Cav7\DonationGoalSync\Siropu\Donations\Entity\Goal`. This
   is the check CI is blindest to and the one most likely to break: an extension
   whose `from_class` no longer names a real class stays active, valid and
   exported while being completely inert, and the only symptom is that the reset
   quietly goes back to missing.
2. **Confirm the vendor still calls `canResetRecurringGoal()`** from
   `Cron\Goal::resetRecurringGoals()`. If the vendor inlines or renames the
   check, the override is bypassed with no error.
3. Confirm `Siropu\Donations:Goal` and `Siropu\Donations:Donation` still carry
   the nine columns listed under **Fail-safe behaviour**. A rename makes the
   cron abort silently, which looks identical to "nothing to do".
4. Make a donation with `donation_goal_id` set to a deleted goal id and confirm
   the next run folds it in.
5. Make a donation against a *disabled* goal that still exists and confirm the
   next run does **not** fold it in.

## Addon info

| Field | Value |
|---|---|
| Addon ID | `Cav7/DonationGoalSync` |
| Namespace | `Cav7\DonationGoalSync` |
| Version | 1.1.0 (`1010070`) |
| Developer | Cav7 |

## License

See [LICENSE](LICENSE).

## Provenance

Written directly on the 7Cav dev stack and exported into this repo from
`src/addons/Cav7/DonationGoalSync` there. It was not imported from another
repository, so it has no history before this commit.
