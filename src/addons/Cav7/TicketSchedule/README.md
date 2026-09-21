# 7Cav - Ticket Schedule

Opens an [NF/Tickets](https://nixfifty.com/products/tickets.2/) ticket on a
calendar cadence, so a piece of work that repeats, such as a yearly review of a
policy, has a ticket waiting on the day rather than depending on someone
remembering. An admin writes a ticket schedule in the ACP: the ticket's title,
its first message, the category it belongs in, a start date and a cadence. An
hourly cron opens one ticket per due date as the board's opener user, and the
category's staff are notified as they are for any other ticket.

The terms this README uses (ticket schedule, cadence, start date, due date,
inactive) are defined in [CONTEXT.md](CONTEXT.md). Opener is defined in the
suite-wide [CONTEXT.md](../../../../CONTEXT.md).

## Requirements

- XenForo 2.3.0+
- NF/Tickets 2.11.1+ (declared in `addon.json`; the floor is the version the
  code was read against, in the vendor's own `version_id` numbering)

## Installation

1. Copy `src/addons/Cav7/TicketSchedule` into your XenForo installation at the
   same path, or install the release zip through the admin panel.
2. Install it: `php cmd.php xf-addon:install Cav7/TicketSchedule`.

The install creates one table, `xf_cav7_ticket_schedule`, and the cron entry
`cav7TSOpenDueTickets`, which runs at the top of every hour and is active from
the start. Uninstalling drops the table and leaves every ticket the addon opened
in place.

One option, under **Setup > Options > Ticket Schedule**: the opener user ID.
Every scheduled ticket is opened as this user. The default is 598, the user
`Cav7/EnlistmentReminder` already posts as. While the ID names no user, the schedule screens
refuse every save and the cron logs one error per run and opens nothing.

## Writing a schedule

The screens sit under **Tickets > Ticket schedules** in the ACP and are open to
anyone holding the vendor's `nfTickets` admin permission. There is no permission
of the addon's own.

A schedule has:

- A title, up to 150 characters, which is what NF/Tickets stores.
- A first message, BB code in a plain textarea.
- A category. The picker lists only categories that allow tickets to be
  opened.
- A start date, which may be in the past. "Every 1 March since 2019" has its
  next ticket on the next 1 March, not today.
- A cadence: yearly, monthly, weekly, or every N days with N from 1 to 366.

Monthly and yearly keep the start date's day of the month. A month too short
for it uses its last day, and the month after returns to the start date's day,
so a schedule on the 31st lands on 28 February and then 31 March. A yearly schedule
on 29 February lands on 28 February in a common year.

The save refuses, with the reason shown, a category the opener may not open a
ticket in, a category that requires a prefix and has no default prefix, and an
opener user ID that names no user. Each of these would otherwise fail on the
due date, a year later.

The list shows each schedule's due date and links to the last ticket it opened.
The toggle in each row sets a schedule inactive or active without deleting it.
An inactive schedule opens nothing; setting it active again counts the due date
from the start date, with no ticket for the time it was off. Editing the start
date or cadence recomputes the due date the moment you save. Editing only the
title or message does not.

## What the cron does

Every hour, for each active schedule whose due date is today or earlier in the
board's timezone, the cron opens one ticket in the schedule's category as the
opener, with the category's default prefix, priority and status. It then moves
the due date past today, records the ticket, and sends the vendor's own
notifications. Several missed due dates, after an outage or a long spell
inactive, yield one ticket, not one per missed date.

A ticket that cannot be opened, because the category has since been closed for
opening or the opener has lost permission there, leaves one error in the
XenForo error log naming the schedule. The due date is unchanged, so the next
run retries. The schedule stays active and nobody else is told; reading the
error log is how you find out.

A ticket that opened but whose notifications failed, because a mailer or a
notifier from another addon threw, leaves one error naming the schedule and
the ticket. The ticket stands and the due date has moved on, so no second
ticket opens for it.

## Tests

Run them with `tools/run-tests.sh TicketSchedule`. They need only `php` and
cover `Cadence`, the calendar arithmetic. Everything that needs a live XenForo
is verified by hand on a dev stack before a release, per
[CONTRIBUTING.md](../../../../CONTRIBUTING.md).

## License

See [LICENSE](LICENSE).

## Provenance

Written directly in this repo for issue #283. It was not imported from another
repository, so it has no history before its first commit here.
