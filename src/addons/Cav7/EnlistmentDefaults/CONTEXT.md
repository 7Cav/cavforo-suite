# Cav7/EnlistmentDefaults

Pre-fills the add-milpac form with the values a new enlistment almost always
uses, and applies the standing set of unit citations and the first service
record the moment a milpac is created — so a recruiter starts from a
near-complete form and the new member starts with what every member already
carries. Companion to NF/Rosters; attaches through XenForo class extensions and
ships none of the vendor's code (same shape as
[Cav7/RosterAudit](../RosterAudit/README.md)). For **milpac**, see the
suite-wide [CONTEXT.md](../../../../CONTEXT.md).

## Language

**PUC (Presidential Unit Citation)**:
A single NF/Rosters award (`award_id` 61, "Army & Air Force Presidential Unit
Citation") that the unit has earned several times. Each time it was earned is a
separate dated grant, so a milpac carries _multiple_ PUCs — one per date.
_Avoid_: treating "PUC" as the whole "Unit Awards" award group (that group also
holds other unit citations and a separator row); PUC is specifically award 61.

**PUC set**:
The fixed list of dated PUC grants every milpac is meant to receive — currently
six dates (2003-03-18, 2004-09-01, 2009-08-10, 2010-09-18, 2011-06-02,
2021-05-16). The set grows only when the unit earns another PUC; it is bundled
with the addon, not derived at runtime (see
`docs/adr/0001-bundle-citation-set.md`).
_Avoid_: "all unit awards" (only the PUC, award 61, is auto-applied today). Also
_avoid_ reading "the set" as a guarantee of what a milpac carries — the dates are
applied one at a time and a milpac can end up holding only some of them (see
**Dropped grant**).

**Dropped grant**:
A bundled PUC date that failed to apply when the milpac was created and was
skipped, leaving that milpac permanently short of the full **PUC set**. The
remaining dates and the **Enlistment record** still apply, so the milpac looks
ordinary; the only trace is an admin error-log entry. Nothing re-applies it —
repair is granting the award by hand (see
`docs/adr/0002-error-log-is-the-only-failure-surface.md`).
_Avoid_: "failed enlistment" (the milpac is created and the member is enlisted
either way) and "pending date" (**pending** means not yet carried at the moment
the set is applied, which is the normal state of every date on a new milpac).

**Citation**:
The JPG document attached to a PUC grant, stored per award row at
`data://roster_award_citations/…/{record_id}.jpg`. Citations are _generalized_:
the same image is reused for a given PUC date across every member, so the set of
citation images is bundled with the addon rather than produced per member.
_Avoid_: per-member or personalized citations (they are unit-level documents).

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

- **"A set of PUCs" vs "the PUC"** — resolved: one award (61) granted on each of
  the six dates the unit earned it, not a set of distinct awards. The
  "Unit Awards" group's other entries (Joint Meritorious, Valorous, etc.) are
  _not_ auto-applied.
- **Create vs move** — only a _new_ `RosterUser` row (`Service\Profile\Adder`)
  is a milpac creation. Moving a member between rosters
  (`Service\Profile\Mover`) updates the existing row, so it never triggers this
  addon. Re-applying PUCs to an existing milpac is explicitly never done.
- **Applied vs carried** — resolved: the **PUC set** is what the addon tries to
  apply, not what the milpac is guaranteed to hold afterwards. Each date is
  applied independently, so a failure on one leaves a **Dropped grant** and the
  other dates still land. A milpac carrying five of six PUCs is a real state, and
  an unremarkable-looking one.
- **Prefill vs apply** — two different moments. The **Enlistment defaults** are
  the add-form's _initial state_ (the recruiter sees and edits them before
  saving); the PUC set and the Enlistment record are _applied on save_. Prefill
  is form-only; the record-follows-Join-Date rule is global — any new milpac's
  record tracks whatever Join Date it ends up with, however it was created.

## Example dialogue

> **Dev:** A member just got moved from the reserve roster to active — do they
> get the PUC set again?
>
> **Expert:** No. That's a move, not a creation — same `RosterUser` row, just a
> new `roster_id`. PUCs are applied once, when the milpac is first created, and
> never re-applied.
>
> **Dev:** And if someone who left years ago comes back?
>
> **Expert:** Coming back means a brand-new milpac, so yes — they get the full
> PUC set and the enlistment record, same as anyone else. "New" means a new
> record on this site, not their first time in the org.
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
