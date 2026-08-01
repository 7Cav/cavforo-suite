# Enlistment Reminder

XenForo add-on for the [7th Cavalry](https://7cav.us) that keeps enlistment
applications from stalling in the queue.

An hourly cron scans the Enlistment Papers forum (node 325 by default). When an
open application has sat past the deadline carrying no processing status prefix,
the add-on posts one brief, neutral note in the thread and sends a direct alert —
rather than a mention in the post — to the clerks responsible for that
application's type, linking straight to the thread. A thread is reminded once and
never again.

A thread counts as picked up only while it carries one of the configured
in-processing prefixes, the statuses RRD moves the queue through: In Progress,
Hold and Approved. Nothing else counts: not a reply, not a pin, and not who is
seated where on the roster today.

Why the alert is not a mention is in
[docs/adr/0001-alert-not-mention.md](docs/adr/0001-alert-not-mention.md); the
domain terms are in [CONTEXT.md](CONTEXT.md).

## What it scans, and what it skips

- Only threads directly in the queue node. The Completed (326) and Denied (327)
  child forums are different nodes and are never touched.
- Only open, visible threads. Sticky status is ignored.
- A thread's primary prefix marks it a standard enlistment or a re-enlistment,
  and each type routes to its own clerk positions; the Senior and Lead clerks sit
  in both sets. A thread whose prefix marks neither type is not a valid
  enlistment: it is skipped, with a log line if it was otherwise due a reminder.
- A processing clerk is any member seated in one of the configured clerk
  positions, held as a primary or a secondary roster seat. Seats are re-read on
  every scan, so they decide who is alerted, never whether a thread is
  un-actioned.

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
| Reminder deadline (hours) | How long an application may sit with no processing status before it is reminded (default 24, minimum 1, maximum 720) |

Do not put the enlistment type prefixes (57, 58) in the in-processing list: they
are not processing statuses, and the run aborts if they are there. **Processing
status** in [CONTEXT.md](CONTEXT.md) is where that distinction is defined.

## When a scan stops, and when it only complains

Rather than treat every application as un-actioned and remind the whole queue —
or go permanently silent — the add-on aborts the run and logs an error when any
of these is true:

- SV/MultiPrefix is not active, its prefix table cannot be read, or the table
  returns nothing at all for a non-empty queue
- the in-processing list is empty, or parses to nothing
- the in-processing list names a prefix that no longer exists
- the in-processing list contains an enlistment type prefix
- both type-prefix lists are empty

One empty type-prefix list is not an abort. The other type still routes and a
collision on its IDs is still caught; only the emptied type goes unreminded, and
it is skipped thread by thread as unrecognized. The run logs the blank option by
name and carries on.

Two faults are reported without stopping the run:

- the queue node ID names no node. An empty queue in a real node stays silent.
- a queue thread carries a prefix listed neither as an in-processing status, nor
  as an enlistment type, nor as a decoration. The unaccounted-for IDs are
  reported once per scan; a board that has not drifted reports nothing.

A deadline outside the 1–720 hour range substitutes the default and says so in
the log, rather than aborting.

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
3. Configure the options above

Run the tests with `tools/run-tests.sh EnlistmentReminder`. They need only `php`.
Everything that needs a live XenForo is verified by hand on a dev stack before a
release, per [CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## License

[MIT](LICENSE) © 2026 7Cav

## Provenance

Created in this repository for issue #75. No upstream import.
