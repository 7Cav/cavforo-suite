# Two more author-reachable actions: attachment_deleted and poll_reset

2026-07-24

ADR 0001 settled the **author-reachable action**s and said every other logged
action is unreachable without authority over somebody else's content. Two are
reachable without it. Both are now in the list, which brings it to twelve.

`poll_reset`. `XF\ControllerPlugin\PollPlugin::actionDelete()` checks
`$poll->canDelete($error)` once and then branches on a `poll_action` form field
to either `XF\Service\Poll\DeleterService::delete()`, which logs `poll_delete`,
or `XF\Service\Poll\ResetterService::reset()`, which logs `poll_reset`. One
controller action, one permission check, two log actions.
`XF\Poll\ThreadHandler::canDelete()` passes the thread's author while
`voter_count` is 0, so a thread starter clearing the votes on their own poll
reaches `poll_reset` on exactly the terms that already put `poll_delete` in the
list.

`attachment_deleted`. `XF\ModeratorLog\PostHandler::isLoggable()` cases `'edit'`
and `'attachment_deleted'` together and returns false when the actor wrote the
post, so XenForo itself treats removing your own attachment as author-reachable.
Its profile-post and profile-post-comment handlers carry no equivalent rule, and
`XF\Attachment\ProfilePostHandler::onAttachmentDelete()` — and the comment
handler beside it — call `logModeratorAction(..., 'attachment_deleted', [], false)`
unconditionally. Deferring therefore withholds the action for a post author and
records it for a profile-post author, which is not a rule anyone chose.

## Consequences

Adding a name to the list can only withhold an entry, and only from the member
who wrote the content. A non-author and a member who holds a **moderator record**
both still reach the handler underneath, so neither can regress. What changes is
that a member removing an attachment from their own profile post, or resetting
their own poll, writes nothing — which is what the rest of the list already does
for a member editing their own post.

The asymmetry the earlier README described as XenForo's to keep is gone with it.
It was still a real illustration of the deferral working; `edit` on a thread,
where XenForo's thread handler withholds `title` but not `edit`, is the same
illustration and needs no exception.

## Considered options

Leaving both out and letting each handler underneath answer was the position ADR
0001 took, on the argument that a vendor rule in force is better than a rule of
ours. It holds only where a rule exists. For `attachment_deleted` two of the
three handlers that log it have none, and for `poll_reset` no handler has one at
all, so deferring is not "the vendor decides" but "nobody decided".
