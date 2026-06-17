# Cav7/EnlistmentDefaults

Applies the standing set of unit citations and the first service record to a
milpac the moment it is created, so new members start with what every member
already carries. Companion to NF/Rosters; attaches through XenForo class
extensions and ships none of the vendor's code (same shape as
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
The fixed list of dated PUC grants every milpac receives — currently six dates
(2003-03-18, 2004-09-01, 2009-08-10, 2010-09-18, 2011-06-02, 2021-05-16). The
set grows only when the unit earns another PUC; it is bundled with the addon,
not derived at runtime (see `docs/adr/0001-bundle-citation-set.md`).
_Avoid_: "all unit awards" (only the PUC, award 61, is auto-applied today).

**Citation**:
The JPG document attached to a PUC grant, stored per award row at
`data://roster_award_citations/…/{record_id}.jpg`. Citations are _generalized_:
the same image is reused for a given PUC date across every member, so the set of
citation images is bundled with the addon rather than produced per member.
_Avoid_: per-member or personalized citations (they are unit-level documents).

**Enlistment record**:
The single first `ServiceRecord` written when a milpac is created: type
**Transfer**, body `Enlisted in the 7th Cavalry Regiment, Assigned Boot Camp`,
dated to the milpac's creation. The body is a fixed, canonical line (≈5,300
existing milpacs carry it verbatim).
_Avoid_: "enlistment" as the trigger name — the trigger is milpac _creation_
(a new `RosterUser` row), which also covers returning members; and the
historical "Assignment"-typed copies of this line, which are user-entry drift
(the correct type is Transfer).

## Flagged ambiguities

- **"A set of PUCs" vs "the PUC"** — resolved: one award (61) granted on each of
  the six dates the unit earned it, not a set of distinct awards. The
  "Unit Awards" group's other entries (Joint Meritorious, Valorous, etc.) are
  _not_ auto-applied.
- **Create vs move** — only a _new_ `RosterUser` row (`Service\Profile\Adder`)
  is a milpac creation. Moving a member between rosters
  (`Service\Profile\Mover`) updates the existing row, so it never triggers this
  addon. Re-applying PUCs to an existing milpac is explicitly never done.

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
