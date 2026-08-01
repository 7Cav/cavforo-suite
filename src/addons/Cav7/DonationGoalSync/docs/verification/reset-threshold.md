# Verification: does the reset-threshold override actually reach the vendor's cron?

A recorded manual pass against a live board, standing in for CI coverage this
cannot have. `tools/run-tests.sh` runs the override over a stubbed `XFCP_Goal`,
which proves what the method answers but not that XenForo ever calls it. A class
extension whose `from_class` no longer names a real class stays active, valid and
exported while being completely inert, and the only symptom is that the monthly
reset quietly goes back to missing. Nothing without a XenForo install and the
vendor add-on present can tell the two apart.

Why the override exists at all, and the alternatives rejected:
[ADR 0001](../adr/0001-correct-the-reset-threshold-rather-than-the-clock.md).

Run against `~/srv/xenforo-dev` (XenForo 2.3.11, Siropu Donations 1.6.1) on
2026-08-01, for `Cav7/DonationGoalSync` 1.1.0.

## What is being checked

Three things, none of them visible to CI:

1. A goal entity fetched through the entity manager resolves to the extended
   class, so the override is in the chain rather than merely registered.
2. The vendor's own `Siropu\Donations\Cron\Goal::resetRecurringGoals()` — the
   real cron, not a reimplementation — resets a goal that is due, on a fixture
   that reproduced the bug before the fix.
3. The same path does **not** reset a goal that is not yet due.

Assertions are on the observable outcome, `xf_siropu_donations_goal.start_date`
changing or not, read back from the database rather than from the entity. The
identity map serves a stale entity after a direct write, so a re-`find()` would
confirm the wrong state.

## Conventions used below

```bash
S=~/srv/xenforo-dev/app
# The stack holds a copy of the addon tree, not a symlink to the repo, so the
# code under test has to be put there before each run, and Docker Desktop's
# bind mount lags the host by about a second — check the container agrees
# before trusting a result.
rsync -a --delete src/addons/Cav7/DonationGoalSync/ $S/src/addons/Cav7/DonationGoalSync/
sleep 3
docker exec xenforo-staging-fpm md5sum /var/www/html/src/addons/Cav7/DonationGoalSync/RecurringSchedule.php
md5 -q src/addons/Cav7/DonationGoalSync/RecurringSchedule.php
# -> 1e7a3b8af23df9703fad873c76e99730 on both

docker exec -u 1004:987 xenforo-staging-fpm php /var/www/html/cmd.php xf-addon:upgrade Cav7/DonationGoalSync
# -> Importing... Add-on data (Class extensions)
```

Scripts referenced below live at `~/srv/xenforo-dev/verify/donation-goal-reset/`.

## 1. The extension is in the resolved class chain

```
resolved class : Cav7\DonationGoalSync\Siropu\Donations\Entity\Goal
override live  : YES
start_date     : 2026-07-02 00:30:43 UTC
threshold      : 2026-08-01 00:00:00 UTC
```

The stale `start_date` is production's own: the cycle should have restarted on
1 July and did not, which is the June->July miss. Under the vendor's arithmetic
the threshold read `2026-08-01 00:30:43`; it now reads midnight.

## 2. The fixture that reproduced the bug now resets

`reset-loop.php` sets `start_date` to a day in the previous month carrying a
time-of-day `OFFSET` seconds ahead of now, then runs the real vendor cron. At
`OFFSET=30` the vendor's threshold sits 30 seconds in the future, which before
the fix left the goal unreset — that is the whole bug.

```
now           : 2026-08-01 16:23:08 UTC
start_date    : 2026-07-02 16:23:38 UTC (ts=1783009418)
canReset      : true
after cron    : 2026-08-01 16:23:08 UTC (ts=1785601388)
DID RESET     : yes
  [restore] start_date back to 1782952243 (2026-07-02 00:30:43 UTC)
FAIL: expected no reset, got the opposite
```

**The `FAIL` line is the pass.** The script's assertion encodes the pre-fix
expectation (`OFFSET=30` means "expect no reset"), so the inversion is the
result being demonstrated. Its `threshold` line is its own reporting-only
recomputation of the *vendor* formula and is expected to disagree with the
override; `canReset` is the entity answering.

Before the fix, the identical invocation printed:

```
canReset      : false
DID RESET     : NO
PASS: goal did not reset as expected for OFFSET=30
```

## 3. A goal that is not due is left alone

Without this the row above is satisfied by an override that always says yes.

```
start_date    : 2026-08-01 00:00:00 UTC (cycle began this month)
threshold     : 2026-09-01 00:00:00 UTC
canReset      : false
DID RESET     : NO
PASS: not yet due, correctly left alone
  [restore] start_date back to 1782952243
```

## How the board was put back

Every step wrote `start_date` and restored it from a snapshot taken before the
write, via a `register_shutdown_function` so an abort still restores. The
columns `resetRecurringGoal()` touches — `start_date`, `donation_amount`,
`recurring_amount`, `last_donor_user_id`, `last_donation_id` — were all captured
and all restored. Confirmed afterwards:

```
donation_goal_id  start_date   dt                   donation_amount  recurring_amount  donation_count
5                 1782952243   2026-07-02 00:30:43  720.00           8069.00           1887
```

which matches the pre-run snapshot in every column.

The `xf_class_extension` row added for the export stays: it is the add-on's own
data and is what `_data/class_extensions.xml` now ships. Development mode was
switched on for the export and switched back off afterwards.

## When to re-run

After any XenForo or Siropu Donations upgrade. Section 1 is the check most
likely to break and the one CI is blindest to. Section 2 additionally covers the
vendor still calling `canResetRecurringGoal()` from its cron at all — if the
vendor inlines or renames that check, the override is bypassed with no error and
sections 2 and 3 both stop reflecting the override.

Note that section 2's fixture only reproduces the original race **on the 1st of
a month**: the threshold's day is always the 1st, so on any other date it sits in
the past and the row passes for the wrong reason. Sections 1 and 3 are
date-independent.
