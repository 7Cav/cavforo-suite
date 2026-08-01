# 7Cav - Roster Gamertag Search

A XenForo addon that adds a gamertag/Cav name search page to the [NF Rosters](https://xenforo.com/community/resources/nf-rosters.7490/) (Milpacs) section. Members can quickly look up any roster entry by username or console gamertag directly from the roster index.

## Features

- **Search overlay**, triggered by a "Gamertag Search" button injected into the roster index page actions bar
- Searches both **username** and **console gamertag** (`consoleGamertag` custom field) with a single query
- Displays **rank**, **roster name**, and **gamertag** for each result
- Minimum 2-character query, results capped at 50

## Requirements

- XenForo 2.x
- [NF Rosters](https://xenforo.com/community/resources/nf-rosters.7490/) addon with a `consoleGamertag` roster field

## Installation

1. Upload the `Cav7/RosterSearch` directory into your XenForo `src/addons/` folder so the path is `src/addons/Cav7/RosterSearch/`.
2. In the XenForo Admin CP go to **Add-ons → Install/Upgrade from Archive** (or **Manage Add-ons → Install**) and select this addon.
3. No database changes are made, so install and uninstall are safe.

## Routes Added

| Route | Action | Description |
|---|---|---|
| `/rosters/search` | `actionSearch` | Full search results page |
| `/rosters/search-overlay` | `actionSearchOverlay` | Overlay entry point (linked from roster index) |

## Template Modifications

| Key | Target Template | Description |
|---|---|---|
| `cav7RosterSearchButton` | `nf_rosters_roster_index` | Injects the "Gamertag Search" button into the page actions bar |

## License

See [LICENSE.md](LICENSE.md).

## Provenance

Imported from https://github.com/7Cav/RosterSearch at commit 7ad8a00a8ab34e3cf60e78c6630daa8afafb2354.
