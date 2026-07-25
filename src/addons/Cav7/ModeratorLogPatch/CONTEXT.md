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
A logged action a member can produce on their own content using only own-content
permissions. These are the actions where the name alone does not settle whether
moderation happened, because the same name covers both a member tidying up after
themselves and someone acting on a stranger's content; everything outside the
category is treated as moderation on the strength of its name.

Which names qualify is a reading of the code that logs each one, and it lives in
exactly one place: `AuthorshipRule::AUTHOR_REACHABLE_ACTIONS`. The reasoning for
the first set is in
[ADR-0001](docs/adr/0001-log-by-authorship-not-by-permission.md), and
[ADR-0004](docs/adr/0004-two-more-author-reachable-actions.md) records two names
that were missing from it — so membership is the part of this definition worth
checking against the code rather than against prose.
_Avoid_: "self-edit" for this category. An author-reachable action performed on
someone else's content is moderation and does log, so the category describes
which actions are *capable* of being self-actions, not who performed a given
one.

**Content author**:
The member a log entry's `content_user_id` names, read from the content entity's
`user_id` column by `ContentAuthor`. For seven of the eight registered content
types that is who wrote the thing. For `user` it is not: XenForo's member handler
logs actions taken *against* a member and fills the column from that member's own
id, so there "the author" is the subject of the moderation. It decides nothing,
because no action logged against a member is author-reachable, and
[ADR-0006](docs/adr/0006-two-cases-the-authorship-axis-cannot-express.md) records
why it is left that way.
_Avoid_: treating "author" and "content owner" as interchangeable when the content
type is a member.
