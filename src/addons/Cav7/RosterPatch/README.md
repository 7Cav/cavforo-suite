# Cav7/RosterPatch

Behavioural fixes for the [NF/Rosters](https://nixfifty.com/products/rosters-and-personnel-status-reports.5/) XenForo add-on, built as a companion add-on. It attaches to the vendor code through XenForo class extensions and ships none of the vendor's code, so NF/Rosters can be updated independently.

This is the home for NF/Rosters *behaviour* patches. Its sibling, [RosterAudit](../RosterAudit/), records an audit trail and guards the two gaps that protect that trail; the fixes here change how the roster behaves and carry their own on/off switch, so a misbehaving fix can be disabled without losing audit history.

## What it does

**Position group grants now follow the position, not just the seat.**

The vendor applies a position's extra user groups to a member only at the moment they are seated — when first added, or when their assignment changes. Editing the *position's* group list afterwards reaches nobody already holding it, so the position config and the seated members drift apart in both directions — a group added to the position never reaches existing holders, and a group removed from it is never revoked.

When a position's group list changes, this add-on re-applies it to every current holder (primary and secondary) through XenForo's own user-group-change service — the same path the vendor uses. Because that service diffs across all of a member's grants, it never strips a group the member still holds through another position, roster, or rank. An empty group list revokes the grant cleanly.

**Award and service-record dates are fixed calendar days now.**

An award or service record carries a date with no time of day. The vendor stored that day at whatever time it was saved and rendered it in each viewer's timezone, so the same entry could read a day early or a day late depending on who was looking, and a staffer outside UTC often saw the wrong day before they touched the form.

The fix treats the date as one calendar day throughout. The submitted day is parsed at midnight UTC and rejected when it is not a real date, so 2026-13-40 is an error rather than a silent roll into 2027. The entity stores it at midnight UTC, the profile renders it in UTC to match the edit form, and every viewer reads the same day. A new entry's date defaults to the editor's own today in their timezone instead of UTC's.

This also reaches the PUC grants and the enlistment record that [EnlistmentDefaults](../EnlistmentDefaults/) stamps. Both are now written at midnight UTC, the enlistment record's fallback being midnight UTC of the milpac's creation day when the Join Date is blank or unusable, so flooring leaves them on the same day for any board. Historical dates saved under the old behaviour are left as they are, and re-saving an entry through the corrected form stores it correctly.

Three consequences come with that. The template change renders every row through the UTC getter for all viewers, so a historical row stored close to a UTC day boundary can read a different day to a viewer outside UTC than it did under the old per-viewer rendering. The getter always renders `Y-m-d`, so a style that reformatted the date cell loses that reformatting. And a date cell that passed no format argument at all stops rendering in the viewer's language format.

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

Run the tests with `tools/run-tests.sh RosterPatch`. They need only `php`. Everything that needs a live XenForo — the template modifications against the vendor's own copy of `nf_rosters_user_view`, above all — is verified by hand on a dev stack before a release, per [CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## License

MIT License, see [LICENSE](LICENSE) for details.

Copyright (c) 2026 7Cav
