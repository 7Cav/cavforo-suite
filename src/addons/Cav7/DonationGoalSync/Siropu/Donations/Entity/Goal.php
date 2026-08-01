<?php

namespace Cav7\DonationGoalSync\Siropu\Donations\Entity;

use Cav7\DonationGoalSync\RecurringSchedule;

/**
 * Extends Siropu\Donations\Entity\Goal.
 *
 * One method, and only for goals configured to reset on the 1st of the month;
 * every other shape is handed back to the vendor, which keeps this override's
 * blast radius to the one configuration it is about. What the vendor answered
 * instead, why it was wrong, and why the clock read here is \XF::$time rather
 * than the vendor's wall clock:
 * docs/adr/0001-correct-the-reset-threshold-rather-than-the-clock.md.
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

        // \XF::$time rather than the wall clock the vendor reads, so the
        // instant this decision is made against is the same one
        // resetRecurringGoal() then stores as the new cycle start.
        return \XF::$time >= $threshold;
    }
}
