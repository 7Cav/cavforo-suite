# Cav7/RosterPatch

Behavioural fixes for the [NF/Rosters](https://nixfifty.com/products/rosters-and-personnel-status-reports.5/) XenForo add-on, built as a companion add-on. It attaches to the vendor code through XenForo class extensions and ships none of the vendor's code, so NF/Rosters can be updated independently.

This is the home for NF/Rosters *behaviour* patches. Its sibling, [RosterAudit](../RosterAudit/), records an audit trail and guards the two gaps that protect that trail; the fixes here change how the roster behaves and carry their own on/off switch, so a misbehaving fix can be disabled without losing audit history.

## What it does

**Position group grants now follow the position, not just the seat.**

The vendor applies a position's extra user groups to a member only at the moment they are seated — when first added, or when their assignment changes. Editing the *position's* group list afterwards reaches nobody already holding it: the grant is a one-time snapshot stored on the user (`xf_user_group_change`, keyed `nfRostersPosition-{id}`), not a live binding. So the position config and the seated members drift apart in both directions — a group added to the position never reaches existing holders, and a group removed from it is never revoked.

A class extension on `NF\Rosters\Entity\Position` adds the `_postSave` hook the vendor is missing. When a position's group list changes, the add-on re-applies it to every current holder (primary and secondary) through XenForo's own user-group-change service — the same path the vendor uses. Because that service diffs across all of a member's grants, it never strips a group the member still holds through another position, roster, or rank.

**Award and service-record dates are fixed calendar days now.**

An award or service record carries a date with no time of day. The vendor stored that day at whatever time it was saved and rendered it in each viewer's timezone, so the same entry could read a day early or a day late depending on who was looking, and a staffer outside UTC often saw the wrong day before they touched the form.

The fix treats the date as one calendar day throughout. The submitted day is parsed at midnight UTC and rejected when it is not a real date, so 2026-13-40 is an error rather than a silent roll into 2027. The entity stores it at midnight UTC, the profile renders it in UTC to match the edit form, and every viewer reads the same day. A new entry's date defaults to the editor's own today in their timezone instead of UTC's.

This also reaches the PUC grants and the enlistment record that [EnlistmentDefaults](../EnlistmentDefaults/) stamps, though the two are stored differently. PUC grants are written at midnight UTC, so flooring leaves them on the same day for any board. The enlistment record is different: it is written at midnight in the board timezone, or at the milpac's creation time when the Join Date is blank or unusable. Flooring moves that record to its UTC calendar day, which matches the day shown before only on a board at or behind UTC. The live board is UTC+0 today, so nothing shifts. A board ahead of UTC would see such a record land on the previous UTC day. The template change also renders every row through the UTC getter for all viewers, so a historical row stored close to a UTC day boundary can read a different day to a viewer outside UTC than it did under the old per-viewer rendering. The calendar-day logic lives in `MilpacDate`, a pure class with no XenForo dependency, so the round trip is tested directly, and the entity, controller, and template wiring stays thin around it. Historical dates saved under the old behaviour are left as they are, and re-saving an entry through the corrected form stores it correctly.

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
  tests/                                 pure-logic tests + shape guards, no stack required
  _data/, _output/                       class-extension + template-modification registration
```

## License

MIT License, see [LICENSE](LICENSE) for details.

Copyright (c) 2026 7Cav
