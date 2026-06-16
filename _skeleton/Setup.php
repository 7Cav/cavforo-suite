<?php

namespace Cav7\AddonId;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\AddOn\StepRunnerUninstallTrait;

/**
 * Install, upgrade, and uninstall steps for the addon.
 *
 * Delete this file if the addon has no install state (no tables, options,
 * user fields, and so on). XenForo only runs a setup class when one exists.
 */
class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    // Add steps as needed. Each is a numbered method, for example:
    //
    // public function installStep1(): void
    // {
    //     $this->schemaManager()->createTable('xf_cav7_example', function ($table) {
    //         $table->addColumn('example_id', 'int')->autoIncrement();
    //         $table->addColumn('value', 'varchar', 100);
    //     });
    // }
    //
    // public function uninstallStep1(): void
    // {
    //     $this->schemaManager()->dropTable('xf_cav7_example');
    // }
}
