# Cav7/EnlistmentDefaults

Pre-fills the add-milpac form with the values a new enlistment almost always
uses, writes a new milpac's first service record, and keeps one Presidential Unit
Citation row per regiment grant on every milpac. Companion add-on to
[NF/Rosters](https://nixfifty.com/products/rosters-and-personnel-status-reports.5/):
it attaches through XenForo class extensions and ships none of the vendor's
code, so NF/Rosters can be updated independently.

Terms used below (milpac, PUC, PUC row, PUC sync, strip, regiment grant,
enlistment record, Join Date, enlistment defaults) are defined in
[GLOSSARY.md](GLOSSARY.md) and the suite's [GLOSSARY.md](../../../../GLOSSARY.md).
The decisions behind the behaviour are in [docs/adr/](docs/adr/).

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
inside the same save:

- **Its PUC rows**, when the PUC sync is on: one per live regiment grant, as
  below. With the sync off it gets none.
- **The enlistment record.** One Transfer-typed `ServiceRecord` with the
  body `Enlisted in the 7th Cavalry Regiment, Assigned Boot Camp`, dated to the
  milpac's Join Date. A recruiter who backdates the Join Date for a returning
  member gets a record on the real join date, not the moment the row was created.
  A blank or unparseable Join Date falls back to the creation date, so a milpac
  created by import or API never gets a broken record date.

The enlistment record is written on creation only. Moving a member between
rosters and editing a milpac are updates of an existing row.

## The PUC sync

Every PUC is a regiment grant in [Cav7/Citations](../Citations/README.md). The
PUC sync keeps exactly one PUC row per live regiment grant on every milpac, on
every roster: linked to the grant, dated to it, with no details. It adds a
missing row, resets an edited one, and deletes duplicates, rows linked to a
withdrawn grant, and PUC rows with no link, which can only have been added by
hand. Why: [ADR-0004](docs/adr/0004-puc-rows-follow-regiment-grants.md).

It runs when a milpac is created, when a regiment grant is issued, corrected or
withdrawn, and daily (cron entry `cav7EnlistDefPucSync`). The last two queue one
job over every milpac. Its writes stay out of
[Cav7/RosterAudit](../RosterAudit/README.md)'s log and are counted on each
grant's log instead; a new milpac's rows are audited with the rest of it.

The sync ships off. Turn it on by running the strip once the regiment grants are
issued:

```
php cmd.php cav7-enlistment-defaults:puc-strip --dry-run
php cmd.php cav7-enlistment-defaults:puc-strip
```

The dry run prints what each grant would gain, lose and have reset, and how many
unlinked rows would go, and writes nothing. The real run does it, prints the same
counts, and turns the sync on. It refuses while no regiment grant is live. A
database restored to before the strip has the old rows back and the sync off, so
run the strip again.

While the sync is on, the award forms refuse to add, edit or delete a PUC row,
or to turn another row into one. A PUC row is a row of an award Cav7/Citations
lists as taking regiment grants, or one linked to a regiment grant.

## A failure costs the defaults, never the enlistment

Each PUC row write and the record write are isolated: a failure is logged to the
XenForo error log and the milpac save proceeds, because a failure must never
block enlistment. A PUC row that failed comes back on the next daily run. Every
entry is stamped with the milpac (`relation_id`), and from a new milpac also the
member (`user_id`), and keeps the exception whole. The error log is the only
failure surface; check it as part of routine maintenance. The reasoning is in
[ADR-0002](docs/adr/0002-error-log-is-the-only-failure-surface.md).

## Configuration

Five options under the add-on's option group:

- **PUC sync** (`cav7EnlistDefPucSync`, default off). On after the strip; see
  above. Which awards take regiment grants is Cav7/Citations' setting.
- **Enlistment record type id** (`cav7EnlistDefRecordTypeId`, default the
  Transfer type): the type of the first service record.
- **System fallback user id** (`cav7EnlistDefSystemUserId`, default 0): who a
  PUC row is attributed to when no member is acting, which is always the case for
  the strip and the job.
- **Default rank id** (`cav7EnlistDefDefaultRankId`, default 23): the rank the
  add form starts on (Recruit).
- **Default position id** (`cav7EnlistDefDefaultPositionId`, default 193): the
  position the add form starts on (New Recruit). On a roster that does not list
  this position, the form is left unset rather than showing an invalid pick.

## Requirements

- XenForo 2.3+
- PHP 8.1+
- NF/Rosters 2.1+, Cav7/Citations 1.3.0+ and Cav7/RosterAudit 1.2.0+ (declared in
  `addon.json`)

## Installation

1. Copy `src/addons/Cav7/EnlistmentDefaults` into your XenForo installation at
   the same path.
2. Install the addon: `php cmd.php xf-addon:install Cav7/EnlistmentDefaults`.

Run the tests with `tools/run-tests.sh EnlistmentDefaults`. They need only `php`.
Everything that needs a live XenForo is verified by hand on a dev stack before a
release, per [CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## Out of scope

Nothing but PUC rows is applied to an existing milpac: no backfilling of
defaults or enlistment records. The historical `Assignment`-typed enlistment
records already in the data are left as they are. There is no bulk-import path;
disable the addon for a mass historical import.

## License

MIT License, see [LICENSE](LICENSE) for details.

Copyright (c) 2026 7Cav

## Provenance

Written for this suite rather than imported from another repository: its whole
history is in this repo's log, starting with a bundled PUC set and the enlistment
record, with the add-form prefill added later.
