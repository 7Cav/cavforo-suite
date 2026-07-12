<?php

namespace Cav7\EnlistmentReminder\Cron;

use Cav7\EnlistmentReminder\QueueReminder;

/**
 * The hourly cron entry point. It reads the deadline option, clamps it to a
 * sane floor, and hands the scan to QueueReminder. Kept thin so the reminder
 * logic itself stays in QueueReminder (XenForo-coupled work) and
 * ReminderDecision (the pure rule).
 */
class ScanQueue
{
    const DEFAULT_DEADLINE_HOURS = 24;

    /**
     * The floor the configured deadline is clamped to. A deadline of 0 or a
     * negative value would make every open application "past deadline" at once
     * and remind the whole queue, so anything below this falls back to the
     * default with a logged substitution rather than firing on everything.
     */
    const MIN_DEADLINE_HOURS = 1;

    public static function run(): void
    {
        $hours = (int) (\XF::options()->cav7ERDeadlineHours ?? self::DEFAULT_DEADLINE_HOURS);

        // Loud substitution (mirrors RosterAudit's AuditLogPrune): an admin who
        // set an unusable deadline needs to know the reminder is running on the
        // safe default instead of on whatever they typed.
        if ($hours < self::MIN_DEADLINE_HOURS)
        {
            \XF::logError(sprintf(
                'cav7ERDeadlineHours=%d is below the %d-hour minimum; using the %d-hour default instead',
                $hours, self::MIN_DEADLINE_HOURS, self::DEFAULT_DEADLINE_HOURS
            ));
            $hours = self::DEFAULT_DEADLINE_HOURS;
        }

        (new QueueReminder())->remind($hours);
    }
}
