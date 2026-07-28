# ADR-0008: The reconciliation sweep ships disabled

- **Status:** Accepted
- **Date:** 2026-07-28
- **Issues:** [#237](https://github.com/7Cav/cavforo-suite/issues/237)

## Context

The reconciliation sweep (ADR-0002) arrived with its cron entry shipping
`active="1"`, which means installing the addon starts a schedule. Everything else
this addon does happens in response to something a member or an admin did: a group
change fires the sync, a press of the resync button queues one run for the member
who pressed it. The sweep is the first thing here that acts on the guild on its own,
on a clock, without anyone asking.

It is also the part with the widest blast radius. It reads the whole guild, queues
corrections for members it finds divergent, and strips managed roles from unlinked
holders. All of that is what it is for, and the pass recorded in
[docs/verification/reconciliation-sweep-guards.md](../verification/reconciliation-sweep-guards.md)
says it does it correctly. But "correct" and "wanted the moment the addon is
installed, without being asked" are different claims, and only the second one is the
install's to make.

## Decision

Ship the cron entry `active="0"`. A fresh install lands it inactive and nothing
sweeps until an admin turns it on, which is one toggle on the XenForo cron admin
page.

This is a default, not a switch the addon holds. XenForo lists `active` in
`XF\AddOn\DataType\CronEntry::getMaintainedAttributes()`, and
`AbstractDataType::importMappedAttributes()` skips a maintained attribute whenever
the row already exists. The shipped value is therefore read on first install and
never again: an admin who enables the sweep keeps it enabled through every later
upgrade, which re-imports the schedule and leaves the flag alone.

That mechanism is what makes shipping `0` safe. Without it, a shipped `0` would mean
every upgrade quietly switching off a sweep the board had deliberately turned on.
Both halves — the fresh install landing inactive, and an enabled entry surviving a
re-import — are checked on a dev stack and recorded in
[the verification file](../verification/reconciliation-sweep-guards.md#the-shipped-default-the-entry-installs-disabled).

## Consequences

- A board that installs this addon and does nothing else gets the sync fix and the
  resync button, and no scheduled activity. The divergence the sweep exists to
  correct goes on uncorrected until someone enables it. That is the cost, and it is
  the same cost the board already had before the sweep existed.
- The addon still ships no options. Whether the sweep runs, and how often, are both
  the cron entry's, on a page XenForo already provides — so this decision adds a
  knob without adding a setting.
- The interval stays every quarter-hour once enabled. This ADR is about the default
  for *whether*, not a change to *how often*.
- Nothing in the addon writes the flag. There is no `Setup.php` here, and no install
  or upgrade step that could re-enable the entry behind an admin's decision.
- Reverting this decision is a one-character change to `_data/cron.xml` and a
  re-derived `_output`, and it reaches only boards installing for the first time —
  by the maintained-attribute rule above, boards that already hold the row keep
  whatever they set.
