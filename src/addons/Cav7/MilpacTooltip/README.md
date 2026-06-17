# 7Cav - Milpac Tooltip

A XenForo 2.2+ add-on that surfaces a member's milpac in two places: their
hovercard tooltip and their profile page. Both show a compact "mini-milpac" —
rank insignia and title, roster status, billet, MOS, and enlist date — linking
straight to that member's [NF Rosters](https://xenforo.com/community/resources/nf-rosters.7490/)
profile. Members without a milpac get nothing extra; the tooltip and profile
render exactly as before.

## How it works

Two template modifications inject the same callback, each wrapped in an
`<xf:if contentcheck>` so the block disappears when there is nothing to show:

- `cav7MilpacTooltipLink` on `member_tooltip`, after the "Last seen" row
- `cav7MilpacProfile` on `member_view`, after the "Last seen" row

The callback, `Cav7\MilpacTooltip\Template\MemberMilpacTooltip::renderMilpac`,
resolves the member's `NF\Rosters:RosterUser` and hands it to the `cav7_milpac`
template, which renders the markup. Keeping the markup in a template (rather than
building an HTML string in PHP) means it stays escaped, themeable, and styled
through `cav7_milpac.less`. The rank, roster, and position relations are already
eager-loaded with the milpac, so the block costs one extra query and no joins
beyond what NF/Rosters loads anyway.

The **enlist date** is the milpac's `joinDate` roster custom field, not the date
the milpac row was created. It is stored as a plain `Y-m-d` string with no time
or timezone, so the callback formats it directly rather than through XenForo's
timezone-aware date helper (which would shift it a day for some viewers). A
milpac with no `joinDate` simply omits that line.

The **MOS** is the milpac's `mos` roster custom field, shown verbatim. A milpac
with a blank `mos` omits that line too.

When a member holds more than one roster row — a returning member can — the
callback prefers one on an active roster so the block reflects their current
standing.

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

## Addon Info

| Field | Value |
|---|---|
| Addon ID | `Cav7/MilpacTooltip` |
| Namespace | `Cav7\MilpacTooltip` |
| Version | 1.0.0 (`1000070`) |
| Developer | Cav7 |

## License

MIT. See [LICENSE](LICENSE).

## Provenance

Extracted from the retired `Cav7/Keycloak` add-on (recovered archive
`Cav7-Keycloak-recovered-20260617`). The callback originally lived at
`Cav7\Keycloak\Tools\TemplateModifications\MemberMilpacTooltip::getMilpacUrl` and
was wired into `member_tooltip` by a hand-made template modification that
Keycloak never owned — so removing Keycloak left the callback dangling
(`error_invalid_class`). This add-on re-homes the callback under its own
namespace and owns its modifications. The behaviour was expanded from a bare
"MILPAC" link to the full mini-milpac block, extended to the profile page,
switched to the `joinDate` custom field for the enlist date, and given an MOS
line from the `mos` custom field.
