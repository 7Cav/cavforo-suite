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

> **The Discord-side half was run on 2026-07-27 and is recorded in
> [its own section below](#discord-side-pass-a-test-guild-and-a-fenced-live-guild).**
> Everything this first pass lists as unreachable has since been reached. The section
> below also corrects one number here: see [Scale observed](#scale-observed).

> **The sweep ships disabled**, decided after this pass ran. What that does on install
> and on upgrade was checked separately on 2026-07-28 and is recorded in
> [The shipped default](#the-shipped-default-the-entry-installs-disabled). Every check
> in the table below was run with the entry fired deliberately, so none of them depended
> on the flag.

## What this pass cannot answer

Everything Discord-side. The stack's bot token is deliberately blank, so the
integration returns before any API call. The bulk member fetch, Discord-side
divergence detection, and the unlinked-holder strip are **not** exercised here and a
green pass says nothing about them. They are confirmed against the real guild before
release, and that list is in the add-on README.

There is a second reason beyond the blank token, found while doing the Discord-side
pass: the stack's `fpm` container sits on a Docker network marked `internal: true`, so
it has no route off the machine at all. Filling in a real token is not sufficient;
egress has to be granted deliberately and taken away again.

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

> **This 10 is the forum-side half only.** It was the whole of what this pass could
> see, because the Discord side was unreachable. Against the live guild the sweep
> selects **131**, of which these 10 are a part; the remainder is Discord-side
> divergence, which nothing before the pass below had ever measured. Do not quote the
> 10 as the sweep's selection size.

No row on this board carries an error phrase, and 463 connected accounts have no sync
record at all; the scan leaves those alone, since they were never synced on this guild.

## The shipped default: the entry installs disabled

Run on 2026-07-28 for [#237](https://github.com/7Cav/cavforo-suite/issues/237), on the
same stack, against the build that ships `active="0"` in `_data/cron.xml`. Dev mode is
off there, so `xf:addon-install` reads the `_data` tree — the path a board takes
installing the release zip, not the `_output` tree a dev-mode board would import. The
decision this checks is [ADR-0008](../adr/0008-the-sweep-ships-disabled.md), which is
also where the mechanism behind check 2 is written down.

| # | Check | Result |
|---|---|---|
| 1 | After an uninstall and a fresh install, `xf_cron_entry.active` for `cav7DSPReconcile` is `0` | pass |
| 2 | With that row set back to `1`, re-importing the add-on's data leaves it `1` | pass |
| 3 | The stack is left holding the entry inactive, with the add-on's other data reinstalled | pass |

Check 2 is the one worth having. It is what separates a shipped default from a switch
the add-on holds: without it, shipping `0` would mean every upgrade quietly switching
off a sweep the board had deliberately turned on, and check 1 alone cannot tell those
two apart.

Check 1 also left `next_run` at `2147483647` rather than at a quarter-hour boundary,
which is XenForo's way of recording that an entry has no next run. Worth naming: an
inactive entry is not one waiting quietly on a schedule.

**How the board was put back.** Not to its pre-pass state, and the difference is the
point. The uninstall in check 1 deleted the cron row the board was holding — the
previous build's, at `active=1` — so there is no original row to restore; the install
created a new one from the shipped data. What the reinstall did put back is everything
else the add-on owns: its three class extensions, nine phrases and template
modification, all imported again by the same install. The pass ends with the entry at
`active=0`, which is what this build ships, and check 2's temporary `1` undone. That
last step was made through the entity layer rather than by raw SQL, so `next_run`
carries the `2147483647` sentinel a real install produces; a raw `UPDATE` sets the flag
but leaves a stale past `next_run` behind, which reads as an entry that is merely
overdue. Nothing outside this add-on's own rows was touched, so there is nothing else
to unwind.

## Discord-side pass: a test guild and a fenced live guild

Run on 2026-07-27 against the same stack, for
[#232](https://github.com/7Cav/cavforo-suite/pull/232). This is the pass that clears
the six release blockers in the README's "Before a release, against the real guild"
list.

### Method: two guilds, and a bot that cannot write

The dangerous path is `stripUnlinkedHolder()`, which replaces a member's role set for
anyone the forum believes is unlinked. Its blast radius is whatever the link map says,
and the stack's link map is a mirror of unknown age. So the pass never let mirror data
and write capability meet on the live guild:

- A **second bot application** holds the stack's credentials. The token is one global
  option on the `nfDiscord` provider while the guild is per server row, so a stack can
  be pointed anywhere the bot has been invited — and this bot was invited only to a
  throwaway guild. That is a structural boundary, not a procedural one.
- The **destructive checks ran in that throwaway guild**, at full write privilege,
  against real Discord: real role hierarchy, real managed-role semantics, real
  refusals, with a blast radius of the author's own alt accounts.
- The **live guild was read with the same bot holding no `Manage Roles`** and a role at
  position 1 of 217. Every `PATCH` it issued came back `403`, which is exactly what
  makes the refusal path observable without moving a role.

Egress was granted with `docker network connect bridge` for each run and removed
after, so the compose file's documented `internal: true` backstop was borrowed against
rather than edited.

### What was checked, and what happened

| # | Check | Where | Result |
|---|---|---|---|
| 1 | The member fetch pages the whole guild and terminates, reading a count that matches the guild's own | live | pass — 8,568 read, 8,568 reported, no partial-walk row |
| 2 | A managed role hand-added to a member whose groups do not grant it is taken back off within one cycle, and their self-assigned roles survive | test | pass |
| 3 | A member whose groups changed while their sync was failing is brought into line within one cycle | test | pass, record repaired itself |
| 4 | An unlinked holder loses their managed roles and keeps every other role | test | pass, two holders |
| 5 | The strip succeeds for a member holding a role Discord manages | test | pass, `HTTP 200` |
| 5 | A deliberately bad set is reported as refused rather than as a strip | live | pass — 0 stripped, 80 refused |
| 6 | Corrections the sweep makes appear in the corrected member's change log | test | pass |

### Discord refuses a set that omits a managed role

Probed directly before check 5, because the answer decides whether the fail-closed
guard in `preservedRoleIds()` is load-bearing or decorative. A `PATCH` omitting a role
Discord manages is refused **whole**: `403`, `{"message":"Missing Permissions","code":
50013}`, member's roles unchanged. The same set with the managed role kept returns
`200`.

So the guard is load-bearing. A failed guild-roles read yields an empty preserved list,
every booster then receives a set missing their booster role, Discord refuses each one
entire, `Api::request()` turns that into `false`, and the strip is lost with nothing
recorded. That is the failure the guard exists to prevent, now measured rather than
argued.

The subject was a bot's own integration role, not a Nitro booster: both carry
`managed`, which is the property `preservedRoleIds()` keys on. No Nitro subscription is
needed to answer this.

Note `50013` is also what a genuine missing-permission failure returns, so a refusal
count says *that* Discord refused, never *why*.

### Scale observed on the live guild

Measured with the sweep's two write leaves — `stripUnlinkedHolder()` and
`queueCorrections()` — overridden to record rather than act, leaving the walk, the
roles read and every decision as production code. The fenced run afterwards agreed with
it exactly on both numbers, which is what makes the dry-run form worth trusting.

| | |
|---|---|
| Guild members | 8,568 |
| Connected accounts (mirror) | 3,577 |
| Members the sweep would queue for correction | **131** |
| Unlinked holders it would strip | **80** |
| …of whom would be left holding nothing | **29** |

The roles removed are mostly `Forum Registered` (73), `Active Members` (55), `Rank -
RCT` and `Recruits` (33 each), with a long tail of unit, staff and rank roles including
one `Rank - GEN` and one `Rank - COL`. Server nicknames on the affected accounts —
`MAJ.McCloud.A RET` among them — and the 38 of 80 carrying no nickname at all both
point at accumulated drift from departed members rather than a detection fault.

**That list was computed from mirror link data and must not be acted on as-is.** The
mirror is a snapshot of unknown age; anyone who linked after it was taken reads as
unlinked. The same dry run has to be repeated against production before any strip, and
the 80 read as a list, before the first unfenced run rather than after it.

### What this pass still cannot answer

A strip that *succeeds* on the live guild. The fence guaranteed every live `PATCH` was
refused, so this code has still never written to the real guild. The mechanism is
proven in the test guild against real Discord, and no further testing closes the gap —
the first successful live strip is the production run itself.

Rate limiting ([#233](https://github.com/7Cav/cavforo-suite/issues/233)) is likewise
untouched. The walk took 10.4s over 9 pages and 80 refused patches took 28.5s without a
`429`, so it does not trip at this scale; nothing was learned about what happens when
it does.

> **What a `429` does was settled on 2026-07-28 without waiting for one**, in
> [the pass below](#what-a-429-actually-does-through-the-vendors-api).

## What a `429` actually does through the vendor's Api

Run against `~/srv/xenforo-dev` (XenForo 2.3.11, NF/Discord 2.12.0) on 2026-07-28, for
[#233](https://github.com/7Cav/cavforo-suite/issues/233).

#233 was filed with two candidate answers and no way to choose between them, on the
assumption that only a real throttled guild could decide it. It could not: a `429` is a
response, and a response can be handed to the vendor's own code without Discord being
involved at all.

### Method: a real XenForo, the real `Api`, a mocked transport

The point is to leave everything that decides the outcome in place. So this drives
`NF\Discord\Api::get()` — not a copy of it — on a booted `XF\Cli\App`, and swaps only
the transport underneath:

1. Boot `XF\Cli\App` against the stack.
2. Replace the container's `http` entry with a subclass of `XF\SubContainer\Http`
   whose `createClient()` calls the **real** `applyDefaultClientOptions()`, so the
   Guzzle client is built from the identical option array production builds, then adds
   a `GuzzleHttp\Handler\MockHandler` at the bottom of the stack. Every middleware
   above it — `http_errors` above all — still runs.
3. Serve a canned response and call `Api::factory('123456789', false)->get(...)`.
4. Print what `get()` returned and what `getRetryAfter()` reports.

Nothing leaves the machine, which is just as well: the `fpm` container has no route
off it. `MockHandler` is what makes a `429` available on demand rather than waited for.

### What was checked, and what happened

| Canned response | `get()` returned | `getRetryAfter()` |
|---|---|---|
| `429` + Discord's rate-limit body and headers | `false` (bool) | `1785215358` |
| `403 Missing Access` | `false` (bool) | `NULL` |
| `200` + one member record | `array` (decoded) | `NULL` |

Then the same `Api` object across a `429`, a `403` and a `200` in sequence:
`1785215358`, then `NULL`, then `NULL`.

### What it settles

**A `429` arrives as a `ClientException`, and the vendor turns it into `false`.**
Guzzle 7 throws on `4xx`; `request()` catches it, calls `assertNotRateLimited()` (which
records the retry-after and does not throw, because `throwOnErrors` is false), and
returns `false`. The vendor's `'defaults' => ['exceptions' => false]` is a Guzzle 5
spelling and Guzzle 7 ignores it — inferred from the config spelling when #233 was
filed, and now observed.

**The second candidate shape cannot happen.** #233's worse case had the `429` body
decoded and returned as an array, which the sweep would have iterated as a page of
members and read as a short page — silent truncation, a run reporting a complete guild
having read part of it. `get()` returned a bool, never an array, so nothing reaches
`json_decode`. The two acceptance criteria written against that shape were dropped
rather than guarded, since a test could only have stubbed a vendor behaviour the vendor
does not have.

Nothing else on the board can reintroduce it: `xf_code_event_listener` carries no
`http_client_options` or `http_client_config` listener that could disable
`http_errors`, and `xf_class_extension` carries no extension of `NF\Discord\Api` that
could set `throwOnErrors`.

**The retry-after is the signal, and it is honest about a refusal.** A `403` clears it,
because a real `403` also arrives as a `ClientException` and so also reaches
`assertNotRateLimited()`. That makes `request()`'s early return for `{400, 401, 403}`
unreachable in practice — only its `304` arm is live.

**It is only ever a `429`.** `isRateLimited()`'s header-derived branches never fire:
each tests `count($headers) > 1` on a single-valued header, and then takes
`min($positive, 0)`. So the status code is the whole of it, there is no pre-emptive
"nearly out of budget" signal, and `getRetryAfter()` means "that response was a `429`"
and nothing more. Note it returns an absolute timestamp rather than a delay, despite
the name.

### What it does not settle

Whether Discord always delivers throttling as an HTTP `429`. This proves what XenForo,
Guzzle and the vendor do with a `429` response object; an edge-level ban could arrive
as a `403` or a dropped connection and would read here as a refusal. Nothing in this
method reaches that, and a real throttled guild would be needed to.

It also surfaced one thing it could not fix, and the sequence above is what shows it.
`retryAfter` is cleared at the top of `assertNotRateLimited()`, so only the calls that
reach it clear the previous one. Reading `request()`, **three** returns come earlier:
the `ServerException | ConnectException` branch, the `204` arm, and the
`{304, 400, 401, 403}` arm.

Only the first is reachable by this add-on's calls — Guzzle turns a real `4xx` into an
exception, so that arm is dead as noted above, and neither endpoint the sweep uses
answers `204` or `304`. But it means a connect failure straight after a throttled call
still reports the previous call's value. See
[#248](https://github.com/7Cav/cavforo-suite/issues/248), which carries the same
inventory — a fix designed off "one path" would be designed off a wrong reading.

## What role hierarchy actually blocks

Run against the throwaway guild on 2026-07-28, for
[#242](https://github.com/7Cav/cavforo-suite/issues/242).

#242 reported that the sweep retries forever against "someone who has a role above the
bot". The fix designed for it rests entirely on one sentence read out of Discord's
permissions page — *"A bot can grant roles to other users that are of a lower position
than its own highest role"* — taken to mean the constraint is on the roles **moved**,
not on the member holding them. If that reading were wrong the fix would be wrong in the
worst available way: those roles would be excluded, the retries and the log would stop,
and the members would go on uncorrected with every run reporting clean. So it was
measured before any code was written.

### Method: real Discord, no XenForo

The premise is about Discord alone, so this needs neither the stack nor the vendor —
four raw HTTPS calls with the throwaway bot's token. The bot sat at position 11 of 13.
`M1` held `Cutie` at position 12, above it. The in-reach role was **created by the bot**
rather than borrowed: a role a bot creates lands below its own highest, so the fixture
needs no drag from a human. An explicit `User-Agent` is set throughout, because
Discord answers urllib's default with a bare `403` that would read here as a hierarchy
refusal and fake a pass on every probe at once.

### What was checked, and what happened

| | Call | Result |
|---|---|---|
| P0 | `M1` → `[Cutie, scratch]` — keeps the out-of-reach role, **adds** an in-reach one | `200` |
| P1 | `M1` → `[Cutie]` — keeps it, **drops** the in-reach one | `200` |
| P2 | `M1` → `[scratch]` — drops the out-of-reach role | `403`, `code 50013` |
| P3 | `M2` → `[Cutie]` — adds an out-of-reach role to someone else | `403`, `code 50013` |

`M1` was re-read afterwards and matched what it started with, `M2` was untouched, and
the scratch role was deleted (`204`).

### What it settles

**Hierarchy constrains the roles moved, never the member holding them.** `M1`'s highest
role was above the bot's throughout, and their roles were still rewritten twice — once
adding, once dropping — because the out-of-reach role stayed in the set. Discord's own
docs list the target-hierarchy rule for *kick, ban and edit nickname* only, and role
assignment is not among them. So there is no such thing as a member this add-on cannot
reach; there are only roles.

That is what makes the exclusion a real partial correction rather than a way of giving
up: keep the immovable role, and everything else in the same call still moves.

**A refusal still says nothing about its cause.** P2 and P3 return the same `50013` the
omitted-managed-role refusal returns, so a refusal count cannot distinguish hierarchy
from a missing permission — which is why the reach test is made **before** the call
rather than inferred from its failure.

### What it does not settle

Roles at exactly the bot's own position. Discord breaks that tie *"by id"* without
stating the direction, so no probe here can generalise, and `RoleReach` reads a tie as
out of reach instead: that error leaves a role unenforced and says so, where the
opposite one sends a write Discord refuses whole.

Nor does it say anything about the add-on's own wiring — only about what Discord does.
The wiring is the dev-stack list in the README, items 12 and 7.

## Re-running it

The script is not committed — it mutates a board and its value is in the recorded
result, not in re-running it unchanged. What it does is the numbered list in
[What was checked, and what happened](#what-was-checked-and-what-happened), in order,
with the restore proven at the end. The setup it needs is the one the README already
describes for the resync button: credentials filled in, and a server row that is both
active and carries a guild id.

The hierarchy pass above is worth re-running for the same reason and is nearly free: it
writes only to a throwaway guild, restores what it touched, and needs no stack at all.
`docs/agents` has no copy of the script for the same reason the others are not
committed — the four calls in its table are the whole of it.

The `429` pass is the exception and is worth re-running, because it is the one check
here that reads vendor behaviour rather than ours — and vendor behaviour is exactly
what moves under an upgrade without anything in this repo noticing. It writes nothing,
needs no credentials and touches no guild: the four numbered steps in its method are
the whole of it, and the table is what to compare against. Re-run it after a XenForo,
Guzzle or NF/Discord upgrade. A `get()` that starts returning an array where the table
says `false` is the silent-truncation shape arriving after all.

[The shipped-default pass](#the-shipped-default-the-entry-installs-disabled) is worth
re-running for the same reason, and is cheap: it needs no credentials, no guild and no
script, and it touches nothing but this add-on's own rows. On a stack with dev mode
**off**, so the install reads `_data`:

```
php cmd.php xf:addon-uninstall Cav7/DiscordSyncPatch
php cmd.php xf:addon-install Cav7/DiscordSyncPatch
# check 1: read active for cav7DSPReconcile — expect 0, next_run 2147483647
# set it to 1
php cmd.php xf:addon-rebuild Cav7/DiscordSyncPatch
# check 2: read it again — expect 1
# then set it back to 0 through the entity layer, not by UPDATE, so next_run
# returns to the sentinel; check 3 is re-reading both columns
```

Re-run it after a XenForo upgrade. What it depends on is XenForo's own
`getMaintainedAttributes()` contract, and check 2 turning red would mean upgrades had
started overwriting a flag admins own — the failure mode the default is only safe
without.
