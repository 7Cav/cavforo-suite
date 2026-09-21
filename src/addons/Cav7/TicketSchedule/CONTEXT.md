# Cav7/TicketSchedule

Opens an NF/Tickets ticket on a calendar cadence, so recurring work such as a
yearly review has a ticket waiting on the day rather than depending on someone
remembering. Schedules are written and edited in the ACP. For cross-addon terms,
see the suite-wide [CONTEXT.md](../../../../CONTEXT.md).

## Language

**ticket schedule**:
One record that names a ticket to open and the cadence to open it on. It carries
the ticket's title, first message and NF/Tickets category. Anything else the
ticket ends up with, such as priority or assignee, comes from the category's own
defaults, not from the schedule.
_Avoid_: recurring ticket (the ticket is not recurring, the schedule is), job,
cron (the cron is the one clock that reads every schedule, not a schedule)

**cadence**:
How a ticket schedule repeats: a start date plus a unit of yearly, monthly,
weekly or every N days. Each due date is worked out from the previous due date,
never from when the ticket was actually opened, so a late run does not shift
every later date.
_Avoid_: interval (reads as elapsed time since the last opening, which is the
shape this is not), frequency

**start date**:
The date a ticket schedule's cadence counts from. It may be in the past, and a
past one is an anchor rather than a missed due date, so it opens nothing on its
own. It fixes the day each due date lands on; a month too short for that day
uses its last day and keeps the anchor for the month after.
_Avoid_: anchor (what it does, not its name), first due date (the first due date
is the first cadence date on or after today, which may be later)

**inactive**:
A ticket schedule the cron skips. Turning it active again recomputes the due
date from the start date, with no ticket for the time it was off.
_Avoid_: paused, disabled, suspended

**due date**:
The calendar day, in the board's timezone, on which a ticket schedule next opens
its ticket. A schedule has exactly one. When more than one has gone by without a
ticket, one ticket opens and the due date moves past today, so a long outage
yields one late ticket rather than a pile.
_Avoid_: next run (the cron runs hourly regardless), fire date, trigger

**opener**:
The XenForo user a scheduled ticket is opened as. One user for the whole board,
not one per schedule.
_Avoid_: bot (names a use, not the role), author
