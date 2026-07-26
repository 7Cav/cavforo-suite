# 0006 - Cav7/Core hosts suite-wide operational commands

## Status

Accepted

## Context

Some checks are about the suite rather than about any one addon. The first is
the template modification check (#171): eight addons ship template
modifications, and the question "are this suite's patches actually in force on
this board" is only answerable by looking at all of them at once.

ADR 0002 makes each addon its own bounded context, developed, versioned and
released independently. A command living in one addon that reads seven others'
data contradicts that directly.

`Cav7/Core` exists for what cuts across addons, but has shipped no code so far.
Its README scopes it to shared *library* code extracted from the addons — the
rank taxonomy, roster lookups, the API-scope pattern — and ADR 0001 defers that
extraction until every addon is in the repo. Core has also never been released:
there is no `Core-v*` tag, and prod installs only released zips, so it is not on
prod today.

Three placements were considered.

**An existing addon.** Costs nothing to ship, since the host addon is already on
prod. But it puts one context in charge of seven others, which is the thing ADR
0002 was written to prevent, and it makes the choice of host arbitrary.

**One command per addon, each checking only its own modifications.** Respects
the boundaries exactly. But it gives the operator eight commands instead of one,
duplicates the reconciliation eight times, and no single run can report the
suite-level picture. Sharing the logic to avoid the duplication puts it in Core
anyway.

**Core.** Matches ADR 0002's rule for cross-cutting things. Costs Core's first
release and an install on prod, and makes its debut a diagnostic command rather
than the deduplication it was created for.

## Decision

Suite-wide operational commands live in `Cav7/Core`. The template modification
check is the first, and Core is released and installed on prod to carry it.

This widens Core's scope from what its README describes: Core is the home for
code that serves the suite as a whole, whether that is shared library code
extracted from the addons or tooling that has no single addon to belong to. The
extraction described in ADR 0001 is unaffected and still deferred.

## Consequences

- Core becomes a real addon with a release cycle. It gains a `Cli/Command`
  directory, and a `_data/cron.xml` for the scheduled half of the check.
- Prod's addon list grows by one. Every board that wants the check needs Core
  installed, which is a new prerequisite for the suite's diagnostics.
- Core's XenForo floor of 2.2.0+ already lets any addon in the suite depend on
  it, so nothing about the existing addons has to change.
- The bounded contexts hold: no addon reaches into another, and the one place
  that legitimately sees all of them is the one ADR 0002 nominated.
- A future suite-wide check has an obvious home and needs no new decision.
- If Core later carries both extracted library code and operational tooling, the
  two may want separating. That is a cheaper problem than the alternatives here,
  and can be taken up when there is more than one of each.
