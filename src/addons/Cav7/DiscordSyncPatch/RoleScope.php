<?php

namespace Cav7\DiscordSyncPatch;

/**
 * Narrows the role ids a user group grants to one guild.
 *
 * NF/Discord stores what a group grants as a flat list across every server, where
 * an id MAY carry a "<serverId>:" prefix and a bare id belongs to the default
 * server — the same split the vendor's own SyncUser::groupRoleIdsByServer makes.
 * Every decision in this addon that compares configured roles against Discord's
 * roles has to apply it first, because Discord never sends a prefix: an unscoped
 * comparison reads another guild's roles as belonging to this one.
 *
 * Extracted from RoleClaim (issue #157), which now calls it. One interpretation of
 * the stored format, in one place.
 *
 * Pure: no XenForo, no database, no I/O.
 */
class RoleScope
{
    /**
     * @param string[] $mappedRoleIds   Every role any user group grants, in the
     *                                  stored "<serverId>:<roleId>" or bare form.
     * @param int      $serverId        The server being scoped to.
     * @param int      $defaultServerId The server a bare id belongs to.
     *
     * @return string[] Unprefixed role ids for $serverId, unique, in input order.
     */
    public static function forServer(
        array $mappedRoleIds,
        int $serverId,
        int $defaultServerId
    ): array
    {
        $scoped = [];

        foreach ($mappedRoleIds as $mappedRoleId) {
            $parts = explode(':', (string) $mappedRoleId, 2);
            if (count($parts) === 1) {
                $mappedServerId = $defaultServerId;
                $roleId = $parts[0];
            } else {
                [$mappedServerId, $roleId] = $parts;
            }

            // A bare prefix ("<serverId>:") names no role. The empty whole token is
            // filtered upstream, but an empty role id after a valid prefix is not, so
            // drop it here rather than pass on a phantom empty id.
            if ($roleId === '') {
                continue;
            }

            if ((int) $mappedServerId !== $serverId) {
                continue;
            }

            $scoped[$roleId] = $roleId;
        }

        return array_values($scoped);
    }
}
