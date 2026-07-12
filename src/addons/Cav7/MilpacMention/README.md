# Milpac Mention

XenForo add-on for the [7th Cavalry](https://7cav.us) that treats a linked milpac
like an `@`-mention.

A milpac is one member's roster profile, at `/rosters/profile/<relation_id>/`.
When that link appears in a post, a profile post, a profile-post comment, a
report comment, or a ticket message, the linked member gets a distinct
`milpac_mention` alert that opens the content. The member can switch the post,
profile-post, profile-post-comment, and ticket alerts off from their alert
preferences; the report alert stays on, the same way XenForo won't let you mute
being named in a report. Members already hand-build these roster links all the
time, so the notification lands on the workflow that exists today with no editor
change.

The ticket-message surface only works when NF/Tickets is installed. It is a soft
dependency: leave NF/Tickets out and the add-on still installs, the other four
surfaces still fire, and the two ticket extensions stay dormant.

The full design is in
[`docs/specs/milpac-mention-implementation-spec.md`](../../../../docs/specs/milpac-mention-implementation-spec.md).

## How it works

- Detection is one shared hook on `XF\Service\Message\PreparerService::prepare()`,
  the class every mention surface runs its message through, so it can never miss a
  surface. After the parent runs, it pulls each `relation_id` out of the message
  with `#/rosters/profile/(\d+)#`.
- `MilpacResolver` turns those `relation_id`s into `user_id`s by running the
  `NF\Rosters:RosterUser` finder in reverse. One member is one milpac is one
  `relation_id`, so it is a straight lookup.
- The detection regex, the reverse-resolution shape, and the firing rules are pure
  PHP in `MilpacResolver`, so they are unit-tested without XenForo.
- Firing is a thin extension on each surface's notifier:
  `XF\Service\Post\NotifierService`, `XF\Service\ProfilePost\NotifierService`,
  `XF\Service\ProfilePostComment\NotifierService`,
  `XF\Service\Report\NotifierService`, and, when NF/Tickets is installed,
  `NF\Tickets\Service\Message\Notifier`. After the stock notifier pass each one
  raises `milpac_mention` on its own content type (`post`, `profile_post`,
  `profile_post_comment`, `report`, `nf_tickets_message`), reusing that surface's
  stock alert handler rather than adding a new content type or handler.

The firing rules match `@`-mention behaviour: a member linking their own milpac is
not alerted, any number of links to one member is a single alert, a member both
`@`-mentioned and milpac-linked gets only the `@` alert, editing content to add a
link fires nothing, and milpac links count against the author's
`maxMentionedUsers` budget with `@` kept first.

## The `$name` completer

Staff who link milpacs often do not have to hand-build the roster link. Type `$`
and the start of a member's name in any editor and a dropdown of milpac holders
opens, each row showing the rank and name with the roster below it so you can
tell same-name members apart. Pick one and it inserts the named roster link,
"Rank Name" pointing at `/rosters/profile/<relation_id>/`, the same link members
already build by hand. The rich editor inserts it as a link; plain BBCode and
mobile insert it as `[URL='…/rosters/profile/N/']Rank Name[/URL]` text rather than
a bare URL.

The completer is only an input shortcut. It inserts the same link the detection
hook already reads, so a `$name` insert fires `milpac_mention` through the path
above with no second notification code. It attaches on XenForo's `editor:init`
event next to the `@` and `:` completers, so it overrides no core method, and `$`
only opens after a word boundary, so ordinary text like "it cost $5" does not
trigger it. The names come from the `milpac-mention/find` endpoint, which lists
current milpac holders whose username matches what you have typed. The completer
JS ships under `_files/js/Cav7/MilpacMention/`, where XenForo serves an add-on's
editor script from, and an `<xf:js>` template modification loads it on the editor.

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

There is nothing to configure and no `Setup.php`: the add-on is class extensions
plus alert data. Its alert rows carry `depends_on_addon_id`, so they clear
themselves when the add-on is uninstalled.

## License

[MIT](LICENSE) © 2026 7Cav

## Provenance

Created in this repository for issue #84. No upstream import.
