<?php

namespace Cav7\DonationGoalSync;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\AddOn\StepRunnerUninstallTrait;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    // No schema changes: this add-on only recomputes an existing column
    // (xf_siropu_donations_goal.donation_amount) on the recurring goal.
}
