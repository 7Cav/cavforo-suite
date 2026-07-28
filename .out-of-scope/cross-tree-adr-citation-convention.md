# A repo-wide convention for citing a decision record across trees

This repo does not define a citation form that marks a decision record as
suite-wide or addon-specific, and does not audit existing references against
one.

Decisions live in two kinds of tree — one suite-wide under `docs/adr/`, one per
addon under `src/addons/Cav7/<AddonId>/docs/adr/` — and both number from `0001`
independently. That split is [ADR
0002](../docs/adr/0002-per-addon-bounded-contexts.md) and stays. The proposal
here was the layer on top of it: a single documented form for citing a record
from anywhere in the repo, plus a one-time pass bringing every existing
reference in line.

## Why this is out of scope

**Paths already resolve.** Every path-form citation in the repo carries the
full slug, not just the number:

```
docs/adr/0001-log-by-authorship-not-by-permission.md
../docs/adr/0005-the-release-build-is-the-distribution-channel.md
```

Slugs are unique across both trees, so a path names exactly one document
whatever directory the reader started in. The ambiguity is confined to bare
`ADR-000N` in prose.

**The bare form already has a rule, it is just unwritten.** Around sixty bare
numbers appear outside the ADR trees, and nearly all of them follow the same
habit: inside an addon, a bare number means that addon's record. Writing the
habit down changes nothing about how those sites read.

**The audit is the expensive half and buys nothing.** Bringing sixty sites
across seven addons into a new spelling is a large diff through PHP docblocks,
READMEs and tests, with no behaviour change and real review cost. The
acceptance criterion that every existing reference must follow the new form is
what turned a one-sentence convention into a sweep.

**The evidence that motivated it is gone.** The request was raised off two
concrete instances, and both were deleted before it could be triaged.
`ModeratorLogPatch/docs/adr/0003-register-the-name-xenforo-resolves-to.md` went
in #199, and `tests/WiringTest.php`, whose failure message cited the suite-level
`0003` from inside the addon, went in #207. Nothing in the repo today is known
to have sent a reader to the wrong document.

## The counter-evidence, recorded honestly

The collision is not imaginary. `ADR-0002` currently names two different
documents depending on which file you read it in:

| Cited from | Means |
|---|---|
| `MilpacTooltip/RosterLink.php`, `EnlistmentReminder/PositionIdList.php` | suite `0002-per-addon-bounded-contexts.md` |
| `EnlistmentDefaults/tests/FailureLoggingTest.php` | its own `0002-error-log-is-the-only-failure-surface.md` |
| `DiscordSyncPatch/docs/verification/reconciliation-sweep-guards.md` | its own `0002-sweep-corrects-only-divergent-members.md` |

`DiscordSyncPatch` now carries `0001`–`0007` against the suite's `0001`–`0006`,
so every number that addon cites has a suite-level twin.

What keeps this from biting is that the prose around each citation names the
decision — "bounded context (ADR-0002)", "ADR-0007 has that decision" — so a
reader who lands in the wrong tree finds a document that plainly is not the one
being described. That is weaker than a convention would be, and it is what the
repo is choosing to rely on.

## What would reopen this

- A citation that actually sent someone to the wrong document, rather than one
  that could in principle.
- Anything that resolves citations mechanically — a link checker, a docs build —
  since a machine has none of the prose context a reader does.

Either of those makes the argument above stop holding, and the answer changes
with it.

## Prior requests

- #196 — "A bare ADR number can point at either decision tree"
