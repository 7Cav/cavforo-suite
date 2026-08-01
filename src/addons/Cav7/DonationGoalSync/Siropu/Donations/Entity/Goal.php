<?php

namespace Cav7\DonationGoalSync\Siropu\Donations\Entity;

use Cav7\DonationGoalSync\RecurringSchedule;

/**
 * Extends Siropu\Donations\Entity\Goal.
 *
 * One method, and only for goals configured to reset on the 1st of the month;
 * every other shape is handed back to the vendor, which keeps this override's
 * blast radius to the one configuration it is about.
 *
 * The vendor's own threshold inherited the time of day of the previous reset,
 * seconds included, so whether a month's reset happened depended on which
 * second the cron happened to fire at. The README has the full account.
 *
 * @see \Cav7\DonationGoalSync\RecurringSchedule for the arithmetic and its tests
 */
class Goal extends XFCP_Goal
{
    public function canResetRecurringGoal()
    {
        $recurring = $this->settings['recurring'] ?? null;

        if (!$recurring || empty($recurring['1st_day']))
        {
            return parent::canResetRecurringGoal();
        }

        $threshold = RecurringSchedule::firstOfMonthThreshold(
            (int) $this->start_date,
            (int) $recurring['months']
        );

        // \XF::$time rather than the wall clock the vendor reads, so the instant
        // this decision is made against is the same one resetRecurringGoal()
        // then stores as the new cycle start.
        //
        // \XF::$time is frozen at request start, which is safe only because of
        // the gap between the threshold and the cron. The one caller is the
        // siropuDonationsRecurring entry, scheduled at 00:30, and XenForo will
        // not run an entry until \XF::$time has reached its next_run — so by the
        // time this is asked on the 1st, \XF::$time is already half an hour past
        // midnight. Reschedule that entry to 00:00 and the margin is gone: a
        // request starting at 23:59:5x would then read a pre-midnight \XF::$time
        // and defer the reset a full day. Move the cron and switch this back to
        // the wall clock, or do not move the cron.
        return \XF::$time >= $threshold;
    }
}
