# Milpac Mention

XenForo add-on for the [7th Cavalry](https://7cav.us) that treats a linked milpac
like an `@`-mention.

A milpac is one member's roster profile, at `/rosters/profile/<relation_id>/`.
When that link appears in a post, a profile post, a profile-post comment, a
report comment, or a ticket message, the linked member gets a distinct
`milpac_mention` alert that opens the content. The member can switch the post,
profile-post, profile-post-comment, and ticket alerts off from their alert
preferences; the report alert stays on, the same way XenForo won't let you mute
being named in a report.

The ticket-message surface only works when NF/Tickets is installed. It is a soft
dependency: leave NF/Tickets out and the add-on still installs, the other four
surfaces still fire, and the two ticket extensions stay dormant.

Terms used below — milpac link, milpac mention, surface, suppressed area — are
defined in [CONTEXT.md](CONTEXT.md). The design is in
[`docs/specs/milpac-mention-implementation-spec.md`](../../../../docs/specs/milpac-mention-implementation-spec.md).

## When the alert fires

The firing rules match `@`-mention behaviour: a member linking their own milpac
is not alerted, any number of links to one member is a single alert, a member
both `@`-mentioned and milpac-linked gets only the `@` alert, editing content to
add a link fires nothing, and milpac links count against the author's
`maxMentionedUsers` budget with `@` kept first. One rule has no `@` equivalent:
an admin can name forum nodes and ticket categories where the alert does not fire
at all, which [Configuration](#configuration) covers.

## The `$name` completer

Staff who link milpacs often do not have to hand-build the roster link. Type `$`
and the start of a member's name in any editor and a dropdown of milpac holders
opens, each row showing the rank and name with the roster below it so you can
tell same-name members apart. Pick one and it inserts the named roster link,
"Rank Name" pointing at `/rosters/profile/<relation_id>/`, the same link members
already build by hand. The rich editor inserts it as a link; plain BBCode and
mobile insert it as `[URL='…/rosters/profile/N/']Rank Name[/URL]` text rather than
a bare URL. `$` only opens the dropdown at a word boundary, so a `$` inside a
word (`cost$5`) never looks anything up, and a `$` that matches no milpac holder
leaves the dropdown closed.

The completer is only an input shortcut. It inserts the same link the add-on
already reads, so a `$name` insert alerts exactly as a hand-built link does.

## Typed `$name`

A `$username` also resolves when you just type it and post, without opening the
dropdown. On save or preview, a bare `$username` at a word boundary that matches a
current milpac holder becomes that member's named roster link, the same "Rank
Name" pointing at `/rosters/profile/<relation_id>/` the dropdown inserts, and
alerts that member. A typed `$name` and a picked `$name` save as the same link,
and because it resolves on the server it also works on mobile and in the plain
BBCode editor, where the dropdown is awkward.

It follows the rules XenForo uses for a typed `@username`: `me$user` in the
middle of a word stays literal, a `$name` inside `[CODE]`, `[PLAIN]`, or
`[URL=…]` stays literal, and a `$token` that is nobody's username — including a
member who holds no milpac — stays literal. The one difference from `@` is the
target: `$name` links a milpac, it does not `@`-mention a user.

## Requirements

- XenForo 2.3.0+
- NF/Rosters 2.1+ (the alert resolves the roster link to the linked member)
- NF/Tickets is optional. With it installed, milpac links in ticket messages fire
  the alert too; without it, the other four surfaces work as usual.

## Installation

1. Copy `src/addons/Cav7/MilpacMention` into your XenForo installation at
   `src/addons/Cav7/MilpacMention`
2. Install the add-on: **Admin CP → Add-ons → Milpac Mention → Install**
   (or `php cmd.php xf-addon:install Cav7/MilpacMention`)

Its alert rows carry `depends_on_addon_id`, so they clear themselves when the
add-on is uninstalled.

## Configuration

**Admin CP → Setup → Options → Milpac Mention** holds two lists of places where a
milpac link raises no alert.

- **Forums where milpac mentions are suppressed** picks forum nodes. It ships
  empty.
- **Ticket categories where milpac mentions are suppressed** picks NF/Tickets
  categories. It ships holding S1 Citations (17), Medal Recommendations (18),
  Medal Approvals (20) and Medal posting (21) — the award queues, where the
  linked milpac names the member an award is being processed for rather than
  someone being addressed, so the alert, whose line carries the ticket title,
  tells a member about their own pending award. Clear them if your board is laid
  out differently. With NF/Tickets absent the row renders read-only (greyed out)
  and says why.

Selecting an area suppresses it; anything you leave unselected alerts exactly as
it always has, so suppression is only ever something you asked for. Both controls
are pickers of real names rather than boxes for ids.

Suppression withholds the notification and nothing else. In a suppressed area the
milpac link still renders, still resolves, and the `$name` completer still works.
Suppression is by place: it does not consult who opened the ticket, who is a
participant, or who can view it.

## License

[MIT](LICENSE) © 2026 7Cav

## Provenance

Created in this repository for issue #84. No upstream import.
