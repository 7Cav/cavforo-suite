# ADR-0004: PUC rows follow regiment grants, kept by the PUC sync

- **Status:** Accepted
- **Date:** 2026-11-10
- **Issues:** #346, decided in #308
- **Supersedes:** [ADR-0001](0001-bundle-citation-set.md)

## Context

ADR-0001 bundled the six PUC dates and their images in the addon and copied them onto each new milpac, because a template milpac can be edited silently. `Cav7/Citations` now holds every PUC as a **regiment grant**, which changes only through a logged correction or withdrawal. The PUC rows already on milpacs have drifted: about 1,400 sit a day off their date, some milpacs hold none, and some rows were added by hand.

## Decision

Every milpac on every roster holds exactly one **PUC row** per live regiment grant, linked to that grant, dated to it, with no details. The **PUC sync** adds a missing row, resets an edited one, and deletes duplicates, rows linked to a grant that is no longer live, and unlinked rows of a regiment award. It never adopts an unlinked row: once the strip has run, an unlinked PUC row can only have been added by hand.

It runs when a milpac is created, when a regiment grant is issued, corrected or withdrawn, and daily. Its first run is the strip command, which an operator runs after a dry run and which turns the sync on. Until then a new milpac gets no PUC rows. While it is on, the award forms refuse to add, edit or delete a PUC row.

The sync writes through entities, because a delete needs the vendor's `_postDelete` to remove the row's legacy file and `Cav7/RosterPatch` floors the date on save. The bulk writes run inside `Cav7/RosterAudit`'s `AuditPause`, and their counts go on each grant's log. A new milpac's rows stay in the audit log with the rest of that milpac.

## Considered options

- **Keep the bundled set and add a repair command.** Rejected: a new PUC would still need a release, and nothing would remove drift or hand-added rows.
- **Adopt an unlinked row that matches a grant's date.** Rejected: rows sit a day or more off their date, so adopting needs fuzzy matching. Replacing every row in one strip is simpler.
- **Raw SQL for the bulk writes.** Rejected: it skips the vendor's `_postDelete` and RosterPatch's date floor.

## Consequences

A new PUC needs no release. Issuing its regiment grant puts a row on every milpac within one job run, and withdrawing it takes them off. A row that fails to write is back by the next daily run. A database restored to before the strip brings the old rows back with the sync off, so the restore runbook reruns the strip.
