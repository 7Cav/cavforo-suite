# Verification: do the reconciliation sweep's guards hold on a real board?

A recorded manual pass, standing in for CI coverage this cannot have. The sweep's
decisions are pure units that CI runs; everything around them — the credentials
guard, the server-map filter, the forum-side scan, the pending guard, and the veto
over the vendor's cron — needs a XenForo, a database and the vendor add-on, none of
which CI has.

The vocabulary below — _divergence_, _reconciliation sweep_, _stale sync record_,
_unlinked holder_ — is the add-on's own [`CONTEXT.md`](../../CONTEXT.md) glossary's.
The design is [ADR-0002](../adr/0002-sweep-corrects-only-divergent-members.md),
[ADR-0003](../adr/0003-single-reconciliation-owner.md) and
[ADR-0004](../adr/0004-backstop-disconnect-cleanup-in-the-sweep.md).

Run against `~/srv/xenforo-dev` (XenForo 2.3.11, NF/Discord 2.12.0) on 2026-07-27,
for [#157](https://github.com/7Cav/cavforo-suite/issues/157), against a mirror of the
live board: 3,175 sync log rows, 3,577 nfDiscord connected accounts, one guild.

## What this pass cannot answer

Everything Discord-side. The stack's bot token is deliberately blank, so the
integration returns before any API call. The bulk member fetch, Discord-side
divergence detection, and the unlinked-holder strip are **not** exercised here and a
green pass says nothing about them. They are confirmed against the real guild before
release, and that list is in the add-on README.

The credentials the pass sets are dummy values, which is what makes the forum-side
half reachable at all: with a token present the sweep gets past its first guard, and
the Discord calls beyond it fail — which is itself one of the paths worth seeing.

Whether a refused strip is reported as a strip cannot be reached here either, and not
only because the token is blank. With a dummy token every Discord call fails, and the
guild-roles read fails first: the sweep logs that it could not read them and returns
before the member walk, so the strip is never called at all. That the sweep now reads
`patchGuildMemberRoles`'s result rather than discarding it is argued from the vendor's
source — `Api::request()` returns `false` on every failure path — and confirmed on the
live guild alongside the booster check, not here.

## Method

The sweep is driven headlessly through `XF\Cli\App` with `start(true)`, then observed
through the rows it writes to `xf_nf_discord_queue`. Assertions are on those rows and
their contents, never on log text.

Every mutation goes through raw SQL and is undone by raw SQL. The entity layer cannot
be used to restore the connected-account provider: this board's `discord_server_id` is
blank, which the entity requires, so a save of the pristine options fails validation.
Getting that wrong once left the stack holding dummy credentials, so the pass now ends
by re-reading each thing it touched and asserting the original value is back.

## What was checked, and what happened

| # | Check | Result |
|---|---|---|
| 1 | With credentials blank — the stack's default — the sweep enqueues nothing | pass |
| 2 | With credentials set but no server row carrying a guild id, it enqueues nothing | pass |
| 3 | A member whose recorded group set is edited to disagree with their groups is corrected | pass, exactly one row |
| 4 | That correction is queued against the guild that has an id, with a guild-less row present too | pass |
| 5 | The queued message does not carry `skipLoggingChanges` | pass |
| 6 | A second run does not stack a second correction while the first is pending | pass |
| 7 | A member whose groups agree, active and error-free, is not corrected | pass |
| 8 | The vendor's cron queues nothing when vetoed, with its option forced to read true | pass, 0 rows |
| 9 | The same call queues rows when made past the override | pass, 100 rows |
| 10 | A member whose sync log carries an error phrase is selected, and is left alone again once it is cleared | pass |
| 11 | The cron entry appears on the cron admin page, named, and firing it the way the Run button does runs the sweep | pass, after a fix |
| 12 | Every value the pass changed is back as it was afterwards | pass |

Check 9 is what makes check 8 mean anything. `nfDiscordEnableReverseSync` is defined
nowhere in the vendor's data, so it reads false and the vendor's cron returns early on
its own; without forcing the option true and showing the unvetoed call queueing 100
rows, a veto that had stopped working would have read as a pass.

Check 8 fires through `XF::extendClass(...)`, which is what the scheduled path does.
It does **not** cover the control panel's "Run" button, which calls the raw class —
see the correction note in ADR-0003.

Check 10 had to manufacture its own subject. No row on this board carries an error
phrase, so the pass put one on a member the scan was leaving alone, showed the scan
then selected them, cleared it, and showed the scan left them alone again. Without
that last step the check would pass just as well against a scan that selected
everybody.

Check 11 found a real defect rather than confirming one. The cron entry was
registered and active but shipped no `cron_entry.cav7DSPReconcile` phrase, so the
admin page listed it under its raw phrase key while every sibling add-on's entry has
a name. The phrase was added, the two data trees re-derived, and the check re-run
against the rebuilt add-on. The "Run" button's call shape — `call_user_func` on the
raw class — is what the pass fired, since that is the button this check is about;
the scheduled path is check 8's business.

Check 11 also re-imported the add-on's data on the stack, which is an install action
rather than a board mutation, so it is not in the restore above and the new phrase is
still there.

## Scale observed

With nothing made stale, the sweep selected **10** members of 3,175 and left the rest
alone. Those 10 are the members holding a connected account alongside a sync record
the vendor left inactive — the population a reconciler exists to retry, and the one
NF/Discord's own cron excludes by filtering on `sync_log.active = 1`.

No row on this board carries an error phrase, and 463 connected accounts have no sync
record at all; the scan leaves those alone, since they were never synced on this guild.

## Re-running it

The script is not committed — it mutates a board and its value is in the recorded
result, not in re-running it unchanged. What it does is the numbered list above, in
order, with the restore proven at the end. The setup it needs is the one the README
already describes for the resync button: credentials filled in, and a server row that
is both active and carries a guild id.
