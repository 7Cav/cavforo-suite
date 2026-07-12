<?php

namespace Cav7\EnlistmentReminder;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    /**
     * The marker table: one row per thread the reminder has already posted a
     * note on. Its presence is the "already reminded" signal the decision reads,
     * so a thread is reminded exactly once however many times the cron runs. The
     * table is owned entirely by this add-on, so it is created on install and
     * dropped on uninstall with nothing left behind.
     */
    public function installStep1(): void
    {
        $this->schemaManager()->createTable('xf_cav7_enlistment_reminder', function (Create $table)
        {
            $table->addColumn('thread_id', 'int');
            $table->addColumn('reminded_date', 'int');
            $table->addPrimaryKey('thread_id');
            $table->addKey('reminded_date');
        });
    }

    public function uninstallStep1(): void
    {
        $this->schemaManager()->dropTable('xf_cav7_enlistment_reminder');
    }
}
