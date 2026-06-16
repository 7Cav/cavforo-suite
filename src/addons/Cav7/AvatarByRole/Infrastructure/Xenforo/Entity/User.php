<?php

namespace Cav7\AvatarByRole\Infrastructure\Xenforo\Entity;

class User extends XFCP_User
{
    /**
     * Avatars are forced to the member's rank image by the templater listener,
     * so an uploaded avatar never shows. Denying the upload outright keeps the
     * editor hidden and stops dead files reaching the server: XenForo gates the
     * avatar editor, the account/avatar action, and the API avatar endpoint on
     * this single method.
     */
    public function canUploadAvatar()
    {
        return false;
    }
}
