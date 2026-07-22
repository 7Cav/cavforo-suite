# ADR-0002: The admin error log is the only failure surface; no config guard, no alerting

- **Status:** Accepted
- **Date:** 2026-07-22
- **Issues:** #62

## Context

Applying the enlistment defaults happens in `RosterUser::_postSave()`, inside the
save that creates the milpac. Two layers of `catch (\Throwable)` sit on that path.
The outer one, in the entity extension, is what guarantees a failure here never
blocks a recruiter from creating a milpac. The inner one, per PUC date in
`EnlistmentApplier::grantPucSet()`, only decides whether one failed date takes the
other five and the enlistment record down with it.

#62 read the inner catch as a hole: it would swallow the `InvalidArgumentException`
`PucSet::awardDateTimestamp()` throws on a malformed bundled date, turning a typo
into a logged, dropped grant. That specific exception is close to unreachable.
`EnlistmentDecisions::pendingDates()` only ever returns values drawn from
`PucSet::dates()`, so `citationPath()` cannot throw at all, and a malformed entry in
the constant fails `PucSetTest` twice over before it could ship.

Grilling the wider question surfaced the failures that can actually happen, and they
split by shape.

A **systematic** failure is a configured id pointing at the wrong thing: the PUC
award or the enlistment record type renumbered by a vendor rebuild, which is the
scenario those options exist for. It fails identically on every milpac.

A **sporadic** failure is a transient database error or a citation image that will
not attach. It drops one date on one milpac.

## Decision

Partial application stays. The six PUC dates are independent of each other and the
enlistment record write is already isolated in its own `try`, so one failure costs
one award rather than the whole set.

Failures are recorded in the XenForo admin error log and nowhere else. The add-on
does not validate its configured ids, does not run a health-check cron, and does not
notify anyone.

What the log records has to be actionable, which means naming the milpac the failed
grant belongs to and preserving the exception rather than flattening it to its
message.

## Considered options

- **Collapse to all-or-nothing.** A milpac with zero awards and no service record is
  conspicuous where one with five of six is not, so failing bigger would make
  failures easier to spot. Rejected: it trades a real benefit (a transient blip on
  one date costing only that date) for detectability, and the same detectability is
  better bought by making the log entry actionable.
- **Validate the configured ids when the options are saved.** Rejected: the failure
  these options guard against is the ids changing underneath a stored value that
  nobody edits. A callback that only fires when an admin touches the options page
  misses precisely that case.
- **A scheduled health check on the configured ids.** Rejected: a renumbered award id
  produces a first enlistment carrying no awards or six visibly wrong ones, so it is
  caught by looking at the milpac. Detecting on a schedule what is already obvious on
  sight is not worth the add-on's first cron entry.
- **A pre-flight check before the grant loop.** Rejected: it would only catch an id
  resolving to nothing, which is already the loudest case. An id resolving to the
  *wrong* award passes an existence check and produces a visibly wrong milpac anyway.
- **Notify a human (XenForo alert).** `Cav7/EnlistmentReminder` establishes the
  pattern (ADR-0001 there). Rejected here: a dropped grant is an operational error,
  not a task for a member, and the admin error log is the surface this install
  already uses for it.

## Consequences

A dropped grant is permanent. Nothing re-applies the PUC set: the automation fires
only on a milpac INSERT, and there is no cron, admin action, or CLI command that
re-runs it. The idempotency guard in `pendingDates()` makes a re-run safe, but no
re-run exists to be made safe. Fixing a dropped grant means adding the award by hand
in the Rosters UI, which is accepted because a sporadic drop is rare and the repair
takes a minute.

A re-apply path is therefore a real gap, deliberately left open. If it is ever wanted
it is the same shape as the `Cav7/DiscordSyncPatch` resync work and belongs in its own
issue, not bolted onto the error policy.
