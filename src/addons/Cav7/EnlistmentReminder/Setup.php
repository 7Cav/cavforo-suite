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

    /**
     * Issue #144 retired the single cav7ERClerkPositionIds option in favour of
     * four per-type options — the standard and re-enlistment prefix ids and their
     * clerk-position sets. The four replacements are created with their agreed
     * defaults by the add-on's declarative option import, which runs right after
     * these upgrade steps and also drops the retired option as orphaned data. This
     * step removes it (and its option-group relation) explicitly as well, so the
     * retirement is legible in the migration and holds even if the orphan sweep is
     * ever skipped — an admin must never be left with a dead option nothing reads.
     *
     * No value is migrated from the old option: the two new default position sets
     * union to exactly the old default (579,580,751,960,1012), so a default-config
     * install keeps the same pickup coverage without carrying a custom value that
     * a union cannot be un-merged back into two type sets anyway.
     */
    public function upgrade1010070Step1(): void
    {
        $db = $this->db();
        $db->delete('xf_option', 'option_id = ?', ['cav7ERClerkPositionIds']);
        $db->delete('xf_option_group_relation', 'option_id = ?', ['cav7ERClerkPositionIds']);
    }

    public function uninstallStep1(): void
    {
        $this->schemaManager()->dropTable('xf_cav7_enlistment_reminder');
    }
}
