# 0003 - Canonical order for committed class-extension records

## Status

Superseded by [0004](0004-class-extension-order-is-case-folded.md), which replaces the byte comparison in the decision below with a case-folded one. The rest still holds.

## Context

XenForo exports class extensions with `ORDER BY from_class, to_class, execute_order`, so the order it writes is decided by the collation of the columns it sorts on. That makes the exported order a property of the database an addon was exported from, not of the addon.

Our committed files had drifted from what our own exports produce. `xf_class_extension.from_class` and `to_class` are `utf8mb4_general_ci` on the production database and on the dev stack restored from it, and under that collation `C` (0x43) sorts ahead of `\` (0x5C), so `XF\Service\ProfilePostComment\NotifierService` comes before `XF\Service\ProfilePost\NotifierService`. Two committed files disagreed with that. MilpacMention's rows had been written in the opposite order and a later re-export's correction was reverted by hand to keep it out of an unrelated diff. MilpacTooltip's rows were in no sorted order at all, which no export produces.

Nothing caught either one. The consistency check compares class-extension content as an unordered set, so a reordered file still validates. The visible symptom was that a routine re-export produced a diff touching rows the change had nothing to do with, which is what trained us to revert those hunks by hand, which widened the drift further.

## Decision

The committed `_data/class_extensions.xml` rows are kept in one canonical order, defined as a byte comparison of `from_class`, then `to_class`. `tools/validate-addon.php` enforces it and fails on a file that is out of order.

No tooling rewrites what the export produces. The canonical order is the order our exports already emit, so the fix is to reconcile the committed files to it once, by re-exporting, and to stop hand-editing them afterwards.

## Considered options

A normalisation step that re-sorts rows after every export was the obvious alternative, and it is what the original bug report assumed. We rejected it. There is no evidence of two installs disagreeing: every install we have is `utf8mb4_general_ci`, and every `_data` file in the repo was authored against the dev stack. Imposing a repo order that differs from the exporter's would mean every future export needs post-processing forever, to correct a divergence that hand-editing caused rather than the database.

## Consequences

- A byte comparison is not a general model of `general_ci`. The two disagree whenever an underscore competes with a letter at the same position, so `a_b` sorts before `ab` in PHP and after it in the database. Phrase titles, option ids, and template names all have that shape, so this rule is limited to class extensions and must not be generalised to the other `_data` types. No class name in the repo contains an underscore today; one that did would need this revisited.
- `execute_order` is not part of the rule. The schema's `UNIQUE KEY (from_class, to_class)` makes the pair unique, so the exporter's third sort key is never reached as a tiebreaker. This is the same identity XenForo uses when it imports and when it names the `_output` file.
- If the database collation ever changes, for example a production migration to `utf8mb4_0900_ai_ci`, exports will flip order and the check will start failing on legitimate output. The response then is one reconciliation commit and a change to this rule, not a normaliser.
- `_output/extension_hint.php` is generated from the same rows and is not checked separately. Anything that reorders it reorders the XML too, and the check catches that first.
- The check lives with `validate-addon.php` rather than `check-data-consistency.php`, because canonical order is a property of a single `_data` file rather than agreement between the `_data` and `_output` trees.
