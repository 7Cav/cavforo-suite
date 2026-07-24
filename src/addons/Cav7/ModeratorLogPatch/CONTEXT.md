# Cav7/ModeratorLogPatch

Makes XenForo's moderator log record what members with moderation permissions
actually do, instead of only what the handful holding a moderator record do.
Companion to XF core's log handlers and to those of NF/Tickets and NF/Calendar,
all of which it extends. For terms shared across the suite, see the suite-wide
[CONTEXT.md](../../../../CONTEXT.md).

## Language

**Moderator record**:
A member's row in XenForo's moderator table, surfaced on their user as
`is_moderator`. It is an appointment, not a capability: it drives the moderator
bar and the staff list, and XenForo also uses it as the sole gate on writing to
the moderator log. Holding one is independent of holding any permission that
authorises a moderation action, which is why five members hold a record here
while several hundred can stick a thread and twenty of twenty-five admins hold
none. The two populations barely overlap, and the gap between them is the whole
reason this addon exists.
_Avoid_: "moderator" unqualified — it reads as either the record or anyone who
can moderate, and that ambiguity is precisely what this addon unpicks. Also
avoid "staff", which is an org role and tracks neither.

**Author-reachable action**:
A logged action a member can produce on their own content using only
own-content permissions: resolving their own ticket, changing its priority,
retitling their own thread, editing their own post, soft-deleting either, and
changing their own thread's prefix, custom fields or poll. These are the only
actions where the action name alone does not settle whether moderation
happened, because the same name covers both a member tidying up after
themselves and someone acting on a stranger's content. Every other logged
action — stick, lock, move, approve, hard delete, spam clean, reassign — cannot
be reached without a permission over other people's content, so it is
moderation by definition and needs no further test.
_Avoid_: "self-edit" for this category. An author-reachable action performed on
someone else's content is moderation and does log, so the category describes
which actions are *capable* of being self-actions, not who performed a given
one.
