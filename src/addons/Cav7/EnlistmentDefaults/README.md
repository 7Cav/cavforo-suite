# Cav7/EnlistmentDefaults

Pre-fills the add-milpac form with the values a new enlistment almost always
uses, and applies the standing unit citations and the first service record the
moment a milpac is created, so a recruiter starts from a near-complete form and
a new member starts with what every member already carries. Companion add-on to
[NF/Rosters](https://nixfifty.com/products/rosters-and-personnel-status-reports.5/):
it attaches through XenForo class extensions and ships none of the vendor's
code, so NF/Rosters can be updated independently (same shape as
[Cav7/RosterAudit](../RosterAudit/README.md)).

For the domain terms (milpac, PUC, PUC set, citation, enlistment record, Join
Date, enlistment defaults) see [CONTEXT.md](CONTEXT.md). For why the citation set
is bundled in the addon rather than cloned from a template milpac, see
[docs/adr/0001-bundle-citation-set.md](docs/adr/0001-bundle-citation-set.md).

## What it does

**Prefills the add-milpac form.** When a recruiter opens the add form, it starts
with the rank set to Recruit, the position set to New Recruit (only on rosters
that list it), and both Join Date and Promotion Date set to today in board time.
Every value stays editable, and nothing is forced: the prefill is the form's
starting point only, never written on save and never re-injected after a submit.
The username, bio, full name, MOS, console gamertag, and secondary positions
stay blank, so the recruiter enters the member's real details. The prefill is
form-only, so the API and bulk-import creation paths are untouched.

When a milpac is created (a new `NF\Rosters\Entity\RosterUser` row is inserted),
the addon does two more things inside the same save:

- **Grants the PUC set.** One `RosterUserAward` per bundled Presidential Unit
  Citation date (award 61), with `award_date` set to that date and the bundled
  citation JPG attached. The set is six dates today: 2003-03-18, 2004-09-01,
  2009-08-10, 2010-09-18, 2011-06-02, 2021-05-16. The dates and their citation
  images ship in the addon under `assets/puc-citations/`.
- **Writes the enlistment record.** One Transfer-typed `ServiceRecord` with the
  body `Enlisted in the 7th Cavalry Regiment, Assigned Boot Camp`, dated to the
  milpac's Join Date. A recruiter who backdates the Join Date for a returning
  member gets a record on the real join date, not the moment the row was created.
  A blank or unparseable Join Date falls back to the creation date, so a milpac
  created by import or API never gets a broken record date.

The two grant-and-record steps fire on creation only. Moving a member between
rosters and editing a milpac are both updates of an existing row, so neither
re-applies the set. A re-run on a milpac that already carries a PUC date skips
it, so the set never duplicates. The record-follows-Join-Date rule, by contrast,
applies to any new milpac however it was created.

## Configuration

Five options under the add-on's option group, so the addon survives a vendor
rebuild that renumbers ids:

- **PUC award id** (`cav7EnlistDefPucAwardId`, default 61) — the award granted
  on each bundled date.
- **Enlistment record type id** (`cav7EnlistDefRecordTypeId`, default the
  Transfer type) — the type of the first service record.
- **System fallback user id** (`cav7EnlistDefSystemUserId`, default 0) — who a
  grant is attributed to when there is no acting visitor (CLI, cron). With a
  session, the acting visitor is used.
- **Default rank id** (`cav7EnlistDefDefaultRankId`, default 23) — the rank the
  add form starts on (Recruit).
- **Default position id** (`cav7EnlistDefDefaultPositionId`, default 193) — the
  position the add form starts on (New Recruit). On a roster that does not list
  this position, the form is left unset rather than showing an invalid pick.

## Requirements

- XenForo 2.3+
- PHP 8.0+
- NF/Rosters 2.1+ installed (declared as a dependency in `addon.json`)

## Installation

1. Copy `src/addons/Cav7/EnlistmentDefaults` into your XenForo installation at
   the same path.
2. Install the addon: `php cmd.php xf-addon:install Cav7/EnlistmentDefaults`.

For release builds, generate hashes first:
`php cmd.php xf-addon:build-release Cav7/EnlistmentDefaults`.

## Design notes

**Fail-open by design.** Each grant and the record write are isolated as far as
the log seam holds: a failure is logged to the XF error log and the milpac save
proceeds, because a failure must never block enlistment. This matches the
[Cav7/RosterAudit](../RosterAudit/README.md) policy, and it leaves the error log
as the only failure surface, so an entry has to be enough on its own. Every
failure that reaches the log from applying the defaults, a dropped grant or a
failed enlistment record write, is stamped with the milpac (`relation_id`) and
the member (`user_id`) it belongs to; a dropped grant also names its PUC date,
and the exception is logged whole, with its class and stack trace, so the grant
can be traced to a member and granted by hand. (The add-form prefill fails before
there is a milpac to name, so its entries carry no stamp.) There is no config
validation, health-check cron, or notification, so a persistent failure (for
example an unreadable citation file) leaves new milpacs missing part of the set
until someone reads the error log: check it as part of routine maintenance. The
reasoning is in
[docs/adr/0002-error-log-is-the-only-failure-surface.md](docs/adr/0002-error-log-is-the-only-failure-surface.md).
That rule is the applier's: the add-form prefill drops a rejected value silently,
which [ADR-0003](docs/adr/0003-a-prefill-rejection-stays-silent.md) decided.

**What the log seam does not cover.** The isolation is only as good as the
logging it leans on. A throw out of the error log call escapes both the grant
loop and the applier, so the grants after it are never attempted, the enlistment
record is never written, and none of it reaches the log; the entity's last-resort
catch then discards its own logging failure rather than fail the save over it.
The milpac save is the part that holds unconditionally: a broken error log costs
the enlistment defaults, not the enlistment.

**Insert-only.** The hook is on the milpac entity's post-save, gated on insert.
A move (`NF\Rosters\Service\Profile\Mover`) and an edit both save an existing
row, so they never trigger. "New" means a new milpac row on this site, which also
covers a returning member.

**Idempotency.** Before granting, the addon reads the PUC dates the milpac
already carries and skips them, matching by calendar day. A re-run adds no
duplicates.

**The set is bundled.** The PUC dates and their citation images live in the
addon, not derived at runtime. Earning a new PUC means adding its date and
citation image and cutting a release. See
[docs/adr/0001-bundle-citation-set.md](docs/adr/0001-bundle-citation-set.md).

**Prefill is form-only.** The defaults are set on the milpac entity the add-form
GET render hands the view, and only on that GET render. The POST branch is left
exactly as the vendor produced it, so whatever the recruiter submits is what
saves. The prefill is also fail-open, but its two failures differ: a bad board
timezone throws and is logged, costing the whole prefill (rank and position
included), while a value the field set rejects is dropped silently — nothing is
logged, and
that one field is left unfilled while the others still fill. Do not search the
error log for a field that did not prefill; the silence is deliberate, and the
reasoning is in
[docs/adr/0003-a-prefill-rejection-stays-silent.md](docs/adr/0003-a-prefill-rejection-stays-silent.md).
Either way the form still renders. The prefill runs on every roster's add form,
not just an enlistment roster, since a non-enlistment add is the rare case a
recruiter just overrides.

## Tests

`tools/run-tests.sh EnlistmentDefaults`. The decisions are plain units with no
XenForo dependency — which dates are pending, who a grant is attributed to, how a
record is dated, and the citation-attach rollback — and each is exercised for
real. `tests/FailureLoggingTest.php` goes further and drives the entity
extension's post-save against stand-ins for the vendor entities, so the
fail-open policy and the identity stamp on a dropped grant are covered too.

What none of it covers is the vendor. Every test here compares our code against
our own expectations, and the stand-ins were written from reading NF/Rosters, not
from running it. The list below is what a stack run has to confirm.

### Re-run these on a dev stack after an NF/Rosters or XenForo upgrade

CI cannot see any of them, and each one fails silently in production if it breaks.

1. Creating a milpac grants the whole PUC set, each award carrying its citation
   image. This is the class extension on the milpac entity's post-save still
   firing on insert, and the two vendor row factories (`getNewAward()`,
   `getNewServiceRecord()`) still being there.
2. A PUC date whose citation JPG is missing from `assets/puc-citations/` leaves
   **no** award row for that date, while the other dates and the enlistment
   record still apply and the milpac still saves. This is the one the rollback
   exists for: the vendor's image service rejects a missing or unreadable source
   by **throwing** (`Service\AwardRecord\Image::validateImageForRecord()` raises
   before it reaches any branch that returns false), not by returning false, and
   the award row is already saved by then. If the vendor ever swaps the throw for
   a plain false, or moves the validation, the rollback is what stops a
   citationless row surviving — and `EnlistmentDecisions::pendingDates()` matches
   on `award_date`, so a surviving row makes that date look granted for good.
3. The error-log entry for that dropped date names the milpac and the member, and
   keeps the exception's class and stack trace.
4. A rejection the service signals by returning false rather than throwing also
   leaves no row behind, and the reason reaches the log entry. The reason arrives
   as an `\XF\Phrase` the addon renders; a vendor that returns a plain string
   instead still works, one that returns something else does not.
5. The add-form prefill still fills rank and position on a new milpac's add form,
   and the submitted values are what save.
6. A roster field the prefill writes (`joinDate`, `promoDate`) that rejects the
   value still leaves that one field blank, fills the other, renders the form, and
   logs **nothing** — the silence ADR-0003 decided on. Every rejection branch in
   `XF\CustomField\Set::set()` returns false under `ignoreInvalid` rather than
   throwing; a vendor that starts throwing instead would turn a blank field into a
   logged, wholly-unfilled prefill. Drive it by capping a field's max length below
   ten characters, opening the add form, then putting the cap back.
7. The prefill's `catch (\Throwable)` still renders the add form when an `\Error`
   is raised inside it. Nothing in CI throws through this catch, so its breadth
   rests on this check; narrowing it to `\Exception` gives an error page instead of
   the add form.

Item 2 is worth driving deliberately rather than waiting for it: move one JPG out
of `assets/puc-citations/`, create a milpac, confirm no row for that date, then
put the file back.

## Out of scope

Backfilling defaults or re-dating records on existing milpacs; scoping the form
prefill to particular rosters (it applies on every roster's add form);
prefilling through the entity's creation defaults (that would change the API and
import paths, not just the form); backfilling PUCs onto existing milpacs;
auto-applying any unit award other than the PUC (award 61); a bulk-import path
(disable the addon for a mass historical import); changing the historical
"Assignment"-typed enlistment records already in the data.

## Layout

```
src/addons/Cav7/EnlistmentDefaults/
  PucSet.php                    the bundled PUC dates and their citation paths
  EnlistmentDecisions.php       pure rules: gating, idempotency, attribution, record date
  EnlistmentFormDefaults.php    pure rule: the add-form's initial state
  EnlistmentApplier.php         orchestration + fail-open policy (the deep module)
  EnlistmentGateway.php         the entity-world seam the applier drives
  RosterUserGateway.php         production seam: vendor factories + image service
  NF/Rosters/Entity/RosterUser.php       the insert-only post-save hook
  NF/Rosters/Pub/Controller/Roster.php   the add-form prefill (GET render only)
  assets/puc-citations/         the bundled citation JPGs, one per PUC date
  _data/*.xml                   options, option group, class extensions, phrases, template mods
  tests/*.php                   standalone PHP tests (no XenForo)
```

## License

MIT License, see [LICENSE](LICENSE) for details.

Copyright (c) 2026 7Cav
