<?php

namespace Cav7\AvatarByRole;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function install(array $stepParams = [])
    {
        $this->createUserField('cav7_opt_out_mourning', [
            'display_group'       => 'preferences',
            'display_order'       => 100,
            'field_type'          => 'checkbox',
            'match_type'          => 'none',
            'max_length'          => 0,
            'required'            => false,
            'show_registration'   => false,
            'user_editable'       => 'yes',
            'viewable_profile'    => false,
            'viewable_message'    => false,
            'moderator_editable'  => false,
            'field_choices'      => ['1' => 'Yes'],
        ]);

        $sourceDir = $this->addOn->getAddOnDirectory() . '/Resources/images';
        $destDir = \XF::getRootDirectory() . '/styles/default/xenforo/avatars';

        if (!is_dir($sourceDir)) {
            throw new \RuntimeException("Source image directory not found: $sourceDir");
        }

        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new \RuntimeException("Failed to create destination directory: $destDir");
            }
        }

        foreach (glob($sourceDir . '/*') as $sourceFile) {
            $filename = basename($sourceFile);
            $destFile = $destDir . '/' . $filename;
            if (!copy($sourceFile, $destFile)) {
                throw new \RuntimeException("Failed to copy $filename to $destDir");
            }
        }
    }

    public function uninstall(array $stepParams = [])
    {
        $this->deleteUserField('cav7_opt_out_mourning');

        $destDir = \XF::getRootDirectory() . '/styles/default/xenforo/avatars';

        if (is_dir($destDir)) {
            $files = glob($destDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($destDir);
        }
    }

    protected function createUserField(string $fieldId, array $config): void
    {
        $em = $this->app->em();

        if ($em->find('XF:UserField', $fieldId)) {
            return;
        }

        $field = $em->create('XF:UserField');
        $field->field_id = $fieldId;

        foreach ($config as $k => $v) {
            $field->{$k} = $v;
        }

        $field->save();
    }

    protected function deleteUserField(string $fieldId): void
    {
        $em = $this->app->em();

        $field = $em->find('XF:UserField', $fieldId);
        if ($field) {
            $field->delete();
        }
    }
}
