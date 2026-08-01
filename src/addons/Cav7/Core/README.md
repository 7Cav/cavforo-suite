# Cav7/Core

The home for code that serves the suite as a whole rather than any one addon: the suite-wide operational tooling that has no single addon to belong to ([ADR 0006](../../../../docs/adr/0006-core-hosts-suite-wide-operational-commands.md)), and the library code the addons share.

Today that is one command and the cron entry that runs it. No other addon in the suite declares Core as a dependency, so install it for the check.

## Are this suite's template modifications in force?

```
php cmd.php cav7-core:check-template-modifications
```

Eight addons in the suite patch templates they do not own, through XenForo template modifications. When one of those patches stops reaching the page — a vendor upgrade moved the markup, somebody edited a style's copy, an addon's data never imported — the addon still reports installed and active, and the board renders the unpatched page.

The command asks the whole question at once. It exits:

- `0` — every shipped modification is in force, with the counts it checked
- `1` — one or more are not, each named on its own line
- `2` — the check could not be performed, which is not the same as finding nothing wrong

A failure line names which of the six shapes it is, because the remedies differ, and carries its own remedy, so the command's output is the reference rather than any list here. The vocabulary — shipped, installed, in force, and the shapes — is defined once in [CONTEXT.md](../../../../CONTEXT.md).

It reads XenForo's verdict rather than any template body, so what it reports is what the board recorded the last time each copy was compiled. It repairs nothing, mutes nothing, and ignores modifications belonging to addons outside this suite.

Which copies it looks at: the master copy always, plus the copies an **in-use style** — the board default plus anything a member can select — resolves to. A style made selectable later widens the check on its own, with no code change.

The same check runs daily from `cav7CoreTemplateModCheck` and writes each failure to XenForo's error log, which raises the admin dashboard's "server errors have been logged" notice. It logs on every run; there is no state tracking to suppress a repeat, and no way to mute a known failure. A red result should mean "go fix the board".

## Requires

Nothing beyond XenForo 2.2+, a floor set low enough that any addon in the suite can depend on Core regardless of its own. The check reads whatever `Cav7/*` addons are installed alongside it, and is content with none.
