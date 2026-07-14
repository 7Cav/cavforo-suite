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

An in-post link to a roster profile gets its hovercard through two class
extensions rather than a template modification, because the hover is the
member's whole forum card, not just the mini-milpac.
`XF\BbCode\Renderer\Html::getRenderedLink` is the choke point every rendered
`[URL]` anchor passes through, so an extension there recognises a
`/rosters/profile/<relation_id>/` link, resolves its `relation_id` to the
member's `user_id`, and stamps the anchor with `data-xf-init="member-tooltip"`
so XenForo's own tooltip handler drives the hover. Those lookups are batched per
post: the extension collects a message's roster-profile relation_ids once, at
the message-level render boundary (`setupRender`) before any anchor is stamped,
and resolves them in a single query, so a post with many roster links, or the
same milpac linked repeatedly, costs one lookup rather than one per link. The
stamped anchor points
XenForo at the roster URL with `tooltip=1`; an extension of
`NF\Rosters\Pub\Controller\Roster::actionProfile` answers that request by
resolving to the member and handing off to `MemberController::actionTooltip`,
so the hover shows the stock `member_tooltip`, the same card the username
shows, milpac chip included. The href is left alone, so a click still opens the
roster profile. Only a link that points at this board is stamped — a relative
link, or an absolute one whose host matches the board's `boardUrl`. A
`/rosters/profile/<n>/` link to a different board is recognised but left as a
plain link, so its local relation_id is never resolved to a local member and
the hover cannot show the wrong card. Recognising the link, the same-origin
gate, and stamping the anchor are pure PHP in `RosterLink`, unit-tested without
XenForo; a link that does not resolve to a member stays a plain link with no
card. The design is recorded in
[ADR 0001](docs/adr/0001-in-post-milpac-hovercard-reuses-member-tooltip.md).

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
