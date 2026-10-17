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

    public function installStep2(): void
    {
        $this->createAlertKeyTable();
    }

    /**
     * 1.1.0 adds the alert key table. Existing tickets get no rows, so nothing
     * changes for a hook until a call carries a key.
     */
    public function upgrade1010070Step1(): void
    {
        $this->createAlertKeyTable();
    }

    public function uninstallStep1(): void
    {
        $this->schemaManager()->dropTable('xf_cav7_ticket_webhook_hook');
    }

    public function uninstallStep2(): void
    {
        $this->schemaManager()->dropTable('xf_cav7_ticket_webhook_alert_key');
    }

    /**
     * One row per hook and alert key, naming the ticket the key links. The
     * key is stored only as the 32 bytes HookCall hashes it to, because
     * healthchecks.io's natural key is also the secret that pings a check.
     * Deleting a ticket leaves its row; the next call finds no ticket and
     * replaces the row.
     */
    protected function createAlertKeyTable(): void
    {
        $this->schemaManager()->createTable('xf_cav7_ticket_webhook_alert_key', function (Create $table)
        {
            $table->addColumn('hook_id', 'int');
            $table->addColumn('key_hash', 'varbinary', 32);
            $table->addColumn('ticket_id', 'int');
            $table->addPrimaryKey(['hook_id', 'key_hash']);
        });
    }
}
