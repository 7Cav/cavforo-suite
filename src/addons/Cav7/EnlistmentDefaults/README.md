# Cav7/EnlistmentDefaults

Pre-fills the add-milpac form with the values a new enlistment almost always
uses, and applies the standing unit citations and the first service record the
moment a milpac is created, so a recruiter starts from a near-complete form and
a new member starts with what every member already carries. Companion add-on to
[NF/Rosters](https://nixfifty.com/products/rosters-and-personnel-status-reports.5/):
it attaches through XenForo class extensions and ships none of the vendor's
code, so NF/Rosters can be updated independently (same shape as
[Cav7/RosterAudit](../RosterAudit/README.md)).

Terms used below — milpac, PUC, PUC set, citation, enlistment record, Join Date,
enlistment defaults — are defined in [CONTEXT.md](CONTEXT.md). The decisions
behind the behaviour are in [docs/adr/](docs/adr/).

## The add-form prefill

When a recruiter opens the add-milpac form, it starts with the rank set to
Recruit, the position set to New Recruit (only on rosters that list it), and both
Join Date and Promotion Date set to today in board time. Every value stays
editable, and nothing is forced: the prefill is the form's starting point only,
never written on save and never re-injected after a submit. The username, bio,
full name, MOS, console gamertag, and secondary positions stay blank, so the
recruiter enters the member's real details.

It runs on every roster's add form, not just an enlistment roster, since a
non-enlistment add is the rare case a recruiter just overrides. It is form-only,
so the API and bulk-import creation paths are untouched.

A value the roster field rejects is dropped **silently**: that one field is left
unfilled, the others still fill, and nothing is logged. Do not search the error
log for a field that did not prefill — the silence is deliberate, and
[ADR-0003](docs/adr/0003-a-prefill-rejection-stays-silent.md) has the reasoning.
A bad board timezone behaves differently: it is logged, and it costs the whole
prefill, rank and position included. Either way the form still renders.

## What a new milpac gets

When a milpac is created (a new `NF\Rosters\Entity\RosterUser` row is inserted),
the addon does two things inside the same save:

- **Grants the PUC set.** One `RosterUserAward` per bundled Presidential Unit
  Citation date (award 61), with `award_date` set to that date and the bundled
  citation JPG attached. The set is six dates today: 2003-03-18, 2004-09-01,
  2009-08-10, 2010-09-18, 2011-06-02, 2021-05-16. The dates and their citation
  images ship in the addon under `assets/puc-citations/`, so earning another PUC
  means adding its date and image and cutting a release.
- **Writes the enlistment record.** One Transfer-typed `ServiceRecord` with the
  body `Enlisted in the 7th Cavalry Regiment, Assigned Boot Camp`, dated to the
  milpac's Join Date. A recruiter who backdates the Join Date for a returning
  member gets a record on the real join date, not the moment the row was created.
  A blank or unparseable Join Date falls back to the creation date, so a milpac
  created by import or API never gets a broken record date.

Both fire on creation only. Moving a member between rosters and editing a milpac
are both updates of an existing row, so neither re-applies the set, and a re-run
on a milpac that already carries a PUC date skips it, so the set never
duplicates. The record-follows-Join-Date rule, by contrast, applies to any new
milpac however it was created.

## A failure costs the defaults, never the enlistment

Each grant and the record write are isolated: a failure is logged to the XenForo
error log and the milpac save proceeds, because a failure must never block
enlistment. So a new milpac can end up carrying five of the six PUCs, or none of
them, and look entirely ordinary otherwise. Nothing re-applies a dropped grant;
the repair is granting the award by hand.

The error log is the only failure surface — there is no config validation,
health-check cron, or notification, so a persistent failure (an unreadable
citation file, say) leaves new milpacs missing part of the set until someone
reads it. Check it as part of routine maintenance. Every entry from applying the
defaults is stamped with the milpac (`relation_id`) and the member (`user_id`) it
belongs to, a dropped grant also names its PUC date, and the exception is logged
whole, with its class and stack trace. The reasoning is in
[ADR-0002](docs/adr/0002-error-log-is-the-only-failure-surface.md).

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

Run the tests with `tools/run-tests.sh EnlistmentDefaults`. They need only `php`.
Everything that needs a live XenForo is verified by hand on a dev stack before a
release, per [CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## Out of scope

Nothing is ever applied to an existing milpac: no backfilling defaults or PUCs,
and no re-dating of records. The historical `Assignment`-typed enlistment records
already in the data are left as they are. No unit award other than the PUC
(award 61) is auto-applied, and there is no bulk-import path — disable the addon
for a mass historical import.

## License

MIT License, see [LICENSE](LICENSE) for details.

Copyright (c) 2026 7Cav

## Provenance

Written for this suite rather than imported from another repository: its whole
history is in this repo's log, starting with the PUC set and the enlistment
record, with the add-form prefill added later.
