# Enlistment Reminder

XenForo add-on for the [7th Cavalry](https://7cav.us) that keeps enlistment
applications from stalling in the queue.

An hourly cron scans the Enlistment Papers forum (node 325). When an open
application has sat past the deadline with no reply from a processing clerk, the
add-on posts one brief, neutral note in the thread and records it so the same
thread is never reminded twice. A reply from a seated clerk is the only signal
that an application has been picked up; a recruiter reply, an applicant reply, or
the bot's own note does not count.

Alongside the note, the add-on sends a direct alert to the clerks responsible for
the application's type (rather than tagging anyone in the post), linking straight
to the thread. A thread's primary prefix marks it a standard enlistment or a
re-enlistment, and each type routes to its own clerks; the Senior and Lead clerks
sit in both sets. Sending an alert rather than a mention is explained in
[docs/adr/0001-alert-not-mention.md](docs/adr/0001-alert-not-mention.md); the
domain terms are in [CONTEXT.md](CONTEXT.md).

## How it works

- A processing clerk is any member seated in one of the configured clerk
  positions, held as a primary or a secondary roster seat. Any one of the
  positions counts as a pickup. For the alert, the positions split by type: a
  standard enlistment and a re-enlistment each have their own clerk set, with the
  Senior and Lead clerks in both.
- A thread's enlistment type is read from its primary prefix, which the intake
  form stamps at submission. A thread whose prefix marks neither type is not a
  valid enlistment; it is skipped, with a log line if it was otherwise due a
  reminder.
- Only threads directly in the queue node are scanned. The Completed (326) and
  Denied (327) child forums are different nodes and are never touched.
- Only open, visible threads are considered. Sticky status is ignored.
- The decision of which threads to remind is a pure unit
  (`ReminderDecision.php`) with no XenForo dependency, so the deadline boundary,
  the clerk-pickup exclusion, and the once-only guard are unit-tested in plain
  PHP. The type-to-clerks routing is a second pure unit
  (`EnlistmentRouting.php`), tested the same way.

## Requirements

- XenForo 2.3.0+
- NF/Rosters (the reminder reads roster positions to tell a clerk from a
  recruiter)
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
| Reminder deadline (hours) | How long an application may sit with no clerk reply before it is reminded (default 24, minimum 1) |

A reply from any clerk in either set counts as the application being picked up, so
pickup coverage is the union of the two lists (the same five seats as before the
type split). Only the alert audience is chosen by type.

## License

[MIT](LICENSE) © 2026 7Cav

## Provenance

Created in this repository for issue #75. No upstream import.
