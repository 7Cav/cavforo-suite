# Cav7/RosterPatch

Behavioural fixes for the [NF/Rosters](https://nixfifty.com/products/rosters-and-personnel-status-reports.5/) XenForo add-on, built as a companion add-on. It attaches to the vendor code through XenForo class extensions and ships none of the vendor's code, so NF/Rosters can be updated independently.

No vendor file is quoted anywhere in this add-on, tests included. The vendor's markup is described rather than copied: the shipped `_data/template_modifications.xml` holds `<find>` regular expressions over the `{{ date(...) }}` expression, and `tests/MilpacDateTemplateModificationTest.php` writes out date-cell spellings naming the same expression those patterns already describe. A template modification is a description of the markup it targets, so that much is intrinsic to the add-on; a copy of the vendor's template is not, and there is none.

This is the home for NF/Rosters *behaviour* patches. Its sibling, [RosterAudit](../RosterAudit/), records an audit trail and guards the two gaps that protect that trail; the fixes here change how the roster behaves and carry their own on/off switch, so a misbehaving fix can be disabled without losing audit history.

## What it does

**Position group grants now follow the position, not just the seat.**

The vendor applies a position's extra user groups to a member only at the moment they are seated — when first added, or when their assignment changes. Editing the *position's* group list afterwards reaches nobody already holding it: the grant is a one-time snapshot stored on the user (`xf_user_group_change`, keyed `nfRostersPosition-{id}`), not a live binding. So the position config and the seated members drift apart in both directions — a group added to the position never reaches existing holders, and a group removed from it is never revoked.

A class extension on `NF\Rosters\Entity\Position` adds the `_postSave` hook the vendor is missing. When a position's group list changes, the add-on re-applies it to every current holder (primary and secondary) through XenForo's own user-group-change service — the same path the vendor uses. Because that service diffs across all of a member's grants, it never strips a group the member still holds through another position, roster, or rank.

**Award and service-record dates are fixed calendar days now.**

An award or service record carries a date with no time of day. The vendor stored that day at whatever time it was saved and rendered it in each viewer's timezone, so the same entry could read a day early or a day late depending on who was looking, and a staffer outside UTC often saw the wrong day before they touched the form.

The fix treats the date as one calendar day throughout. The submitted day is parsed at midnight UTC and rejected when it is not a real date, so 2026-13-40 is an error rather than a silent roll into 2027. The entity stores it at midnight UTC, the profile renders it in UTC to match the edit form, and every viewer reads the same day. A new entry's date defaults to the editor's own today in their timezone instead of UTC's.

This also reaches the PUC grants and the enlistment record that [EnlistmentDefaults](../EnlistmentDefaults/) stamps. Both are now written at midnight UTC, the enlistment record's fallback being midnight UTC of the milpac's creation day when the Join Date is blank or unusable, so flooring leaves them on the same day for any board. The template change also renders every row through the UTC getter for all viewers, so a historical row stored close to a UTC day boundary can read a different day to a viewer outside UTC than it did under the old per-viewer rendering. The calendar-day logic lives in `MilpacDate`, a pure class with no XenForo dependency, so the round trip is tested directly, and the entity, controller, and template wiring stays thin around it. Historical dates saved under the old behaviour are left as they are, and re-saving an entry through the corrected form stores it correctly.

## Reconciling the existing backlog

The hook fixes drift from the moment it is installed, but it only fires when a position's group list actually changes. Positions whose list is already correct yet stale on the members — the backlog that built up before the hook existed — need a one-time pass:

```
php cmd.php cav7-rosterpatch:sync-position-groups
```

It reconciles every position's current groups to its holders and reports only the members whose recorded grant had drifted — the ones it actually changed, not the full headcount. `--dry-run` reports what would change without touching anything; `--position=<id>` limits the run to one position; `-v` lists the affected members. It is idempotent, so re-running it is safe — a second pass reports nothing left to change.

## Requirements

- XenForo 2.3+
- PHP 8.0+
- NF/Rosters 2.1+ installed (declared as a dependency in `addon.json`)

## Installation

1. Copy `src/addons/Cav7/RosterPatch` into your XenForo installation at the same path.
2. Install the addon: `php cmd.php xf-addon:install Cav7/RosterPatch`.
3. Reconcile the existing backlog once (see above), or skip it and let the hook fix each position the next time its groups are edited.

For release builds, generate hashes first: `php cmd.php xf-addon:build-release Cav7/RosterPatch`.

## Design notes

**One `addUserGroupChange` per holder, not remove-then-add.** Re-applying the grant at the same key transitions a holder from their stored snapshot to the position's current groups in a single call, so still-valid groups are never toggled off and back on. An empty group list revokes cleanly (the service deletes the change set when there is nothing left to add).

**The hook runs inside the position's save.** The re-sync happens in `_postSave`, within the same transaction as the position edit, mirroring how the vendor's Adder and Editor apply grants. A position holds at most a handful of members, so the per-save cost is small.

**The date cells are matched by pattern, not by exact vendor markup.** A style can carry its own edited copy of `nf_rosters_user_view`. If that copy differs from the vendor's by so much as a space, an exact find matches nothing in it, and XenForo does not treat that as an error: the add-on still reports as installed and active while that style renders the date in the viewer's timezone. Both modifications are `preg_replace` patterns over the whole `{{ date(...) }}` expression: whitespace around the call and its arguments, and any argument list that carries no parentheses or braces of its own. `MilpacDateTemplateModificationTest` applies them over ten spellings of each date cell — whitespace variants, an extra trailing argument, a missing format argument, and four different wrappers around the expression — and asserts each is rewritten exactly once. The entry labelled `the vendor spelling` is the one NF/Rosters ships as of 2.1.5, and is the only record of that left in the repo.

**What the patterns deliberately will not match.** They take the whole `{{ … }}` expression, not the `date()` call inside one, so a style that wrapped or extended the expression is left alone. A null-guard ternary (`{{ $record.record_date ? date(...) : '-' }}`), a filter (`{{ date(...)|escape }}`), a concatenated suffix (`{{ date(...) . ' UTC' }}`), and a nested call in the argument list (`{{ date($record.record_date, fmt('Y-m-d')) }}`) all match nothing.

Not because a call-anchored replacement would produce invalid markup. Put each of those four through XenForo 2.3.11's own template compiler with the call swapped for the getter and all four compile, the ternary to exactly the code you would write by hand: `($__vars['record']['record_date'] ? $__templater->escape($__templater->method($__vars['record'], 'getRecordDate', array())) : '-')`. `{$…}` is an expression term inside `{{ … }}` the same way it is a variable in running text (`expression_part ::= var` in the compiler's own grammar), so the nesting is fine.

The reason the limit stays is that a `<find>` is a regular expression over template text rather than a parse of it, and the patterns swallow the format argument. Anchored on the whole `{{ … }}` expression they only ever reach a cell that renders a date, where losing the style's format is the cost the next paragraph owns. Anchored on the call they would reach every `date($record.record_date, …)` in the template, including the ones where the format is doing work: `<xf:if is="date($record.record_date, 'Y') == 2020">` starts comparing `2020-03-04` against `2020`, and `<div data-day="{{ date($record.record_date, 'D') }}">` starts emitting a full date to whatever reads that attribute. Both spellings compile, so nothing reports either. Widening the find means first reading the surrounding logic of every expression it would newly take, and no one has. `MilpacDateTemplateModificationTest` asserts all four as non-matches, so anyone doing that reading later does it with the list in front of them.

The patterns also swallow the format argument rather than preserving it, and the getter always renders `Y-m-d`. A style that reformatted the date loses that reformatting, and a call with no format argument stops using the viewer's language format. The fix is worth that cost, but it is a cost.

**What the tests can and cannot say.** `MilpacDateTemplateModificationTest` runs the shipped `<find>`/`<replace>` over date-cell markup written out in the test itself, so it pins the patterns against the spellings we have thought of: narrow a find until it stops taking one of them and the suite fails. What it cannot do is pin them against the add-on you have installed. CI has no XenForo and no vendor tree, so an NF/Rosters release that moved or rewrote the date cell would leave every check green while the board went back to per-viewer dates. It also applies the modifications through a hand-written mirror of XenForo's `TemplateModificationRepository` rather than through XenForo itself; the mirror is faithful for everything shipped here and the test says where it diverges, but nothing enforces that across a XenForo upgrade.

Both gaps close in the same place: a run against a real install on the dev stack, after an NF/Rosters upgrade or a style edit. The two copies of the template come from different places there. A style's own copy is readable through the admin control panel's template editor, under Appearance → Templates. The vendor's master copy is not, on a production board: `XF\Entity\Style::canEdit()` returns false for `style_id 0` outside development mode, `StyleRepository::createStyleTree()` leaves master out of the style selector for the same reason, and the template editor answers `templates_in_this_style_can_not_be_modified`. Read the vendor's copy from NF/Rosters' own `_data/templates.xml` instead. (A style with no copy of its own renders the master, so its template list will show the vendor text — but that is the style's entry, not master's.)

**If a vendor update adds its own re-sync, this add-on becomes redundant** and can be dropped.

## Layout

```
src/addons/Cav7/RosterPatch/
  MilpacDate.php                         calendar-day parse/render/floor logic (pure)
  NF/Rosters/Entity/Position.php         position group-grant re-sync hook
  NF/Rosters/Entity/RosterUserAward.php  floor award_date to midnight UTC on save
  NF/Rosters/Entity/ServiceRecord.php    floor record_date to midnight UTC on save
  NF/Rosters/Pub/Controller/Roster.php   reject bad dates, default a new entry to the editor's today
  Repository/PositionGroupSync.php       holder query + the re-apply logic
  Cli/Command/SyncPositionGroups.php     one-off backlog reconcile
  tests/                                 pure-logic tests, shape guards, and the pattern tests over date-cell markup written in the tests, no stack required
  _data/, _output/                       class-extension + template-modification registration
```

## License

MIT License, see [LICENSE](LICENSE) for details.

Copyright (c) 2026 7Cav
