# 7Cav - Donation Goal Sync

Keeps the recurring donation goal honest, in two independent ways. A cron
recomputes `xf_siropu_donations_goal.donation_amount` from the donations table
instead of trusting the running total the
[Siropu Donations](https://xenforo.com/community/resources/donations.5069/)
add-on maintains, so nobody has to open the goal form and correct the number by
hand. And a class extension corrects the vendor's monthly reset, which otherwise
misses roughly three months in four.

It ships none of the vendor's code and changes no vendor file. It reads the
vendor's entities through XenForo's entity manager, writes one column, and
overrides one method.

## The recomputed total

Every hour at `:00` and `:30`, `Cron/GoalAmount.php` sums the completed donations
dated on or after the goal's cycle start and writes the total to
`donation_amount` — but only when the recomputed figure differs from what is
stored, so a steady state writes nothing.

A donation counts toward the recurring goal when it is `status = 'completed'`,
`donation_date >= start_date`, and either

- its `donation_goal_id` is the recurring goal's, or
- its `donation_goal_id` matches **no existing goal row** — covering both `0`,
  where the donate flow completed without attaching a goal, and a deleted id,
  which legacy recurring PayPal renewals carry because the renewal IPN clones the
  original subscription donation and reuses its goal id verbatim.

Donations attached to a goal row that still exists are left alone **even when
that goal is disabled**, so a closed campaign — a Member-in-Need drive, say — is
never swept into the recurring total. Goal ids are `AUTO_INCREMENT` and never
reused, so a deleted id stays a permanent orphan and cannot later be reclaimed by
an unrelated campaign.

The recurring goal is identified by its `settings.recurring.enabled` flag among
the enabled goals, not by a hardcoded id, so recreating the goal does not require
a code change.

## The monthly reset

`Siropu/Donations/Entity/Goal.php` overrides `canResetRecurringGoal()` for goals
configured to reset on the 1st, so the threshold is **midnight on the 1st**. Any
cron fire on the due day satisfies it, so the reset lands on the 1st instead of
slipping to the 2nd and taking a day's donations out of both cycles. The
arithmetic is in `RecurringSchedule`, which fixes UTC internally rather than
reading the board's `guestTimeZone`.

**One boundary is still not exact, deliberately.** The vendor's
`resetRecurringGoal()` — which this add-on does not override — stores the moment
the cron fired (about `00:30`) as the new cycle start, rather than the midnight
the threshold used. Donations made between midnight and the cron fire on the 1st
are therefore counted in the outgoing month rather than the new one.

## What it does not do

**It resets nothing itself.** `start_date`, `recurring_amount`, `donation_count`,
`last_donor_user_id` and `last_donation_id` are still the vendor's to write, and
the vendor's own `siropuDonationsRecurring` cron still performs the reset. This
add-on changes only the vendor's answer to *"is it due yet?"*, and writes exactly
one column of its own, `donation_amount`.

**It leaves goals on a rolling anniversary alone.** A goal that is not configured
to reset on the 1st has no day boundary to race, so the question is handed
straight back to the vendor. The override owns one configuration.

**It grants and checks no permission**, adds no table, option, field, route,
phrase or template. `Setup.php` exists only so XenForo has a setup class to call;
it declares no install, upgrade or uninstall steps.

## Fail-safe behaviour

The cron aborts without writing when any of these hold:

- `Siropu/Donations` is not in the active add-on cache.
- Either vendor entity structure is missing, or has lost one of the columns the
  query depends on.
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

Run the tests with `tools/run-tests.sh DonationGoalSync`. They need only `php`.
Everything that needs a live XenForo is verified by hand on a dev stack before a
release, per [CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## License

See [LICENSE](LICENSE).

## Provenance

Written directly on the 7Cav dev stack and exported into this repo from
`src/addons/Cav7/DonationGoalSync` there. It was not imported from another
repository, so it has no history before this commit.
