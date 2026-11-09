<?php

namespace Cav7\Citations;

use Cav7\Citations\Setup\GrantSchema;
use Cav7\Citations\Setup\StorageSchema;
use Cav7\Citations\Setup\TemplateSchema;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

/**
 * Uninstall has no steps on purpose. The blob table and citation_blob_id are the only map
 * from rows to blobs, published template versions pin files nothing else names, and grants
 * are the record, so they stay, and a reinstall picks them back up.
 */
class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1(): void
    {
        StorageSchema::install($this->schemaManager());
    }

    public function installStep2(): void
    {
        $this->installTemplates();
    }

    public function installStep3(): void
    {
        GrantSchema::install($this->schemaManager());
        TemplateSchema::backfillFirstUse($this->db());
    }

    public function upgrade1010070Step1(): void
    {
        $this->installTemplates();
    }

    public function upgrade1010070Step2(): void
    {
        GrantSchema::install($this->schemaManager());
    }

    public function upgrade1020070Step1(): void
    {
        TemplateSchema::install($this->schemaManager());
        TemplateSchema::backfillFirstUse($this->db());
    }

    private function installTemplates(): void
    {
        TemplateSchema::install($this->schemaManager());
        TemplateSchema::seedPayGrades($this->db());
    }
}
