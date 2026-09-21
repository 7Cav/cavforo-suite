<?php

namespace Cav7\TicketWebhook;

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
     * One row per hook. The table is this add-on's alone, so it is created on
     * install and dropped on uninstall. The tickets a hook opened live in
     * NF/Tickets' own tables and stay where they are.
     *
     * token_hash is the 32 bytes Token::hash() returns. The token itself is
     * never stored.
     */
    public function installStep1(): void
    {
        $this->schemaManager()->createTable('xf_cav7_ticket_webhook_hook', function (Create $table)
        {
            $table->addColumn('hook_id', 'int')->autoIncrement();
            $table->addColumn('name', 'varchar', 150);
            $table->addColumn('ticket_category_id', 'int');
            $table->addColumn('active', 'tinyint', 3)->setDefault(1);
            $table->addColumn('token_hash', 'varbinary', 32);
            $table->addColumn('created_date', 'int');
            $table->addColumn('last_used_date', 'int')->setDefault(0);
            $table->addColumn('last_ticket_id', 'int')->setDefault(0);
        });
    }

    public function uninstallStep1(): void
    {
        $this->schemaManager()->dropTable('xf_cav7_ticket_webhook_hook');
    }
}
