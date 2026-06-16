<?php

namespace Cav7\AvatarByRole\Infrastructure\Xenforo\Entity;


class User extends XFCP_User
{
    public function canUploadAvatar(&$error = null): bool
    {
        $error = \XF::phrase('no_permission');
        return false;
    }

    public function canDeleteAvatar(&$error = null): bool
    {
        $error = \XF::phrase('no_permission');
        return false;
    }
}
