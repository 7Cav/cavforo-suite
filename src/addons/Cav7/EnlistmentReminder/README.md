# Enlistment Reminder

XenForo add-on for the [7th Cavalry](https://7cav.us) that keeps enlistment
applications from stalling in the queue.

An hourly cron scans the Enlistment Papers forum (node 325). When an open
application has sat past the deadline carrying no processing status prefix, the
add-on posts one brief, neutral note in the thread and records it so the same
thread is never reminded twice. RRD works the queue by moving a thread through
In Progress, Hold and Approved, and any of those means someone has taken the
application on. Nothing else counts: not a reply, not a pin, and not who is
seated where on the roster today.

Alongside the note, the add-on sends a direct alert to the clerks responsible for
the application's type (rather than tagging anyone in the post), linking straight
to the thread. A thread's primary prefix marks it a standard enlistment or a
re-enlistment, and each type routes to its own clerks; the Senior and Lead clerks
sit in both sets. Sending an alert rather than a mention is explained in
[docs/adr/0001-alert-not-mention.md](docs/adr/0001-alert-not-mention.md); the
domain terms are in [CONTEXT.md](CONTEXT.md).

## How it works

- Whether an application has been picked up is read from its status prefixes,
  which SV/MultiPrefix stores in its own thread-prefix link table. Membership in
  the configured in-processing set is the test.
- A processing clerk is any member seated in one of the configured clerk
  positions, held as a primary or a secondary roster seat. Clerk seats decide who
  is alerted, not whether a thread is un-actioned: they are re-read on every
  scan, so a rule built on them would undo itself whenever a clerk rotated out.
  For the alert the positions split by type, a standard enlistment and a
  re-enlistment each having their own clerk set, with the Senior and Lead clerks
  in both.
- A thread's enlistment type is read from its primary prefix, which the intake
  form stamps at submission. A thread whose prefix marks neither type is not a
  valid enlistment; it is skipped, with a log line if it was otherwise due a
  reminder.
- Only threads directly in the queue node are scanned. The Completed (326) and
  Denied (327) child forums are different nodes and are never touched.
- Only open, visible threads are considered. Sticky status is ignored: a thread
  is pinned once it reaches Approved, long after a reminder would have been due.
- The decision of which threads to remind is a pure unit
  (`ReminderDecision.php`) with no XenForo dependency, so the deadline boundary,
  the status-prefix suppression, and the once-only guard are unit-tested in plain
  PHP. Reading a status off a thread's prefixes (`ProcessingStatus.php`) and the
  type-to-clerks routing (`EnlistmentRouting.php`) are two more pure units,
  tested the same way.

## Requirements

- XenForo 2.3.0+
- NF/Rosters (the reminder reads roster positions to decide which clerks to
  alert)
- SV/MultiPrefix (the reminder reads processing status prefixes from its
  thread-prefix link table)
- A XenForo user account for the bot to post the note as

## Installation

1. Copy `src/addons/Cav7/EnlistmentReminder` into your XenForo installation at
   `src/addons/Cav7/EnlistmentReminder`
2. Install the add-on: **Admin CP → Add-ons → Enlistment Reminder → Install**
   (or `php cmd.php xf-addon:install Cav7/EnlistmentReminder`)
3. Configure the options below

## Configuration

All options live under **Admin CP → Options → Enlistment Reminder**:

| Option | Description |
| --- | --- |
| Enlistment queue node ID | Forum node whose open applications are scanned (default 325) |
| Bot user ID | XenForo user the reminder note is posted as (default 598) |
| Standard enlistment prefix IDs | Thread prefix IDs that mark a standard enlistment (default 57) |
| Standard enlistment clerk position IDs | Roster positions alerted about un-actioned standard enlistments (default 579, 580, 751, 1012) |
| Re-enlistment prefix IDs | Thread prefix IDs that mark a re-enlistment (default 58) |
| Re-enlistment clerk position IDs | Roster positions alerted about un-actioned re-enlistments (default 579, 960, 1012) |
| In-processing prefix IDs | Thread prefix IDs that mean a clerk has taken the application on (default 53, 54, 55 for Hold, Approved, In Progress) |
| Decorative prefix IDs | Thread prefix IDs that ride alongside a status without being one (default 66, 68, 110 for S1, RTC, "!!!"). Nothing routes or suppresses on them; they are listed so the add-on can tell a prefix it has no opinion about from one nobody has told it about yet |
| Reminder deadline (hours) | How long an application may sit with no processing status before it is reminded (default 24, minimum 1, maximum 8760) |

Do not put the enlistment type prefixes (57, 58) in the in-processing list: they
are not processing statuses, and listing them would stop the reminder firing at
all. **Processing status** in [CONTEXT.md](CONTEXT.md) is where that distinction
is defined.

Rather than treat every application as un-actioned and remind the whole queue,
the add-on aborts the run and logs an error when any of these is true:

- SV/MultiPrefix is not active (disabling it leaves its prefix table in place but
  nobody maintaining it, which is the one an admin is most likely to hit)
- its prefix table cannot be read, or returns nothing at all for a non-empty queue
- the in-processing list is empty, or parses to nothing
- the in-processing list names a prefix that no longer exists. Deleting a thread
  prefix in the ACP also deletes every thread's link to it, so the applications
  that carried it keep their type prefix and lose only the mark saying a clerk had
  them in hand — they read as untouched and would all be chased at once

Two more aborts run the other way, and stop the add-on going silent rather than
loud:

- the in-processing list contains an enlistment type prefix. Every application in
  the queue carries one, so the whole queue would read as handled and nothing
  would ever be reminded or logged.
- both type-prefix lists are empty. Nothing can route as an enlistment at all,
  and the check above has nothing left to compare against, so the same silencing
  would get through unnoticed.

One empty type-prefix list is not an abort. The check above compares against both
lists together, so the other type still routes and a collision on its ids is still
caught; only the emptied type goes unreminded, and it is skipped thread by thread
as unrecognized. The run logs the blank option by name and carries on, so a fault
confined to one type does not silence the other.

Two more faults are reported without stopping the run, because reporting is all
they need:

- the queue node ID names no node. The scan finds nothing and the reminder is off
  permanently, but an empty scan is otherwise indistinguishable from a queue that
  is genuinely clear — so the node is checked, and only a node that does not exist
  is reported. An empty queue in a real node stays silent.
- a queue thread carries a prefix listed neither as an in-processing status, nor
  as an enlistment type, nor as a decoration. Nothing can validate this one: if the
  processing team adds a status and starts using it, the prefix is real and the
  configuration is valid, while threads in that status read as un-actioned. The
  unaccounted-for IDs are reported once per scan so the drift is visible. A board
  that has not drifted reports nothing.

The deadline is clamped at both ends rather than aborting, since an out-of-range
value still leaves a queue that needs scanning. Too small a value would remind
everything at once; too large a value would remind nothing, ever, with no other
symptom. Either substitutes the default and says so.

## License

[MIT](LICENSE) © 2026 7Cav

## Provenance

Created in this repository for issue #75. No upstream import.
