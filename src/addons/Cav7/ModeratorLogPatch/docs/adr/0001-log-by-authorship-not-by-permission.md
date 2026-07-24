# Log by authorship, not by permission

2026-07-24

XenForo writes a moderator log entry only when the actor holds a **moderator
record**, which here is five members out of the several hundred who can
moderate. Opening that gate forces a decision about the **author-reachable
action**s — editing, retitling, soft-deleting, changing a prefix or poll,
resolving or reprioritising a ticket — where the action name alone does not say
whether moderation happened. We decided it by authorship: for those actions an
entry is written only when the actor is not the content's author. Every other
logged action is unreachable without authority over someone else's content, so
it is always logged.

## Considered options

The alternative was to ask whether the actor holds the moderator-grade
permission behind the action — the "any" half of XenForo's own/any permission
pairs, `manageAnyThread` against `editOwnThreadTitle` and so on. It reads closer
to the intent, and it captures one thing authorship does not: a permission
holder acting on their own content.

We rejected it on its failure mode. It needs a hand-maintained map of roughly
nineteen (content type, action) pairs to permission names across four
permission scopes, several of them owned by third-party addons. When one of
those names changes, the check stops matching, the entries stop appearing, and
nothing says so — the exact failure this addon exists to fix, reintroduced
inside the fix. Authorship names no permissions at all. It also agrees with the
permission check on every action taken against another member's content, since
reaching that content required the permission in the first place, so the two
rules only ever disagree about self-actions.

NF/Calendar had already reached the same conclusion for its own `edit` action,
which is some evidence the shape is right rather than merely convenient.

## Consequences

Someone holding a moderation permission but no moderator record, acting on
their own content, is not logged. Anyone who does hold a moderator record is
untouched: the override defers to the parent implementation for them, so their
entries are identical to what XenForo wrote before this addon existed, and no
existing behaviour can regress.
