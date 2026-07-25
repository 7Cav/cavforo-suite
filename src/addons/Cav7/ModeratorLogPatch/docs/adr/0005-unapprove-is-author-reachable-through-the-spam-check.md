# unapprove is author-reachable, through the spam check

2026-07-24

`unapprove` was outside the **author-reachable action**s on the reading that
nobody can unapprove their own content: sending something back to the approval
queue is a moderator's button. The button is, but the transition is not. A member
editing their own post can produce it on themselves, and until now that wrote a
log entry saying they unapproved their own post.

`XF\Service\Post\EditorService::checkForSpam()` runs on the member's own save and
calls `Post\PreparerService::checkForSpam()` whenever the post is visible and
`XF\Entity\User::isSpamCheckRequired()` is true. On a `moderated` decision the
preparer sets `message_state = 'moderated'`, or `$thread->discussion_state` for a
first post. `XF\ModeratorLog\PostHandler::getLogActionForChange()` resolves
`message_state` changing to `moderated` as `unapprove`, and `ThreadHandler` does
the same for `discussion_state`. The actor on that save is the member, and the
content is theirs.

The rule deferred, because `unapprove` was not in the list. `PostHandler` cases
only `edit` and `attachment_deleted`, so nothing underneath withheld it either,
and a row was written. In the same save the `message` change resolves to `edit`
and *is* withheld, so the log showed a member unapproving their own post with no
edit beside it: an action they never took, and one that reads as them hiding
their own content.

The affected population is not incidental. `isSpamCheckRequired()` is
`!$this->is_admin && !$this->is_moderator && maxContentSpamMessages &&
!hasPermission('general', 'bypassSpamCheck') && message_count <
maxContentSpamMessages`, so it is gated on holding no moderator record: exactly
the members this addon newly logs, and newer ones at that.

## Decision

`unapprove` joins the list. By this addon's own test, a name belongs in it when a
member can produce it on their own content with own-content permissions alone,
and this one qualifies.

Adding it withholds only for the author. A stranger unapproving your post still
logs, and a member who holds a **moderator record** is untouched, because rule 1
hands them to the handler underneath before the list is consulted.

## Considered options

Adding `approve` alongside it, for symmetry. It is not reachable the same way and
stays out: every path that puts a `message_state` or `discussion_state` back to
`visible` is `XF\InlineMod\*Handler`, one of the `ApproverService`s, a
`XF\Spam\Cleaner\*`, or `Post\MoverService`, and all of them need authority over
the content. The two names are asymmetric because the spam check is, and this
list follows what the code can produce rather than what pairs neatly.

## Consequences

A member who trips the spam filter on their own edit now writes nothing about it,
which is the same answer the `edit` in that save already got.

Somebody reading the log can no longer tell, from the log, that a member's post
went back to the queue. That was never a reliable signal: for a record holder it
was recorded and for everybody else it was not, and the approval queue is where
that state actually lives.
