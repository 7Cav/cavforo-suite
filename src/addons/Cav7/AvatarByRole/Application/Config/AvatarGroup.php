<?php

namespace Cav7\AvatarByRole\Application\Config;

class AvatarGroup
{
    public function __construct(
        public int $groupId,
        public string $slug,
        public string $imagePath,
    ) {}
}
