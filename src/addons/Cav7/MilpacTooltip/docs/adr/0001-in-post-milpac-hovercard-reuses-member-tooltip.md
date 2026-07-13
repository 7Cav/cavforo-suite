# In-post milpac hovercard reuses the XenForo member tooltip

An in-post roster-profile link (`/rosters/profile/<relation_id>/`) should get a hovercard the way a `[USER]` mention does. Instead of building a milpac-specific card, MilpacTooltip resolves the link's `relation_id` to its member's `user_id` and serves XenForo's own `member_tooltip`, which already carries the milpac chip this add-on injects. A class extension of `XF\BbCode\Renderer\Html::getRenderedLink` stamps the roster anchor so its hover behaves like a member tooltip; a class extension of `NF\Rosters\Pub\Controller\Roster::actionProfile` answers `tooltip=1` by resolving to the member and handing off to `MemberController::actionTooltip`. No vendor files are modified.

This is the most literal reading of "match XenForo": hovering a roster link gives the identical card as hovering that person's username. It stays correct because `RosterUser.user_id` is required, so every milpac maps to a member, and a member has at most one milpac, so the member tooltip's active-milpac chip is the milpac the link points at.

## Considered options

A bespoke milpac-first hovercard, with its own endpoint, template, and stylesheet keyed on `relation_id`, was designed and prototyped. It was rejected: it duplicated rendering the member tooltip already does, would drift from the member hovercard over time, and added nothing once the milpac chip already lives in `member_tooltip`.

## Consequences

The hovercard is member-centric, not milpac-centric. The milpac is one chip inside the member's forum card, next to the avatar, banners, and member actions. Hover and click also diverge: clicking still opens the roster profile (`/rosters/profile/N/`), while the hovercard's own actions point at the forum profile (`/members/N/`). Styling the link itself at rest is out of scope here and tracked separately in issue #107.
