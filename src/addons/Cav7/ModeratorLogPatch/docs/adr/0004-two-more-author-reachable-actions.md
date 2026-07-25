# Two more author-reachable actions: attachment_deleted and poll_reset

2026-07-24

ADR 0001 settled the **author-reachable action**s and said every other logged
action is unreachable without authority over somebody else's content. Two are
reachable without it. Both are now in the list.

`poll_reset`. `XF\ControllerPlugin\PollPlugin::actionDelete()` checks
`$poll->canDelete($error)` once and then branches on a `poll_action` form field
to either `XF\Service\Poll\DeleterService::delete()`, which logs `poll_delete`,
or `XF\Service\Poll\ResetterService::reset()`, which logs `poll_reset`. One
controller action, one permission check, two log actions.
`XF\Poll\ThreadHandler::canDelete()` passes the thread's author while
`voter_count` is 0, so a thread starter can reach either branch on their own poll.

The two branches then part company, and that asymmetry is the argument rather
than something to explain away. `DeleterService` guards its log call with
`$content->User->user_id != \XF::visitor()->user_id`, so it never records the
author; `ResetterService` has no guard at all and records everybody. The same is
true of `CreatorService` and `EditorService`, which guard the same way. So
`poll_delete`, `poll_create` and `poll_edit` are in the list belt-and-braces
against a service losing its guard, and `poll_reset` is the one name the list
actually decides.

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

The list is now a strict superset of every author-withholding rule in the eight
handlers underneath: `edit`, `attachment_deleted` and `title` are all in it, and the `prefix_id` and
`custom_fields` cases beside them are entity field names, which the resolved action
handed to `isLoggable` never is. For an author our rule therefore answers first
every time; for anybody else that rule and ours both log. The deferral keeps its
reason — a rule this addon has not seen, in a later XenForo version or in somebody
else's handler, stays in force — but it earns it in the future rather than today.

## Considered options

Leaving both out and letting each handler underneath answer was the position ADR
0001 took, on the argument that a vendor rule in force is better than a rule of
ours. It holds only where a rule exists. For `attachment_deleted` two of the
three handlers that log it have none. For `poll_reset` no handler has one either,
and no service does: `poll_delete` at least has a decision behind it, taken in the
deleter service rather than in a handler, and `poll_reset` has nothing anywhere. So
deferring is not "the vendor decides" but "nobody decided".
