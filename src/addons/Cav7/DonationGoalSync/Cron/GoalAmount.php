<?php

namespace Cav7\DonationGoalSync\Cron;

/**
 * Recomputes the recurring donation goal's stored "received" amount
 * (xf_siropu_donations_goal.donation_amount) from the source of truth:
 * completed donations since the current cycle start, folding in any
 * donation that isn't attributable to another real goal -- i.e. the
 * recurring goal itself, plus "orphans" whose donation_goal_id points
 * to no existing goal row. That covers both goal_id = 0 (the Siropu
 * donate flow failed to attach a goal) and legacy recurring PayPal
 * subscriptions that carry a now-deleted goal id (the renewal IPN clones
 * the original subscription donation, reusing its stale goal id verbatim).
 *
 * Donations attached to a goal row that still EXISTS -- even if it is
 * disabled -- are left alone, so a closed campaign (e.g. a Member-in-Need
 * drive) is never swept into the recurring total. Goal ids are AUTO_INCREMENT
 * and never reused, so a deleted id stays a permanent orphan.
 *
 * Designed to fail safe: if the Siropu add-on is missing/disabled, or its
 * schema no longer matches what we depend on, or no recurring goal can be
 * identified, the cron aborts WITHOUT writing anything.
 */
class GoalAmount
{
    public static function recomputeRecurringGoal()
    {
        $app = \XF::app();

        try
        {
            // 1. Abort if the donations add-on isn't active (tables may not exist).
            $activeAddOns = $app->container('addon.cache');
            if (!is_array($activeAddOns) || !isset($activeAddOns['Siropu/Donations']))
            {
                return;
            }

            $em = \XF::em();

            // 2. Verify the schema we depend on still exists. getEntityStructure()
            //    throws if the class is gone; the column checks catch renames.
            $goalStructure     = $em->getEntityStructure('Siropu\Donations:Goal');
            $donationStructure = $em->getEntityStructure('Siropu\Donations:Donation');

            $requiredGoalColumns = ['donation_goal_id', 'start_date', 'donation_amount', 'enabled', 'settings'];
            foreach ($requiredGoalColumns as $column)
            {
                if (!isset($goalStructure->columns[$column]))
                {
                    return; // schema changed — abort
                }
            }

            $requiredDonationColumns = ['donation_goal_id', 'primary_currency_amount', 'donation_date', 'status'];
            foreach ($requiredDonationColumns as $column)
            {
                if (!isset($donationStructure->columns[$column]))
                {
                    return; // schema changed — abort
                }
            }

            // 3. Identify the recurring goal by its setting, not a hardcoded id.
            $goals = $em->getFinder('Siropu\Donations:Goal')
                ->where('enabled', 1)
                ->fetch();

            $recurringGoal = null;
            foreach ($goals as $goal)
            {
                $settings = $goal->settings;

                if (is_array($settings)
                    && !empty($settings['recurring'])
                    && is_array($settings['recurring'])
                    && !empty($settings['recurring']['enabled']))
                {
                    $recurringGoal = $goal;
                    break;
                }
            }

            if (!$recurringGoal)
            {
                return; // no recurring goal to maintain — nothing to do
            }

            $goalId    = (int) $recurringGoal->donation_goal_id;
            $startDate = (int) $recurringGoal->start_date;

            if ($goalId <= 0 || $startDate <= 0)
            {
                return; // unexpected values — abort rather than guess
            }

            // 4. Sum completed donations since the cycle start that belong to
            //    the recurring goal OR are orphaned (their goal_id matches no
            //    existing goal row -- goal_id 0 or a deleted goal). Donations
            //    on a still-existing goal (even disabled) are excluded so other
            //    campaigns are never absorbed. Table names come from the
            //    structure so a future rename can't silently misdirect us.
            $donationTable = $donationStructure->table;
            $goalTable     = $goalStructure->table;

            $sum = $app->db()->fetchOne("
                SELECT COALESCE(SUM(d.primary_currency_amount), 0)
                FROM `" . $donationTable . "` AS d
                WHERE d.status = 'completed'
                  AND d.donation_date >= ?
                  AND (
                        d.donation_goal_id = ?
                     OR d.donation_goal_id NOT IN (
                            SELECT g.donation_goal_id FROM `" . $goalTable . "` AS g
                        )
                  )
            ", [$startDate, $goalId]);

            // 5. Validate before writing.
            if ($sum === null || !is_numeric($sum) || $sum < 0)
            {
                return;
            }

            $sum = round((float) $sum, 2);

            // 6. Only write when it actually differs, to avoid pointless updates.
            if (round((float) $recurringGoal->donation_amount, 2) !== $sum)
            {
                $recurringGoal->fastUpdate('donation_amount', $sum);
            }
        }
        catch (\Throwable $e)
        {
            // Never let this cron throw. Log and bail so a schema change or
            // transient error can't break the scheduled run or the goal data.
            \XF::logException($e, false, '[Cav7/DonationGoalSync] recompute aborted: ');
            return;
        }
    }
}
