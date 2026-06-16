<?php

namespace Cav7\AvatarByRole\Application\Mapper;

use Cav7\AvatarByRole\Application\Config\AvatarConfig;
use Cav7\AvatarByRole\Application\Config\AvatarGroup;

class UserGroupAvatarMapper
{
    public const DEFAULT_AVATAR = '/styles/default/xenforo/avatars/default-avatar.png';

    protected const SUFFIX_GROUPS = [
        'Retired',
    ];

    public static function getAvatarForGroupIds(int $primaryGroupId, array $secondaryGroupIds): string
    {
        if ($primaryGroupId <= 0 && empty($secondaryGroupIds)) {
            return self::DEFAULT_AVATAR;
        }

        $groupIds = array_merge([$primaryGroupId], $secondaryGroupIds);
        $modifiers = [];

        if (array_intersect($groupIds, AvatarConfig::getAllRecruiterVariants())) {
            $modifiers[] = 'Recruiter';
        }

        $group = AvatarConfig::findBySlug('RES');
        if ($group && in_array($group->groupId, $groupIds, true)) {
            return $group->imagePath;
        }

        foreach (self::SUFFIX_GROUPS as $slug) {
            $group = AvatarConfig::findBySlug($slug);
            if ($group && in_array($group->groupId, $groupIds, true)) {
                $modifiers[] = $slug;
            }
        }

        foreach (AvatarConfig::all() as $group) {
            if (in_array($group->groupId, $groupIds, true)) {
                $path = $group->imagePath;

                foreach ($modifiers as $mod) {
                    $path = str_replace('.png', "_$mod.png", $path);
                }

                return $path;
            }
        }

        return self::DEFAULT_AVATAR;
    }

    public static function getGroupBySlug(string $slug): ?AvatarGroup
    {
        return AvatarConfig::findBySlug($slug);
    }
}
