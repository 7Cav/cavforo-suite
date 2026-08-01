# Cav7/CalendarPatch

Behavioural fixes for the NF/Calendar XenForo add-on from [NixFifty](https://nixfifty.com), built as a companion add-on. It attaches to the vendor code through XenForo class extensions and ships none of the vendor's code, so NF/Calendar can be updated independently.

## What it does

**Joining an event's wait list works again.**

NF/Calendar 2.6.2 throws the moment its wait-list joiner is constructed, so every attempt to join a wait list fails and nobody can join. A class extension on `NF\Calendar\Service\EventWaitList\JoinerService` gets the joiner past that. Nothing else about the wait list changes.

## Requirements

- XenForo 2.3+
- PHP 8.x
- NF/Calendar 2.6.2+ installed (declared as a dependency in `addon.json`)

## Installation

1. Copy `src/addons/Cav7/CalendarPatch` into your XenForo installation at the same path.
2. Install the addon: `php cmd.php xf-addon:install Cav7/CalendarPatch`.

## Provenance

Unlike the other add-ons in this suite, CalendarPatch was not imported from a separate repository with `git subtree`. It started here, from issue #49, as the NF/Calendar companion to RosterPatch.

## License

MIT License, see [LICENSE](LICENSE) for details.

Copyright (c) 2026 7Cav
