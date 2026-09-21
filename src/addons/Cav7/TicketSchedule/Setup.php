<?php

namespace Cav7\TicketSchedule;

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
     * One row per ticket schedule. The table is this add-on's alone, so it is
     * created on install and dropped on uninstall. The tickets it opened live
     * in NF/Tickets' own tables and stay where they are.
     *
     * start_date and due_date are DATE columns: a calendar day with no time and
     * no zone, which is what Cadence works in.
     */
    public function installStep1(): void
    {
        $this->schemaManager()->createTable('xf_cav7_ticket_schedule', function (Create $table)
        {
            $table->addColumn('schedule_id', 'int')->autoIncrement();
            $table->addColumn('title', 'varchar', 150);
            $table->addColumn('message', 'mediumtext');
            $table->addColumn('ticket_category_id', 'int');
            $table->addColumn('start_date', 'date');
            $table->addColumn('cadence_unit', 'varchar', 10);
            $table->addColumn('cadence_every', 'smallint')->setDefault(1);
            $table->addColumn('active', 'tinyint', 3)->setDefault(1);
            $table->addColumn('due_date', 'date');
            $table->addColumn('last_ticket_id', 'int')->setDefault(0);
            $table->addColumn('last_ticket_date', 'int')->setDefault(0);
            $table->addKey(['active', 'due_date']);
        });
    }

    public function uninstallStep1(): void
    {
        $this->schemaManager()->dropTable('xf_cav7_ticket_schedule');
    }
}
