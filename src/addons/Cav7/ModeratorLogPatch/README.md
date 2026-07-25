# 7Cav - Moderator Log Patch

Makes the moderator log record what members with moderation permissions actually
do. Extends XenForo's own moderator log handlers, plus those of
[NF Tickets](https://nixfifty.com/products/tickets.5/) and
[NF Calendar](https://nixfifty.com/products/calendar.9/); it changes no vendor
file.

## The problem it fixes

A thread moderation action only reached the log when the person taking it held a
**moderator record** — a row in XenForo's moderator table, which is an
appointment rather than a capability. Everyone else's actions went through
normally and wrote nothing. No error, no warning, no entry.

Holding the record and holding a permission that authorises a moderation action
are independent, and on this board the two populations barely overlap; the
[CONTEXT.md](CONTEXT.md) entry for **moderator record** has the shape of the gap.
So the log was recording a small fraction of the moderation happening on the
forum, and reading as though the rest had not happened.

Stickies were where it was noticed. Every logged action was affected the same
way: locks, prefixes, moves, approvals, soft deletes, thread type changes, title
edits, and the post, profile-post, ticket and calendar equivalents.

## What it changes

Two overrides on the moderator log handler, applied to every registered content
type.

The user-level gate accepts any logged-in member instead of only record holders.
Guests keep failing it, which is what keeps the cron runner and the system actors
that run as a guest out of the log.

The per-action check adds one rule in front of the handler's own. For the
**author-reachable action**s — the ones a member can produce on their own content
with own-content permissions, decided in
[ADR-0001](docs/adr/0001-log-by-authorship-not-by-permission.md) and
[ADR-0004](docs/adr/0004-two-more-author-reachable-actions.md) and listed in
`AuthorshipRule::AUTHOR_REACHABLE_ACTIONS` — an entry is written only when the
actor is not the content's author. Everything else is taken to be unreachable
without authority over somebody else's content, so it always logs. A member who
holds a moderator record skips the rule entirely.

Every other case is handed to the handler underneath rather than answered here.
That is deliberate. Seven of the eight registered handlers override this check
with rules of their own — XenForo's thread, post and both profile-post handlers,
both ticket handlers, and the calendar handler; only XenForo's user handler does
not. Returning an answer instead of delegating would silently discard every one
of them, including any that arrives in a version or an addon this one has not
seen. It is also why record holders cannot regress: for them the override steps
aside, so their entries are byte for byte what XenForo wrote before.

Entries themselves are unchanged. The acting member, their IP, the timestamp,
the content and the URL are all filled in by the same core method that filled
them in for a record holder, because nothing about that method is touched. They
appear in the ACP moderator log and in the "Moderator actions" view on the
thread, and there is nothing to migrate: the first action after the addon is
enabled writes an entry.

## What it does not change

Who can moderate. No permission is read, granted, or checked anywhere in this
addon; the actions it logs are the ones a member could already take.

Nobody gains a moderator record, and the record keeps every other meaning it
had — the moderator bar, the staff list, and everything else built on
`is_moderator`.

Why the rule keys on authorship rather than on the moderator-grade permission
behind each action, and what that costs, is in
[ADR-0001](docs/adr/0001-log-by-authorship-not-by-permission.md).

## The behaviour change worth knowing about

**Log volume goes up a lot.** Everyone who moderates without a record was
writing nothing and now writes entries. This addon touches no retention setting;
XenForo's `moderatorLogLength` is the one to look at first if the table gets
uncomfortable.

**A member holding a moderation permission but no moderator record, acting on
their own content, is still not logged.** That is the one case an
authorship rule cannot see and a permission rule could. ADR-0001 records why we
took the trade.

**Most of the list is withheld from its author here rather than by the handler
underneath.** Across the eight registered handlers only two action names are
withheld from the author by a handler at all: `edit`, by XenForo's post and both
profile-post handlers, by the ticket-message handler and by the calendar handler,
and `title`, by XenForo's thread handler and the ticket handler. XenForo's member
handler withholds nothing. Every other name in the list is this addon's decision
and nobody else's, which is why each one is an ADR rather than a line in a
switch: [ADR-0004](docs/adr/0004-two-more-author-reachable-actions.md) for
`attachment_deleted` and `poll_reset`, and
[ADR-0005](docs/adr/0005-unapprove-is-author-reachable-through-the-spam-check.md)
for `unapprove`.

**A member who trips the spam filter on their own edit is no longer recorded as
having unapproved their own post.** The spam check runs during the member's own
save and sends the content back to the queue, which resolves to `unapprove`.
ADR-0005 has the path.

**Two cases the rule's authorship axis cannot express** are recorded in
[ADR-0006](docs/adr/0006-two-cases-the-authorship-axis-cannot-express.md): a
member clearing somebody else's post off their own profile, which now writes an
entry where nothing was written before, and the member content type, where
"the author" means the member being moderated.

## Verifying an install

```
php cmd.php cav7-moderator-log-patch:verify <node> <user> <category>
```

- `node` — a forum the run may create and delete a throwaway thread in.
- `user` — the member the run acts as. It must be one holding no moderator
  record, and the command refuses otherwise, because a green run against a
  record holder proves nothing.
- `category` — a category the run reads sample content from, for the content
  types that are filed under one. Read-only.
- `--category-id <content_type>=<id>` — optional, repeatable. The two content
  types filed under a category use unrelated id spaces (`ticket_category_id` and
  `category_id`), so one number is right for both only by coincidence. Give one
  per type when it is not.

Three phases. It reads the registered handler content types off the install,
checks each one resolves through this addon, and reports anything else in the
handler's class chain that declares the user gate this addon replaces. It then asks
each resolved handler's two gates the questions the rule is made of — every
author-reachable action, as the member who wrote the content, as somebody else, and
as a member who both wrote it and holds a moderator record — and asks the same
handler built without the extension, so the run can show which answers this addon
changed and that a record holder's are unchanged. That phase writes nothing. It
also says, per content type, whether the sample it read was real content in the
scope you named; a `[board sample]` or `[fabricated sample]` tag on a PASS means
that line was earned somewhere other than where you pointed it. Finally it creates
a throwaway thread, sticks it, retitles it, unsticks it, checks which of those
landed in `xf_moderator_log` and that the entry is reachable from the thread's own
moderator actions view, then deletes the thread and the rows.

Run it after any XenForo or vendor upgrade. The failure this catches writes
nothing anywhere: a class extension whose `from_class` no longer resolves to the
handler it names is active, valid, exported, and inert, and the entries simply
stop appearing again.
[ADR-0003](docs/adr/0003-register-the-name-xenforo-resolves-to.md) covers the
one place that is already true.

## Requirements

XenForo 2.3+, and nothing else. The extensions against the ticket and calendar
handlers install and sit inert when those addons are absent, because XenForo
checks that the extending class exists and not the extended one.

## Reverting

Disable the addon. Everything it adds is a class extension, so the log is back
to its previous behaviour on the next action. It creates no tables, options or
fields, and there is no `Setup.php` to unwind. Entries already written stay
written.

## Assumptions about code it does not own

Every seam is somebody else's. Both overridden methods, the abstract handler
they live on, the resolved action names the rule keys on, and the aliasing that
decides which class an extension lands on all belong to XenForo or to a vendor
addon. `docs/adr/0002-one-extension-per-registered-handler.md` and
`docs/adr/0003-register-the-name-xenforo-resolves-to.md` name the two that
already bit.

`tests/WiringTest.php` runs with no XenForo and no vendor code on the include
path, so it pins **this addon's side** of each seam. An edit here that stops
matching the assumption fails the build. It cannot see the other side: rename a
handler, add a real `TicketHandler.php`, change the aliasable-namespace list, or
resolve a handler in a different order, and the suite stays green. That is what
the verification command is for.

## Tests

`tools/run-tests.sh ModeratorLogPatch`.

- `tests/AuthorshipRuleTest.php` exercises the decision for real.
  `AuthorshipRule` needs nothing from XenForo, so the whole rule — the ordering,
  the exact action names, the guards against treating "nobody" as an author — is
  covered by a plain test run.
- `tests/HandlerBehaviourTest.php` runs the code XenForo actually calls.
  `AuthorshipLogging` needs no XFCP proxy, only a base class declaring the two
  methods it overrides, so a stub entity, a stub member and a spy handler are
  enough to assert what the source text cannot: that a withheld decision never
  reaches the handler underneath, and that a handler answering "no" is still
  obeyed. It covers `ContentAuthor` and `HandlerCoverage` the same way.
- `tests/WiringTest.php` pins what CI cannot execute: the eight class-extension
  registrations and their `_output` copies, both overrides and the shape of each,
  the eight subclasses that compose the trait, and the verification command.

### Re-run on a dev stack after a XenForo, NF/Tickets or NF/Calendar upgrade

The verification command covers all of it, and it is faster than the list. If
you would rather check by hand, these are the four that matter and none of them
are visible to CI:

1. Every registered content type resolves to a handler carrying the trait. This
   is the check most likely to break and the one CI is blindest to.
2. A member with a moderation permission and no moderator record sticks a thread
   and a `stick` entry appears.
3. That member edits their own post and nothing appears.
4. A member who holds a moderator record moderates their own content and logs
   exactly as they did before.

## Addon info

| Field | Value |
|---|---|
| Addon ID | `Cav7/ModeratorLogPatch` |
| Namespace | `Cav7\ModeratorLogPatch` |
| Version | 1.0.0 (`1000070`) |
| Developer | Cav7 |

## License

See [LICENSE](LICENSE).

## Provenance

Written in this repo for issue #187. It was not imported from another
repository.
