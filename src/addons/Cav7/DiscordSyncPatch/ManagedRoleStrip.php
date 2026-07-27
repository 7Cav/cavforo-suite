<?php

namespace Cav7\DiscordSyncPatch;

/**
 * Issue #157 — decides what an unlinked holder should be left holding.
 *
 * An unlinked holder is a guild member with forum-managed roles and no connected
 * account to justify them. There is no forum user to run the vendor's per-user sync
 * for, so the sweep patches their roles directly.
 *
 * The result is the set to SEND, not the set to remove: the vendor's
 * patchGuildMemberRoles replaces a member's whole role set, so what it takes is what
 * the member should hold afterwards.
 *
 * Pure: no XenForo, no database, no I/O.
 */
class ManagedRoleStrip
{
    /**
     * @param string[] $heldRoleIds      Every role the member holds, as Discord
     *                                   reports it.
     * @param string[] $managedRoleIds   Every role any user group grants on the guild.
     * @param string[] $preservedRoleIds Roles Discord manages itself, which a bot can
     *                                   neither grant nor remove — the Nitro-booster
     *                                   role and anything else tagged premium.
     *
     * @return string[]|null The roles to send, or null to make no call at all. An
     *                       empty array is not null: it strips a member whose every
     *                       role is a managed one.
     */
    public static function rolesToKeep(
        array $heldRoleIds,
        array $managedRoleIds,
        array $preservedRoleIds
    ): ?array
    {
        $managed = array_flip(array_map('strval', $managedRoleIds));
        $preserved = array_flip(array_map('strval', $preservedRoleIds));
        $held = array_map('strval', $heldRoleIds);

        // A preserved role stays whatever the configuration says about it. Discord
        // refuses to move roles it manages, and the vendor swallows the refusal, so a
        // set that omits one does not partially apply — the entire strip is lost with
        // nothing recorded anywhere.
        $removable = static fn (string $roleId): bool =>
            isset($managed[$roleId]) && !isset($preserved[$roleId]);

        // Judged on what would actually come off, not on what the member holds. Most
        // of the guild holds no managed role, and a member whose only managed role is
        // preserved would be patched to precisely what they already have.
        if (!array_filter($held, $removable)) {
            return null;
        }

        return array_values(array_filter(
            $held,
            static fn (string $roleId): bool => !$removable($roleId)
        ));
    }
}
