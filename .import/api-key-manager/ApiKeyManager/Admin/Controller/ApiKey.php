<?php

namespace Cav7\ApiKeyManager\Admin\Controller;

use Cav7\ApiKeyManager\Repository\ApiKey as ApiKeyRepo;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class ApiKey extends \XF\Admin\Controller\AbstractController
{
    protected function preDispatchController($action, ParameterBag $params): void
    {
        $this->assertAdminPermission('user');
    }

    public function actionIndex(): AbstractReply
    {
        $keys = $this->getApiKeyRepo()->findKeysForAdminList()->fetch();

        return $this->view(
            'Cav7\ApiKeyManager:ApiKey\List',
            'cav7_api_key_list',
            ['apiKeys' => $keys]
        );
    }

    public function actionRevoke(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();

        $keyId = $this->filter('key_id', 'uint');
        $key = $this->em()->find('Cav7\ApiKeyManager:ApiKey', $keyId);
        if (!$key)
        {
            return $this->error(\XF::phrase('cav7_api_key_not_found'));
        }

        $key->delete();

        return $this->redirect($this->buildLink('cav7-api-keys'));
    }

    protected function getApiKeyRepo(): ApiKeyRepo
    {
        return $this->repository('Cav7\ApiKeyManager:ApiKey');
    }
}
