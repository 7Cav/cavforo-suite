<?php

namespace Cav7\ApiKeyManager;

use XF\Mvc\Entity\Entity;

class Listener
{
    public static function entityPostSave(Entity $entity): void
    {
        if ($entity instanceof \XF\Entity\User)
        {
            self::onUserSave($entity);
            return;
        }
        if ($entity instanceof \Cav7\ApiKeyManager\Entity\ApiKeyScopeDef)
        {
            self::onScopeDefSave($entity);
            return;
        }
    }

    protected static function onUserSave(\XF\Entity\User $user): void
    {
        $changes = $user->getNewValues();
        if (!isset($changes['user_group_id']) && !isset($changes['secondary_group_ids']))
        {
            return;
        }

        /** @var \Cav7\ApiKeyManager\Repository\ApiKey $repo */
        $repo = \XF::repository('Cav7\ApiKeyManager:ApiKey');
        $repo->recomputeScopesForUser((int) $user->user_id);
    }

    protected static function onScopeDefSave(\Cav7\ApiKeyManager\Entity\ApiKeyScopeDef $def): void
    {
        $changes = $def->getNewValues();
        if (!$def->isInsert()
            && !isset($changes['user_group_ids'])
            && !isset($changes['is_active']))
        {
            return;
        }

        // $manual = false so xf:run-jobs (the standard cron) picks it up.
        // Default is true, which would queue it for manual-trigger only.
        \XF::app()->jobManager()->enqueueUnique(
            'cav7_recompute_scopes_all',
            'Cav7\ApiKeyManager:RecomputeKeyScopes',
            ['all' => true],
            false
        );
    }
}
