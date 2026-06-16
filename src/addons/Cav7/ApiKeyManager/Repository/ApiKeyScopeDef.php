<?php

namespace Cav7\ApiKeyManager\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class ApiKeyScopeDef extends Repository
{
    public function findScopesForList(): Finder
    {
        return $this->finder('Cav7\ApiKeyManager:ApiKeyScopeDef')
            ->order(['display_order', 'scope_name']);
    }

    public function findActiveScopes(): Finder
    {
        return $this->finder('Cav7\ApiKeyManager:ApiKeyScopeDef')
            ->where('is_active', 1)
            ->order(['display_order', 'scope_name']);
    }

    public function findByName(string $name): ?\Cav7\ApiKeyManager\Entity\ApiKeyScopeDef
    {
        /** @var \Cav7\ApiKeyManager\Entity\ApiKeyScopeDef|null $def */
        $def = $this->finder('Cav7\ApiKeyManager:ApiKeyScopeDef')
            ->where('scope_name', $name)
            ->fetchOne();
        return $def;
    }
}
