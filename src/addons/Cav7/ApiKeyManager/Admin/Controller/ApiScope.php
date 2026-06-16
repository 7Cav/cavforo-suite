<?php

namespace Cav7\ApiKeyManager\Admin\Controller;

use Cav7\ApiKeyManager\Entity\ApiKeyScopeDef;
use Cav7\ApiKeyManager\Repository\ApiKeyScopeDef as ApiKeyScopeDefRepo;
use XF\Mvc\FormAction;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class ApiScope extends \XF\Admin\Controller\AbstractController
{
    protected function preDispatchController($action, ParameterBag $params): void
    {
        $this->assertAdminPermission('user');
    }

    public function actionIndex(): AbstractReply
    {
        $scopes = $this->getScopeRepo()->findScopesForList()->fetch();

        return $this->view(
            'Cav7\ApiKeyManager:ApiScope\List',
            'cav7_api_scope_list',
            ['scopes' => $scopes]
        );
    }

    public function actionAdd(): AbstractReply
    {
        /** @var ApiKeyScopeDef $scope */
        $scope = $this->em()->create('Cav7\ApiKeyManager:ApiKeyScopeDef');
        return $this->scopeAddEdit($scope);
    }

    public function actionEdit(ParameterBag $params): AbstractReply
    {
        $scope = $this->assertScopeExists($params->scope_id);
        return $this->scopeAddEdit($scope);
    }

    protected function scopeAddEdit(ApiKeyScopeDef $scope): AbstractReply
    {
        /** @var \XF\Repository\UserGroup $userGroupRepo */
        $userGroupRepo = $this->repository('XF:UserGroup');

        return $this->view(
            'Cav7\ApiKeyManager:ApiScope\Edit',
            'cav7_api_scope_edit',
            [
                'scope'      => $scope,
                'userGroups' => $userGroupRepo->getUserGroupTitlePairs(),
            ]
        );
    }

    public function actionSave(ParameterBag $params): AbstractReply
    {
        $this->assertPostOnly();

        if ($params->scope_id)
        {
            $scope = $this->assertScopeExists($params->scope_id);
        }
        else
        {
            /** @var ApiKeyScopeDef $scope */
            $scope = $this->em()->create('Cav7\ApiKeyManager:ApiKeyScopeDef');
        }

        $this->scopeSaveProcess($scope)->run();

        return $this->redirect($this->buildLink('cav7-api-scopes'));
    }

    protected function scopeSaveProcess(ApiKeyScopeDef $scope): FormAction
    {
        $form = $this->formAction();

        $filterMap = [
            'title'         => 'str',
            'description'   => 'str',
            'is_active'     => 'bool',
            'display_order' => 'uint',
        ];

        if (!$scope->exists())
        {
            $filterMap = ['scope_name' => 'str'] + $filterMap;
        }

        $input = $this->filter($filterMap);

        // Assembled separately so basicEntitySave's bulkSet($input) does not
        // overwrite it. Keep user_group_ids out of $filterMap.
        $groupIds = $this->filter('user_group_ids', 'array-uint');
        $scope->user_group_ids = implode(',', array_filter($groupIds));

        $form->basicEntitySave($scope, $input);

        return $form;
    }

    public function actionDelete(ParameterBag $params): AbstractReply
    {
        $scope = $this->assertScopeExists($params->scope_id);

        if ($this->isPost())
        {
            $scope->delete();
            return $this->redirect($this->buildLink('cav7-api-scopes'));
        }

        $viewParams = [
            'scope' => $scope,
        ];
        return $this->view(
            'Cav7\ApiKeyManager:ApiScope\Delete',
            'cav7_api_scope_delete',
            $viewParams
        );
    }

    protected function assertScopeExists(int $scopeId): ApiKeyScopeDef
    {
        /** @var ApiKeyScopeDef|null $scope */
        $scope = $this->em()->find('Cav7\ApiKeyManager:ApiKeyScopeDef', $scopeId);
        if (!$scope)
        {
            throw $this->exception($this->notFound(\XF::phrase('cav7_api_scope_not_found')));
        }
        return $scope;
    }

    protected function getScopeRepo(): ApiKeyScopeDefRepo
    {
        return $this->repository('Cav7\ApiKeyManager:ApiKeyScopeDef');
    }
}
