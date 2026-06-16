<?php

namespace Cav7\RosterAudit;

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

	public function installStep1(): void
	{
		$this->schemaManager()->createTable('xf_cav7_roster_audit_log', function (Create $table)
		{
			$table->addColumn('log_id', 'int')->autoIncrement();
			$table->addColumn('relation_id', 'int')->setDefault(0);
			$table->addColumn('user_id', 'int')->setDefault(0);
			$table->addColumn('username', 'varchar', 50)->setDefault('');
			$table->addColumn('content_type', 'varchar', 50);
			// varchar, not int: holds the audited entity's primary key, which may be
			// non-numeric (e.g. Field#field_id).
			$table->addColumn('content_id', 'varchar', 50);
			$table->addColumn('action', 'varchar', 25);
			$table->addColumn('log_date', 'int');
			$table->addKey(['relation_id', 'log_date'], 'relation_id_date');
			$table->addKey(['content_type', 'content_id'], 'content_type_id');
			$table->addKey('user_id');
			$table->addKey('log_date');
		});
	}

	public function uninstallStep1(): void
	{
		$this->schemaManager()->dropTable('xf_cav7_roster_audit_log');
		// The JSONL detail files under internal_data/cav7_roster_audit are kept
		// on uninstall deliberately: they are audit evidence. Remove them manually
		// if they are no longer needed.
	}
}
