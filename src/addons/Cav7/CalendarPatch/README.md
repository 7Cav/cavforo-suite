# Cav7/CalendarPatch

Behavioural fixes for the NF/Calendar XenForo add-on from [NixFifty](https://nixfifty.com), built as a companion add-on. It attaches to the vendor code through XenForo class extensions and ships none of the vendor's code, so NF/Calendar can be updated independently.

This is the NF/Calendar counterpart to its sibling [RosterPatch](../RosterPatch/): a place for behaviour patches that live outside the vendor add-on, so a fix can ship on its own and be dropped once it is no longer needed, without touching NF/Calendar itself.

## What it does

**Joining an event's wait list works again.**

NF/Calendar 2.6.2 crashes the moment its wait-list joiner is constructed. `XF\Service\AbstractService::__construct` runs `setup()` before the `JoinerService` constructor has assigned its typed `$event` and `$user` properties, and `setup()` reads `$this->getUser()->user_id`, so it reaches for `$user` before it exists and PHP throws "Typed property ... must not be accessed before initialization". Every attempt to join a wait list hits this, so nobody can join.

A class extension on `NF\Calendar\Service\EventWaitList\JoinerService` guards that premature first `setup()` call: while `$user` is still unset it returns early and does nothing. The vendor constructor already calls `setup()` a second time, after it has assigned both properties, and that later call does the real work of building the wait-list entity. The guard relies on `isset()` being false for an uninitialized typed property, so it carries no state of its own and changes nothing once the properties are in place.

## Requirements

- XenForo 2.3+
- PHP 8.x
- NF/Calendar 2.6.2+ installed (declared as a dependency in `addon.json`)

## Installation

1. Copy `src/addons/Cav7/CalendarPatch` into your XenForo installation at the same path.
2. Install the addon: `php cmd.php xf-addon:install Cav7/CalendarPatch`.

For release builds, generate hashes first: `php cmd.php xf-addon:build-release Cav7/CalendarPatch`.

## Design notes

**One class extension, no vendor code.** The fix is a single `setup()` override, and the add-on ships no NF/Calendar files, so the vendor add-on updates on its own schedule and this patch keeps applying until the underlying bug is gone.

**If a vendor update reorders the constructor** so the properties are assigned before `setup()` runs, this add-on becomes redundant and can be dropped.

## Provenance

Unlike the other add-ons in this suite, CalendarPatch was not imported from a separate repository with `git subtree`. It started here, from issue #49, as the NF/Calendar companion to RosterPatch.

It can be retired once NixFifty corrects the joiner's constructor ordering upstream and the fix is verified on staging.

## License

MIT License, see [LICENSE](LICENSE) for details.

Copyright (c) 2026 7Cav
