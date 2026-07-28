<?php

namespace Cav7\DiscordSyncPatch;

/**
 * Issue #242 — decides which of a guild's roles Discord will not let this bot move.
 *
 * Pure: no XenForo, no database, no I/O. The adapters fetch the guild's roles and the
 * bot's own; which of them are immovable is decided here.
 */
class RoleReach
{
    /**
     * Every role on the guild this bot cannot move, whichever reason applies. This is
     * what both call sites actually want: a set that must survive into any role set
     * sent, and that no divergence may be judged on in either direction.
     *
     * The two reasons are kept apart by the callers, not here, because only one of
     * them is worth telling an admin about — a preserved role is permanent and
     * correct, an out-of-reach role is a misconfiguration one drag fixes.
     *
     * @param array<int, array> $guildRoles
     * @param string[]|null     $botRoleIds Null where the bot's own position could not
     *                                      be read. Not the same as an empty array: a
     *                                      bot holding no role reaches nothing, so []
     *                                      makes every role on the guild immovable,
     *                                      while null means only the reason that does
     *                                      not depend on position can be applied.
     *
     * @return string[]
     */
    public static function immovable(array $guildRoles, ?array $botRoleIds, string $guildId): array
    {
        if ($botRoleIds === null) {
            return self::preserved($guildRoles);
        }

        return array_values(array_unique(array_merge(
            self::preserved($guildRoles),
            self::outOfReach($guildRoles, $botRoleIds, $guildId)
        )));
    }

    /**
     * The roles Discord manages itself, which no bot can grant or remove however high
     * it sits. `managed` covers every role an integration owns, including a bot's own;
     * the Nitro-booster role carries the premium_subscriber tag and is managed besides.
     * The tag is still read because it is the property the vendor itself keys on, and a
     * role could in principle carry it alone.
     *
     * @param array<int, array> $guildRoles
     *
     * @return string[]
     */
    public static function preserved(array $guildRoles): array
    {
        $preserved = [];
        foreach ($guildRoles as $role) {
            if (!empty($role['managed']) || array_key_exists('premium_subscriber', $role['tags'] ?? [])) {
                $preserved[] = (string) $role['id'];
            }
        }

        return $preserved;
    }

    /**
     * The roles this bot cannot add or remove, because of where they sit relative to
     * its own highest role.
     *
     * @param array<int, array> $guildRoles Every role on the guild, as Discord reports
     *                                      them: id and position at least.
     * @param string[]          $botRoleIds The roles the bot itself holds on the guild.
     * @param string            $guildId    The guild being read.
     *
     * @return string[]
     */
    public static function outOfReach(array $guildRoles, array $botRoleIds, string $guildId): array
    {
        $positions = [];
        foreach ($guildRoles as $role) {
            $positions[(string) $role['id']] = (int) ($role['position'] ?? 0);
        }

        // A bot holding nothing reaches nothing, which -1 gives without a special case:
        // every real position is at or above it.
        $ceiling = -1;
        foreach ($botRoleIds as $botRoleId) {
            $ceiling = max($ceiling, $positions[(string) $botRoleId] ?? -1);
        }

        $outOfReach = [];
        foreach ($positions as $roleId => $position) {
            // At the ceiling, not merely above it. Discord breaks a tie on an id
            // ordering its own docs do not state, so the tie is read as immovable:
            // that error leaves a role unenforced and says so, where the opposite one
            // sends a write Discord refuses whole and nothing records why.
            // @everyone is every member's, granted by holding the guild rather than by
            // any write, and Discord refuses to add or remove it. Its id is the guild's
            // own. Position says nothing about it — it sits at 0, below every bot —
            // so without this it would read as the most reachable role on the guild.
            if ((string) $roleId === $guildId || $position >= $ceiling) {
                // Cast back deliberately. A role id is a decimal snowflake, so PHP has
                // silently made it an int array key on the way in; every id this addon
                // compares against is a string, and an int would match none of them.
                $outOfReach[] = (string) $roleId;
            }
        }

        return $outOfReach;
    }
}
