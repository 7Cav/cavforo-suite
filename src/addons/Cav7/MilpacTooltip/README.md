# 7Cav - Milpac Tooltip

A XenForo 2.2+ add-on that surfaces a member's milpac in three places: their
hovercard tooltip, their profile page, and in-post links to their roster
profile. On the tooltip and the profile it shows a compact "mini-milpac" —
rank insignia and title, roster status, billet, MOS, and enlist date — linking
straight to that member's [NF Rosters](https://xenforo.com/community/resources/nf-rosters.7490/)
profile. An in-post roster-profile link gets the member's hovercard on hover,
the same card their username shows, which already carries the mini-milpac.
Members without a milpac get nothing extra; the tooltip, profile, and links
render exactly as before.

The decision behind the in-post hovercard is in
[ADR 0001](docs/adr/0001-in-post-milpac-hovercard-reuses-member-tooltip.md).

## The mini-milpac

The **enlist date** is the milpac's `joinDate` roster custom field, not the date
the milpac row was created. The **MOS** is the milpac's `mos` roster custom
field, shown verbatim. A milpac with either field blank omits that line.

When a member holds more than one roster row — a returning member can — the
block shows one on an active roster, so it reflects their current standing.

## In-post roster-profile links

A `/rosters/profile/<relation_id>/` link in a post gains the member's hovercard
on hover: the stock forum card their username shows, milpac chip included. The
href is untouched, so a click still opens the roster profile, and the link looks
the same at rest.

Two links stay plain, with no card. One that does not resolve to a member — a
deleted roster row, an invalid link. And one pointing at a
`/rosters/profile/<n>/` path on a **different board**: its relation_id means
nothing here, so it is left alone rather than hovered with a local member's card.

## Requirements

- XenForo 2.2+
- [NF Rosters](https://xenforo.com/community/resources/nf-rosters.7490/) 2.1+ with a `joinDate` roster field

## Installation

1. Copy `src/addons/Cav7/MilpacTooltip` into your XenForo installation at the same path.
2. Install the addon: `php cmd.php xf-addon:install Cav7/MilpacTooltip`.
3. No database changes are made, so install and uninstall are safe.

## Template Modifications

| Key | Target Template | Description |
|---|---|---|
| `cav7MilpacTooltipLink` | `member_tooltip` | Injects the mini-milpac after the "Last seen" row on the hovercard |
| `cav7MilpacProfile` | `member_view` | Injects the mini-milpac after the "Last seen" row on the profile |

## Templates

| Template | Purpose |
|---|---|
| `cav7_milpac` | Renders the mini-milpac block |
| `cav7_milpac.less` | Styles for the block |

## License

MIT. See [LICENSE](LICENSE).

## Provenance

Extracted from the retired `Cav7/Keycloak` add-on (recovered archive
`Cav7-Keycloak-recovered-20260617`), which owned the callback but not the
template modification that called it. This add-on re-homes the callback under
its own namespace and owns its modifications.
