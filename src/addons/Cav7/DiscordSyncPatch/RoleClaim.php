<?php

namespace Cav7\DiscordSyncPatch;

/**
 * Issue #148 — decides the claim: the set of Discord role ids the per-user sync
 * may remove for the guild being synced. It treats a role as its own once it has
 * recorded granting it, or a user group grants it on that guild.
 *
 * Pure: no XenForo, no database, no I/O. The adapter fetches the inputs and
 * assigns the result; every decision about *which* roles get claimed lives here.
 */
class RoleClaim
{
    /**
     * @param string[] $recordedRoleIds Role ids the sync already recorded granting
     *                                  this member, unprefixed.
     * @param string[] $mappedRoleIds   Every role any user group grants. A mapped
     *                                  id MAY carry a "<serverId>:" prefix; a bare
     *                                  id belongs to $defaultServerId, split the way
     *                                  SyncUser::groupRoleIdsByServer splits it.
     * @param int      $serverId        The server being synced.
     * @param int      $defaultServerId The server a mapped id with no prefix
     *                                  belongs to.
     *
     * @return string[] Unprefixed, unique, scoped to $serverId.
     */
    public static function claim(
        array $recordedRoleIds,
        array $mappedRoleIds,
        int $serverId,
        int $defaultServerId
    ): array
    {
        $claim = [];

        // The recorded set is kept, not replaced: the claim only ever widens what
        // the sync considers removable, so nothing cleaned up today stops being
        // cleaned up. These ids are already scoped to the guild being synced —
        // the record is per-guild — so they carry no prefix to split.
        foreach ($recordedRoleIds as $recordedRoleId) {
            $roleId = (string) $recordedRoleId;
            $claim[$roleId] = $roleId;
        }

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
            // drop it here rather than record a phantom empty id.
            if ($roleId === '') {
                continue;
            }

            if ((int) $mappedServerId !== $serverId) {
                continue;
            }

            $claim[$roleId] = $roleId;
        }

        return array_values($claim);
    }
}
