# 7Cav - Moderator Log Patch

Makes the moderator log record what members with moderation permissions actually
do. Extends XenForo's own moderator log handlers, plus those of
[NF Tickets](https://nixfifty.com/products/tickets.5/) and
[NF Calendar](https://nixfifty.com/products/calendar.9/); it changes no vendor
file.

Without it, a moderation action only reached the log when the person taking it
held a **moderator record** — a row in XenForo's moderator table, which is an
appointment rather than a capability. Everyone else's actions went through
normally and wrote nothing: no error, no warning, no entry. On this board the
members who can moderate and the members who hold a record barely overlap, so the
log was recording a small fraction of the moderation happening on the forum and
reading as though the rest had not happened. Every logged action was affected the
same way: locks, prefixes, moves, approvals, soft deletes, thread type changes,
title edits, and the post, profile-post, ticket and calendar equivalents.

Terms used below — moderator record, author-reachable action — are defined in
[CONTEXT.md](CONTEXT.md). The decisions behind the behaviour are in
[docs/adr/](docs/adr/).

## What it changes

Two overrides on the moderator log handler, applied to every registered content
type.

The user-level gate accepts any logged-in member instead of only record holders.
Guests keep failing it, which is what keeps the cron runner and the system actors
that run as a guest out of the log.

The per-action check adds one rule in front of the handler's own. For the
**author-reachable action**s — the ones a member can produce on their own content
with own-content permissions — an entry is written only when the actor is not the
content's author. Which names those are is
`AuthorshipRule::AUTHOR_REACHABLE_ACTIONS`. Everything else is taken to be
unreachable without authority over somebody else's content, so it always logs. A
member who holds a moderator record skips the rule entirely, so their entries are
byte for byte what XenForo wrote before, and every other case is handed to the
handler underneath rather than answered here.

Entries themselves are unchanged. The acting member, their IP, the timestamp,
the content and the URL are all filled in by the same core method that filled
them in for a record holder. They appear in the ACP moderator log and in the
"Moderator actions" view on the thread, and there is nothing to migrate: the
first action after the addon is enabled writes an entry.

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
their own content, is still not logged.** That is the one case an authorship rule
cannot see and a permission rule could. ADR-0001 records why we took the trade.

**A member who trips the spam filter on their own edit is no longer recorded as
having unapproved their own post.** The spam check runs during the member's own
save and sends the content back to the queue, which resolves to `unapprove`.

**A member clearing somebody else's post off their own profile now writes an
entry**, where nothing was written before.

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
  per type when it is not. A key that does not name a content type this install
  files under a category refuses the run rather than being dropped, and the
  overrides that are used are echoed before the first phase.

It reads the registered handler content types off the install and checks each one
resolves through this addon, then asks each handler's two gates the questions the
rule is made of, then moderates a throwaway thread — sticking, retitling and
unsticking it — and checks which of those landed in `xf_moderator_log` and that
the entry is reachable from the thread's own moderator actions view. It deletes
the thread and the rows afterwards, and the middle phase writes nothing.

A PASS tagged `[board sample]`, `[unscoped sample]` or `[fabricated sample]` was
earned somewhere other than where you pointed the run. Expect `[unscoped sample]`
on most content types: neither argument can narrow a type filed under neither a
node nor a category, so the newest row of it anywhere on the board is all there
is to read, and the tag is the command saying so rather than a fault.

## Requirements

XenForo 2.3+, and nothing else. The extensions against the ticket and calendar
handlers install and sit inert when those addons are absent, because XenForo
checks that the extending class exists and not the extended one.

## Installation

1. Copy `src/addons/Cav7/ModeratorLogPatch` into your XenForo installation at the
   same path.
2. Install the addon: `php cmd.php xf-addon:install Cav7/ModeratorLogPatch`.
3. Confirm it is in force with the verification command above.

Run the tests with `tools/run-tests.sh ModeratorLogPatch`. They need only `php`.
Everything that needs a live XenForo is verified by hand on a dev stack before a
release, per [CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## Reverting

Disable the addon. Everything it adds is a class extension, so the log is back
to its previous behaviour on the next action. It creates no tables, options or
fields, and there is no `Setup.php` to unwind. Entries already written stay
written.

## License

See [LICENSE](LICENSE).

## Provenance

Written in this repo for issue #187. It was not imported from another
repository.
