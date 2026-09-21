<?php

namespace Cav7\TicketSchedule\Cron;

use Cav7\TicketSchedule\TicketOpener;

/**
 * The hourly cron entry point. Kept thin: the work is in TicketOpener, and
 * the calendar rule it applies is in Cadence.
 */
class OpenDueTickets
{
    public static function run(): void
    {
        (new TicketOpener())->openDue();
    }
}
