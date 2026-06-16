<?php

namespace Cav7\AvatarByRole\Infrastructure\Xenforo\Service\User;

use XF\Http\Upload;
use XF\PrintableException;

class Avatar extends XFCP_Avatar
{
    private function deny(): void
    {
        throw new PrintableException(\XF::phrase('no_permission'));
    }

    public function setImageFromUpload(Upload $upload)
    {
        $this->deny();
    }

    public function setImageFromPath($path)
    {
        $this->deny();
    }
}
