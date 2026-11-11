# Cav7/EnlistmentDefaults

Pre-fills the add-milpac form with the values a new enlistment almost always
uses, writes a new milpac's first service record, and keeps one PUC row per
regiment grant on every milpac. Companion to NF/Rosters; attaches through
XenForo class extensions and ships none of the vendor's code. For **milpac**,
**citation**, **citation image**, **grant**, **grant member** and **regiment
grant**, see the suite-wide [GLOSSARY.md](../../../../GLOSSARY.md).

## Language

**PUC (Presidential Unit Citation)**:
A single NF/Rosters award (`award_id` 61, "Army & Air Force Presidential Unit
Citation") that the unit has earned several times. Each time it was earned is a
separate regiment grant, so a milpac carries _multiple_ PUCs, one per grant.
_Avoid_: treating "PUC" as the whole "Unit Awards" award group (that group also
holds other unit citations and a separator row).

**PUC row**:
One milpac's award row for one PUC, linked to its regiment grant and dated to
it.
_Avoid_: grant, which is the regiment grant the row is linked to.

**PUC sync**:
The pass that keeps exactly one PUC row per live regiment grant on every milpac
on every roster: when a milpac is created, when a regiment grant is issued,
corrected or withdrawn, and daily.
_Avoid_: a bare "sync" or "resync", which belong to `Cav7/DiscordSyncPatch`.

**Strip**:
The PUC sync's first run, which an operator starts from the CLI. It replaces
every PUC row made before the sync with linked ones, and turns the sync on.

**Enlistment record**:
The single first `ServiceRecord` written when a milpac is created: type
**Transfer**, body `Enlisted in the 7th Cavalry Regiment, Assigned Boot Camp`,
dated to the member's **Join Date** (falling back to the milpac's creation date
when that field is blank). The body is a fixed, canonical line (≈5,300 existing
milpacs carry it verbatim).
_Avoid_: "enlistment" as the trigger name — the trigger is milpac _creation_
(a new `RosterUser` row), which also covers returning members; and the
historical "Assignment"-typed copies of this line, which are user-entry drift
(the correct type is Transfer). Also _avoid_ dating it to the creation moment as
a rule — creation is only the fallback; the Join Date is the source.

**Join Date**:
The `joinDate` custom field (titled "Join Date"), the date a member joined the
unit. It is the source for the **Enlistment record**'s date, so a backdated
Join Date yields a correctly-dated record.
_Avoid_: conflating it with the Enlistment record — the Join Date is a field on
the milpac; the record is a dated `ServiceRecord` that now takes its date from
it. Also _avoid_ "Enlistment date" as a synonym (this install titles the field
"Join Date").

**Enlistment defaults (the form's initial state)**:
The values the add-milpac form starts pre-filled with for a new enlistment: rank
**Recruit**, position **New Recruit** (only on rosters that list it), and **Join
Date** and **Promotion Date** set to today in board time. They are only the
form's starting point — the recruiter edits any of them before saving, and
nothing is re-injected after submit.
_Avoid_: treating these as enforced or as written on save; they are
presentational defaults. Also _avoid_ scoping them to an enlistment roster —
they prefill on every roster's add form, since a non-enlistment add is the rare
exception a recruiter just overrides.

## Flagged ambiguities

- **"A set of PUCs" vs "the PUC"**: resolved. One award (61) granted on each
  date the unit earned it, not a set of distinct awards. The "Unit Awards"
  group's other entries (Joint Meritorious, Valorous, etc.) are issued to
  members, not to the regiment.
- **Create vs move**: only a _new_ `RosterUser` row (`Service\Profile\Adder`)
  is a milpac creation, and only creation writes the enlistment record. Moving a
  member between rosters (`Service\Profile\Mover`) updates the existing row; its
  PUC rows stay, and the PUC sync keeps them like any other milpac's.
- **Prefill vs apply**: two different moments. The **Enlistment defaults** are
  the add-form's _initial state_ (the recruiter sees and edits them before
  saving); the PUC rows and the Enlistment record are _applied on save_. Prefill
  is form-only; the record-follows-Join-Date rule is global, so any new milpac's
  record tracks whatever Join Date it ends up with, however it was created.

## Example dialogue

> **Dev:** A member just got moved from the reserve roster to active. Do they
> get their PUCs again?
>
> **Expert:** They never lost them. That's a move, not a creation: same
> `RosterUser` row, new `roster_id`. The PUC sync keeps one PUC row per regiment
> grant on every milpac, whatever roster it's on.
>
> **Dev:** And if someone who left years ago comes back?
>
> **Expert:** Coming back means a brand-new milpac, so they get their PUC rows
> and the enlistment record, same as anyone else. "New" means a new record on
> this site, not their first time in the org.
>
> **Dev:** The add form defaults the rank to Recruit. What if I'm adding someone
> to Arlington Memorial Cemetery?
>
> **Expert:** It still defaults to Recruit — the form prefills the same on every
> roster. A memorial add is the rare case; you just change the rank and position
> before saving. We optimised for the daily enlistment, not the exception.
>
> **Dev:** A recruiter backdates the prefilled Join Date to when the member
> actually joined. What date does the enlistment record get?
>
> **Expert:** The Join Date they submitted — the record follows the field, not
> the moment the row was created, so a backdated enlistment gets a correctly
> dated record. Only a blank Join Date falls back to the creation date.
