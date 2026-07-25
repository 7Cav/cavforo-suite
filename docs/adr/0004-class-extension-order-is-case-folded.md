# 0004 - Canonical class-extension order is case-folded, not a byte comparison

## Status

Accepted. Supersedes [0003](0003-canonical-class-extension-order.md) on the comparison rule only; everything else that ADR decided stands.

## Context

[ADR 0003](0003-canonical-class-extension-order.md) defined the canonical order of `_data/class_extensions.xml` rows as a byte comparison of `from_class`, then `to_class`, on the grounds that this is the order our exports already emit. It also stated that `tools/validate-addon.php` enforced it. Neither was accurate: the script had no such check, and a byte comparison is not the order the exporter produces.

XenForo orders these rows in SQL — `ORDER BY from_class, to_class, execute_order` — over `utf8mb4_general_ci` columns, so the canonical order is that collation's. `general_ci` is case-insensitive, and that is where it parts company with a byte comparison. `XenAddons\` folds to `XENADDONS\` and sorts ahead of `XF\` (`E` 0x45 < `F` 0x46), while raw bytes put `XF\` first (`F` 0x46 < `e` 0x65). The same goes for `DBTech\eCommerce` against `DBTech\Shop`, and for any `s9e\` or `xenMade\` namespace.

This is not hypothetical. Measured against all 587 `xf_class_extension` rows on the dev stack, a byte comparison reproduces the database for 38 of 43 add-ons; `strcmp(strtoupper($a), strtoupper($b))` reproduces 43 of 43. The five it fails on are vendor add-ons installed today.

No Cav7 add-on currently extends such a namespace — every one of them extends only `XF`, `NF` or `XFES`, all uppercase — so the byte rule passed on our data by luck. It would have rejected legitimate, unmodified export output the first time an add-on extended `XenAddons\` or `XenConcept\` alongside `XF\`.

The separator case that motivated the original issue is not a divergence: both rules agree that `XF\Service\ProfilePostComment\…` precedes `XF\Service\ProfilePost\…`, because `C` (0x43) beats the `\` separator (0x5C).

## Decision

Canonical order is a case-folded comparison of `from_class`, then `to_class` — `strcmp(strtoupper($a), strtoupper($b))` in PHP. `tools/validate-addon.php` enforces it, names the add-on and the two rows that are out of order relative to each other, and rewrites nothing: a failing file is reconciled by re-exporting it.

## Consequences

- `strtoupper` is the fold because it is ASCII-only and locale-independent as of PHP 8.2, and every class name in the repo is ASCII. A non-ASCII class name would need a real collation model instead of this approximation.
- Case-folding also settles the underscore case ADR 0003 raised against the byte rule. `a_b` sorts after `ab` under both `general_ci` and `strcmp(strtoupper(...))`, where a byte comparison put it first. So that particular disagreement is gone — but the rule stays scoped to class extensions regardless, because a case-folded ASCII comparison is still only an approximation of `general_ci`, which also folds accents and other non-ASCII. Phrase titles, option ids and template names are not held to it.
- ADR 0003's remaining consequences carry over unchanged: `execute_order` is not part of the rule, `_output/extension_hint.php` is not checked separately, and the check lives with `validate-addon.php` rather than `check-data-consistency.php`.
- A production migration to a UCA collation such as `utf8mb4_0900_ai_ci` would flip the order again — those sort punctuation ahead of letters, so `ProfilePost` would precede `ProfilePostComment`. The response then is one reconciliation commit and a new ADR, not a normaliser.
