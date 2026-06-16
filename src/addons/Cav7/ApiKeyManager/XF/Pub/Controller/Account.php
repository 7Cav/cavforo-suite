<?php

namespace Cav7\ApiKeyManager\XF\Pub\Controller;

use Cav7\ApiKeyManager\Repository\ApiKey as ApiKeyRepo;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class Account extends XFCP_Account
{
    public function actionApiKey(): AbstractReply
    {
        $visitor = \XF::visitor();
        $key = $this->getApiKeyRepo()->getKeyForUser($visitor->user_id);

        $view = $this->view(
            'Cav7\ApiKeyManager:Account\ApiKey',
            'cav7_api_key_account',
            ['apiKey' => $key]
        );
        return $this->addAccountWrapperParams($view, 'cav7_api_key');
    }

    public function actionApiKeyCreate(): AbstractReply
    {
        $this->assertPostOnly();
        $visitor = \XF::visitor();
        $repo = $this->getApiKeyRepo();

        if ($repo->getKeyForUser($visitor->user_id))
        {
            return $this->error(\XF::phrase('cav7_api_key_already_exists'));
        }

        $result = $repo->createKeyForUser($visitor->user_id);
        \XF::session()->set('cav7_api_key_new', $result['raw']);

        return $this->redirect($this->buildLink('account/api-key-new-key'));
    }

    public function actionApiKeyRotate(): AbstractReply
    {
        $this->assertPostOnly();
        $visitor = \XF::visitor();
        $repo = $this->getApiKeyRepo();

        $key = $repo->getKeyForUser($visitor->user_id);
        if (!$key)
        {
            return $this->error(\XF::phrase('cav7_api_key_not_found'));
        }

        $rawKey = $repo->rotateKeyForUser($key);
        \XF::session()->set('cav7_api_key_new', $rawKey);

        return $this->redirect($this->buildLink('account/api-key-new-key'));
    }

    public function actionApiKeyRevoke(): AbstractReply
    {
        $this->assertPostOnly();
        $visitor = \XF::visitor();
        $repo = $this->getApiKeyRepo();

        $key = $repo->getKeyForUser($visitor->user_id);
        if ($key)
        {
            $key->delete();
        }

        return $this->redirect($this->buildLink('account/api-key'));
    }

    public function actionApiKeyNewKey(): AbstractReply
    {
        $rawKey = \XF::session()->get('cav7_api_key_new');
        if (!$rawKey)
        {
            return $this->redirect($this->buildLink('account/api-key'));
        }

        \XF::session()->remove('cav7_api_key_new');

        $view = $this->view(
            'Cav7\ApiKeyManager:Account\ApiKeyNewKey',
            'cav7_api_key_account_newkey',
            ['rawKey' => $rawKey]
        );
        return $this->addAccountWrapperParams($view, 'cav7_api_key');
    }

    protected function getApiKeyRepo(): ApiKeyRepo
    {
        return $this->repository('Cav7\ApiKeyManager:ApiKey');
    }
}
