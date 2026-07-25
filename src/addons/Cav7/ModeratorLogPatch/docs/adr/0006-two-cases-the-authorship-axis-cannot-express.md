# Two cases the authorship axis cannot express

2026-07-24

ADR 0001 states its premise as an absolute: for an **author-reachable action**, an
entry is written only when the actor is not the content's author, and every other
logged action needs authority over somebody else's content. Two cases sit outside
what that sentence can say, and neither is a reason to change the rule. Recorded
here so the next reader does not have to find them again.

## Acting inside your own profile

`XF\Entity\ProfilePost::canDelete('soft')` returns true when
`$visitor->user_id == $this->profile_user_id && $visitor->hasPermission('profilePost',
'manageOwn')`, and `ProfilePostComment::canDelete()` the same, keyed on
`$this->ProfilePost->profile_user_id`. `manageOwn` is a permission ordinary member
groups hold.

So a member clearing somebody else's post off their own wall reaches `delete_soft`
with the actor not being the author. The rule defers, neither profile-post handler
has a `delete_soft` rule, and a row is written where none was written before.
`attachment_deleted` reaches the same path.

The rule's axis is authorship of the content. The thing that escapes it is "acting
inside your own profile", which no list of action names can express: it is a
property of the relationship between the actor and where the content sits, not of
the action.

**Decision: leave the rule alone.** This is the noisy direction rather than the
audit-hole direction, and removing another member's content from your own wall is
defensibly worth a row. Adding a second axis to catch it would cost more than the
noise does.

## The member content type

XenForo's `UserHandler::setupLogEntityContent()` fills `content_user_id` from
`$content->user_id`, which for that content type is the moderated member's own id.
So `ContentAuthor::userId()` returns the subject of the moderation, and the rule
reads "the actor is the subject" where it says "the actor is the author".

It is inert. Every action logged against `user` is outside the author-reachable
set: `approve`, `approved`, `rejected`, `spam_clean`, `warning_given`,
`warning_expired`, `warning_removed`, `username_change_approved` and
`username_change_rejected`. Rule 3 answers before the comparison is reached, so
the reinterpretation never decides anything.

**Decision: write it down rather than special-case it.** A branch keyed on the
content type would be a rule about one handler in a class built on not having any,
and it would guard a comparison that is never reached. What was missing was that
anybody knew: it is now in `ContentAuthor`'s docblock, and
`cav7-moderator-log-patch:verify` prints a note for that content type saying its
authorship probe is inert rather than passing silently.

The day an author-reachable action is logged against a member, this stops being
inert, and the note is where somebody will see it.
