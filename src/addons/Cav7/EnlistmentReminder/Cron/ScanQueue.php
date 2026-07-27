<?php

namespace Cav7\EnlistmentReminder\Cron;

use Cav7\EnlistmentReminder\QueueReminder;

/**
 * The hourly cron entry point. It reads the deadline option, clamps it to a
 * sane range at both ends, and hands the scan to QueueReminder. Kept thin so the
 * reminder logic itself stays in QueueReminder (XenForo-coupled work) and
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

    /**
     * The ceiling the configured deadline is clamped to: 30 days (issue #191).
     * The floor exists because too small a value reminds everything at once; this
     * exists because too large a value reminds nothing, ever, and that failure is
     * the quieter of the two.
     *
     * The option is an unsigned_integer spinbox whose min=1 is an HTML attribute
     * and therefore client-side only, with no validation_class behind it, so a
     * mistyped value reaches this method intact.
     *
     * 30 days is chosen against the slip this is for — extra zeros — rather than
     * against a calendar. The queue's own SLA is 48 hours (see CONTEXT.md), so no
     * usable deadline comes close to the bound and nothing real is refused, while
     * `2400` and `240000` typed for `24` are both caught. A year was the first
     * choice and is too loose to be worth having: the oldest open application in
     * the queue is a fortnight old, so anything past roughly 350 hours already
     * selects nothing, and `2400` would have sailed through reminding nobody.
     *
     * What no fixed bound can catch is a value that is wrong but inside it — 400
     * hours, say. That residue is why this is mitigation rather than a fix, and it
     * is what the deferred liveness signal in issue #191 would close.
     */
    const MAX_DEADLINE_HOURS = 720;

    public static function run(): void
    {
        $hours = (int) (\XF::options()->cav7ERDeadlineHours ?? self::DEFAULT_DEADLINE_HOURS);

        // Loud substitution (mirrors RosterAudit's AuditLogPrune): an admin who
        // set an unusable deadline needs to know the reminder is running on the
        // safe default instead of on whatever they typed.
        //
        // Both bounds substitute rather than abort, and that is the point: an
        // out-of-range deadline still leaves a queue that needs scanning, so the
        // run continues on a value that works. Aborting would turn a mistyped
        // number into exactly the silent outage this guard exists to prevent.
        if ($hours < self::MIN_DEADLINE_HOURS)
        {
            \XF::logError(sprintf(
                'cav7ERDeadlineHours=%d is below the %d-hour minimum; using the %d-hour default instead',
                $hours, self::MIN_DEADLINE_HOURS, self::DEFAULT_DEADLINE_HOURS
            ));
            $hours = self::DEFAULT_DEADLINE_HOURS;
        }
        elseif ($hours > self::MAX_DEADLINE_HOURS)
        {
            \XF::logError(sprintf(
                'cav7ERDeadlineHours=%d is above the %d-hour maximum; using the %d-hour default instead. Nothing would ever have been past a deadline that far out, so the reminder would have gone silent with no other symptom.',
                $hours, self::MAX_DEADLINE_HOURS, self::DEFAULT_DEADLINE_HOURS
            ));
            $hours = self::DEFAULT_DEADLINE_HOURS;
        }

        (new QueueReminder())->remind($hours);
    }
}
