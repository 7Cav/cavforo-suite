<?php

namespace Cav7\AvatarByRole\Infrastructure\Xenforo\Pub\Controller;

use XF\Mvc\ParameterBag;

class Account extends XFCP_Account
{
    private function denyAvatarChanges()
    {
        // If you want to allow admins only:
        // if (\XF::visitor()->is_admin) return;

        throw $this->exception($this->noPermission());
    }

    public function actionAvatar(ParameterBag $params)
    {
        // Allow viewing the page (optional).
        // If you’d rather hide the whole page, call denyAvatarChanges() here too.
        $reply = parent::actionAvatar($params);

        // If it’s a View reply, you can also strip UI flags:
        if ($reply instanceof \XF\Mvc\Reply\View) {
            $reply->setParam('canUploadAvatar', false);
            $reply->setParam('canDeleteAvatar', false);
        }

        return $reply;
    }

    public function actionAvatarUpload(ParameterBag $params)
    {
        $this->denyAvatarChanges();
    }
    public function actionAvatarUrl(ParameterBag $params)
    {
        $this->denyAvatarChanges();
    }
    public function actionAvatarCrop(ParameterBag $params)
    {
        $this->denyAvatarChanges();
    }
    public function actionAvatarDelete(ParameterBag $params)
    {
        $this->denyAvatarChanges();
    }
}
