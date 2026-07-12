# Enlistment Reminder

XenForo add-on for the [7th Cavalry](https://7cav.us) that keeps enlistment
applications from stalling in the queue.

An hourly cron scans the Enlistment Papers forum (node 325). When an open
application has sat past the deadline with no reply from a processing clerk, the
add-on posts one brief, neutral note in the thread and records it so the same
thread is never reminded twice. A reply from a seated clerk is the only signal
that an application has been picked up; a recruiter reply, an applicant reply, or
the bot's own note does not count.

Sending each clerk a direct alert (rather than tagging them in the post) is a
separate follow-up. The reasoning is in
[docs/adr/0001-alert-not-mention.md](docs/adr/0001-alert-not-mention.md); the
domain terms are in [CONTEXT.md](CONTEXT.md).

## How it works

- A processing clerk is any member seated in one of the configured clerk
  positions, held as a primary or a secondary roster seat. Any one of the
  positions counts.
- Only threads directly in the queue node are scanned. The Completed (326) and
  Denied (327) child forums are different nodes and are never touched.
- Only open, visible threads are considered. Sticky status is ignored.
- The decision of which threads to remind is a pure unit
  (`ReminderDecision.php`) with no XenForo dependency, so the deadline boundary,
  the clerk-pickup exclusion, and the once-only guard are unit-tested in plain
  PHP.

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
| Processing clerk position IDs | Roster position IDs that count as processing clerks, comma-separated (default 579, 580, 751, 960, 1012) |
| Reminder deadline (hours) | How long an application may sit with no clerk reply before it is reminded (default 24, minimum 1) |

## License

[MIT](LICENSE) © 2026 7Cav

## Provenance

Created in this repository for issue #75. No upstream import.
