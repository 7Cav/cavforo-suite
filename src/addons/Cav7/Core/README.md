# Cav7/Core

The home for code that serves the suite as a whole rather than any one addon.

Two kinds of thing live here. The first is tooling with no single addon to belong to — a check that spans several bounded contexts has to sit somewhere, and [ADR 0006](../../../../docs/adr/0006-core-hosts-suite-wide-operational-commands.md) nominates Core. The second is shared library code extracted from the addons; issue #1 carries that plan.

## Are this suite's template modifications in force?

```
php cmd.php cav7-core:check-template-modifications
```

Eight addons in the suite patch templates they do not own, through XenForo template modifications. When one of those patches stops reaching the page — a vendor upgrade moved the markup, somebody edited a style's copy, an addon's data never imported — the addon still reports installed and active, and the board renders the unpatched page. That is how a milpac date rendered in the wrong timezone for months (#106) while every check said the addon was fine.

XenForo is not silent about this, but it is close: the admin panel shows an `ok / not_found / error` count per modification, and nothing else. No dashboard notice, no error log entry, no aggregate across the suite, and no way to ask from a script. You see it only by visiting that page, for a modification you already suspect.

The command asks the whole question at once. It exits:

- `0` — every shipped modification is in force, with the counts it checked
- `1` — one or more are not, each named on its own line
- `2` — the check could not be performed, which is not the same as finding nothing wrong

A modification is **shipped** (present in an addon's `_data`), then **installed** (the board holds a record), then **in force** (XenForo has actually applied it). An addon can report installed and active while something it ships is in neither of the later states. There are six ways that happens, and a failure line names which — its **shape** — because the remedies differ. Each line also carries its own remedy, so the command's output is the reference rather than any list here.

It reads XenForo's verdict rather than any template body, so what it reports is what the board recorded the last time each copy was compiled. It repairs nothing, mutes nothing, and ignores modifications belonging to addons outside this suite.

Which copies it looks at: the master copy always, because a find failing there means the vendor's markup moved rather than a style being edited, and it is what any style created later inherits. Beyond that, only the copies an **in-use style** — the board default plus anything a member can select — resolves to. A style made selectable later widens the check on its own, with no code change.

The vocabulary above is defined once, in [CONTEXT.md](../../../../CONTEXT.md).

The same check runs daily from `cav7CoreTemplateModCheck` and writes each failure to XenForo's error log, which raises the admin dashboard's "server errors have been logged" notice. It logs on every run; there is no state tracking to suppress a repeat, and no way to mute a known failure. The surface is eleven modifications, and a red result should mean "go fix the board".

### It has almost no CI coverage, on purpose

Everything the check does needs a live XenForo entity layer and database — the joins, the template-map resolution, the in-use-style filter. Only the read of an addon's shipped `_data` is unit-tested, and only for its refusal contract: a file that will not parse must be refused rather than read as "this addon ships nothing", because those two outcomes are exit 2 and a clean green run that checked nothing.

The rest is verified by hand against a dev stack. **CI will go green on a refactor that breaks this command.** Re-check it by hand before releasing this addon, and after any XenForo upgrade.

## The shared library

Code the addons have in common is pulled in here so each can depend on one source of truth instead of its own copy. Likely first candidates, based on what already repeats across the addons:

- The 7Cav rank and user-group taxonomy (group ids and rank slugs), currently hard-coded in `AvatarByRole`, `ApiKeyManager`, and the roster addons.
- NF/Rosters lookups (resolving a member's roster entry, rank, and gamertag), currently duplicated between `RosterSearch` and `RosterAudit`.
- The API-scope and group-gating pattern shared by `ApiKeyManager` and `UserGroupsScope`.

Extraction is tracked separately from the migration; issue #1 has the overall plan.

The XenForo floor is set to 2.2.0+ so any addon in the suite can depend on Core regardless of its own floor.

## Requires

Nothing beyond XenForo. The check reads whatever `Cav7/*` addons are installed alongside it, and is content with none.
