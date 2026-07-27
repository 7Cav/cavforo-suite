<?php

namespace Cav7\DiscordSyncPatch;

/**
 * Issue #157 — decides whether one member's managed Discord roles disagree with the
 * roles their current forum groups grant.
 *
 * Every id it takes is already narrowed to the guild being swept and carries no
 * server prefix; RoleScope does that, because Discord's own member records never
 * carry one.
 *
 * Pure: no XenForo, no database, no I/O. The sweep gathers the inputs and enqueues
 * the correction; whether a member has diverged is decided here.
 */
class RoleDivergence
{
    /**
     * @param string[] $heldRoleIds    Every role the member holds on the guild, as
     *                                 Discord reports it.
     * @param string[] $grantedRoleIds The roles this member's current groups grant.
     * @param string[] $managedRoleIds Every role any user group grants on the guild.
     * @param string[] $preservedRoleIds Roles Discord manages itself, which a bot can
     *                                   neither grant nor remove — the Nitro-booster
     *                                   role and anything else tagged premium.
     */
    public static function diverges(
        array $heldRoleIds,
        array $grantedRoleIds,
        array $managedRoleIds,
        array $preservedRoleIds
    ): bool
    {
        // Only managed roles are judged. Everything else on the guild — self-assigned
        // interest roles, game roles, roles another bot hands out — is outside this
        // addon, so a member holding one is not thereby divergent.
        $managed = array_flip(array_map('strval', $managedRoleIds));

        // Preserved roles drop out of both sides, not just the held one. A group can
        // grant the booster role, and a member can hold it without any group granting
        // it; in either direction the sync cannot move it, so a disagreement about one
        // describes work no correction can carry out. Left in, it is a divergence that
        // survives every correction and re-queues the member on every run.
        $preserved = array_flip(array_map('strval', $preservedRoleIds));

        $judged = static fn (string $roleId): bool => !isset($preserved[$roleId]);

        $held = array_filter(
            array_map('strval', $heldRoleIds),
            static fn (string $roleId): bool => isset($managed[$roleId]) && $judged($roleId)
        );

        $granted = array_filter(array_map('strval', $grantedRoleIds), $judged);

        return self::asSet($held) !== self::asSet($granted);
    }

    /**
     * @param string[] $roleIds
     * @return string[]
     */
    private static function asSet(array $roleIds): array
    {
        $set = array_unique(array_map('strval', $roleIds));
        sort($set);

        return $set;
    }
}
