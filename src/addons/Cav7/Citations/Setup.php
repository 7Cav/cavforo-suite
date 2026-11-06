<?php

namespace Cav7\Citations;

use Cav7\Citations\Setup\StorageSchema;
use Cav7\Citations\Setup\TemplateSchema;
use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

/**
 * Uninstall has no steps on purpose. The blob table and citation_blob_id are the only map
 * from rows to blobs, and published template versions pin files nothing else names, so they
 * stay, and a reinstall picks them back up.
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

    public function upgrade1010070Step1(): void
    {
        $this->installTemplates();
    }

    private function installTemplates(): void
    {
        TemplateSchema::install($this->schemaManager());
        TemplateSchema::seedPayGrades($this->db());
    }
}
