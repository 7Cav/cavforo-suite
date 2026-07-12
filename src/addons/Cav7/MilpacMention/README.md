# Milpac Mention

XenForo add-on for the [7th Cavalry](https://7cav.us) that treats a linked milpac
like an `@`-mention.

A milpac is one member's roster profile, at `/rosters/profile/<relation_id>/`.
When that link appears in a post, a profile post, or a profile-post comment, the
linked member gets a distinct `milpac_mention` alert that opens the content, and
they can switch it off from their alert preferences. Members already hand-build
these roster links all the time, so the notification lands on the workflow that
exists today with no editor change.

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
  `XF\Service\Post\NotifierService`, `XF\Service\ProfilePost\NotifierService`, and
  `XF\Service\ProfilePostComment\NotifierService`. After the stock notifier pass
  each one raises `milpac_mention` on its own content type (`post`,
  `profile_post`, `profile_post_comment`), reusing that surface's stock alert
  handler rather than adding a new content type or handler.

The firing rules match `@`-mention behaviour: a member linking their own milpac is
not alerted, any number of links to one member is a single alert, a member both
`@`-mentioned and milpac-linked gets only the `@` alert, editing content to add a
link fires nothing, and milpac links count against the author's
`maxMentionedUsers` budget with `@` kept first.

## Requirements

- XenForo 2.3.0+
- NF/Rosters 2.1+ (the alert resolves the roster link to the linked member)

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
