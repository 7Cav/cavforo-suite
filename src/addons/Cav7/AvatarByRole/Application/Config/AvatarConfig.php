<?php

namespace Cav7\AvatarByRole\Application\Config;

class AvatarConfig
{
    /**
     * @return AvatarGroup[]
     */
    public static function all(): array
    {
        return [
            new AvatarGroup(32, 'PVT', '/styles/default/xenforo/avatars/PVT_Avatar.png'),
            new AvatarGroup(31, 'PFC', '/styles/default/xenforo/avatars/PFC_Avatar.png'),
            new AvatarGroup(30, 'SPC', '/styles/default/xenforo/avatars/SPC_Avatar.png'),
            new AvatarGroup(29, 'CPL', '/styles/default/xenforo/avatars/CPL_Avatar.png'),
            new AvatarGroup(28, 'SGT', '/styles/default/xenforo/avatars/SGT_Avatar.png'),
            new AvatarGroup(27, 'SSG', '/styles/default/xenforo/avatars/SSG_Avatar.png'),
            new AvatarGroup(26, 'SFC', '/styles/default/xenforo/avatars/SFC_Avatar.png'),
            new AvatarGroup(24, 'MSG', '/styles/default/xenforo/avatars/MSG_Avatar.png'),
            new AvatarGroup(151, '1SG', '/styles/default/xenforo/avatars/1SG_Avatar.png'),
            new AvatarGroup(25, 'SGM', '/styles/default/xenforo/avatars/SGM_Avatar.png'),
            new AvatarGroup(23, 'CSM', '/styles/default/xenforo/avatars/CSM_Avatar.png'),
            new AvatarGroup(22, 'WO1', '/styles/default/xenforo/avatars/WO1_Avatar.png'),
            new AvatarGroup(21, 'CW2', '/styles/default/xenforo/avatars/WO2_Avatar.png'),
            new AvatarGroup(20, 'CW3', '/styles/default/xenforo/avatars/WO3_Avatar.png'),
            new AvatarGroup(19, 'CW4', '/styles/default/xenforo/avatars/WO4_Avatar.png'),
            new AvatarGroup(18, 'CW5', '/styles/default/xenforo/avatars/WO5_Avatar.png'),
            new AvatarGroup(17, '2LT', '/styles/default/xenforo/avatars/2LT_Avatar.png'),
            new AvatarGroup(16, '1LT', '/styles/default/xenforo/avatars/1LT_Avatar.png'),
            new AvatarGroup(15, 'CPT', '/styles/default/xenforo/avatars/CPT_Avatar.png'),
            new AvatarGroup(14, 'MAJ', '/styles/default/xenforo/avatars/MAJ_Avatar.png'),
            new AvatarGroup(13, 'LTC', '/styles/default/xenforo/avatars/LTC_Avatar.png'),
            new AvatarGroup(12, 'COL', '/styles/default/xenforo/avatars/COL_Avatar.png'),
            new AvatarGroup(11, 'BG', '/styles/default/xenforo/avatars/BG_Avatar.png'),
            new AvatarGroup(10, 'MG', '/styles/default/xenforo/avatars/MG_Avatar.png'),
            new AvatarGroup(9, 'LTG', '/styles/default/xenforo/avatars/LTG_Avatar.png'),
            new AvatarGroup(8, 'GEN', '/styles/default/xenforo/avatars/GEN_Avatar.png'),
            new AvatarGroup(7, 'GOA', '/styles/default/xenforo/avatars/GOA_Avatar.png'),
            new AvatarGroup(34, 'RES', '/styles/default/xenforo/avatars/RES_Avatar.png'),
        ];
    }


    public static function findByGroupId(int $id): ?AvatarGroup
    {
        foreach (self::all() as $group) {
            if ($group->groupId === $id) return $group;
        }
        return null;
    }

    public static function findBySlug(string $slug): ?AvatarGroup
    {
        foreach (self::all() as $group) {
            if ($group->slug === $slug) return $group;
        }
        return null;
    }

    public static function getAllRecruiterVariants(): array
    {
        return [
            60, // Recruiter
            59, // RRD Lead/Senior
            238, // RRD HQ
        ];
    }
}
