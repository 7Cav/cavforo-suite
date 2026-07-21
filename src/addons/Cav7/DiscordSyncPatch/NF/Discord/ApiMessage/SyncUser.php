<?php

namespace Cav7\DiscordSyncPatch\NF\Discord\ApiMessage;

use Cav7\DiscordSyncPatch\RoleClaim;

/**
 * Issue #148 — two overrides on the vendor's per-user sync message: an eviction at
 * the message entry point and a claim over the roles a member's forum groups grant.
 * Neither depends on the other: the eviction alone stops new bad records being
 * created but leaves existing ones permanent, and the claim alone repairs existing
 * ones but lets the race keep producing them.
 *
 * dispatch() is the first half. The sync runs as queued messages processed by one
 * long-lived worker, and that worker keeps loaded entities in the identity map for
 * its whole run. A finder re-query does not refresh an entity that is already
 * there, so a message that runs after an admin has saved somebody's groups can act
 * on a copy loaded earlier in the run and write a record that disagrees with the
 * groups. Evicting at the entry point puts every message back on the database.
 *
 * syncRoles() is the second half. The integration decides which roles it may take
 * away by asking "is this role one I recorded granting?", and treats everything
 * else as hand-added and untouchable. A record left disagreeing with the member's
 * groups therefore protects the wrong role for good: every later sync reads the
 * wrong record, reaches the same decision, and rewrites the record still wrong.
 * The override widens that record, in memory only, to also cover every role a user
 * group grants on the guild being synced. That answer comes from configuration,
 * which the fault cannot corrupt, so forum groups become authoritative for every
 * role tied to a group. Roles no user group grants stay outside the claim and are
 * left exactly as they are.
 *
 * Assumptions this makes about vendor internals, each checked at the seam where it
 * would break rather than reimplemented here:
 *
 *  - syncRoles() reads $syncLog->discord_role_ids as the set it may remove, and
 *    overwrites the field with the member's correct roles before it saves, so the
 *    widened value never reaches the database.
 *  - findOrCreateSyncLogForGuild() returns the identity-mapped instance for a
 *    record that exists, so the vendor sees the object this override widened.
 *  - A mapped role id MAY carry a "<serverId>:" prefix; a bare id belongs to the
 *    default server, split the way SyncUser::groupRoleIdsByServer splits it.
 */
class SyncUser extends XFCP_SyncUser
{
    public function dispatch(): bool
    {
        // Scoped to the two entity types this path reads: the member and the
        // integration's per-member record. A full identity-map clear also works but
        // is heavier per message and widens the blast radius for no benefit.
        // Nothing else needs considering, because the queue worker holds plain
        // database rows rather than entities and this entry point is only ever
        // reached from that worker.
        $em = \XF::em();
        $em->clearEntityCache(\XF\Entity\User::class);
        $em->clearEntityCache(\NF\Discord\Entity\SyncLog::class);

        return parent::dispatch();
    }

    protected function syncRoles(): bool
    {
        $this->applyRoleClaim();

        return parent::syncRoles();
    }

    /**
     * Supplies the inputs, calls the decision, assigns the result. Which roles get
     * claimed is decided in RoleClaim, which runs without XenForo and is covered by
     * the ordinary test run.
     */
    protected function applyRoleClaim(): void
    {
        $guildId = $this->api()->getGuildId();
        if (!$guildId) {
            return;
        }

        // A guild the server map does not know is not one this addon can scope a
        // claim to. The vendor's lookup returns int(0) for an unknown guild — its
        // array_search miss is false, coerced to int at the ?int return boundary
        // (the vendor file declares no strict_types) — so the value is neither false
        // nor null. Test it for falsiness: server ids start at 1, and a === null or
        // === false guard would both let server 0 through.
        $serverRepo = \SV\StandardLib\Helper::repository(\NF\Discord\Repository\Server::class);
        $serverId = $serverRepo->getServerIdFromGuildId($guildId);
        if (!$serverId) {
            return;
        }

        $syncLog = $this->user->findOrCreateSyncLogForGuild($guildId);

        // Persist a new record before widening it. An unsaved entity is not in the
        // identity map, so the vendor's own lookup would build a second instance
        // that never sees the claim. Saving first also leaves the insert's
        // change-log entry empty, which is what the vendor's own save produces at
        // this point; widening before the save would instead write an entry
        // announcing that the member was granted every managed role.
        if (!$syncLog->exists()) {
            $syncLog->save();
        }

        $syncLog->discord_role_ids = RoleClaim::claim(
            $syncLog->discord_role_ids,
            $this->getMappedRoleIds(),
            (int) $serverId,
            $serverRepo->getDefaultServerId()
        );
    }

    /**
     * Every Discord role any user group grants across every server. A mapped id MAY
     * carry a "<serverId>:" prefix; a bare id belongs to the default server, split
     * the way groupRoleIdsByServer splits it. RoleClaim narrows them to the guild
     * being synced.
     *
     * One small query per message, against a message that already spends a
     * rate-limited API round trip. Not cached, so a mapping change takes effect on
     * the next message rather than the next worker run.
     *
     * @return string[]
     */
    protected function getMappedRoleIds(): array
    {
        $rows = \XF::db()->fetchAllColumn("
            SELECT nfd_server_group_ids
            FROM xf_user_group
            WHERE nfd_server_group_ids <> ''
        ");

        $mappedRoleIds = [];
        foreach ($rows as $row) {
            foreach (explode(',', (string) $row) as $mappedRoleId) {
                $mappedRoleId = trim($mappedRoleId);
                if ($mappedRoleId !== '') {
                    $mappedRoleIds[] = $mappedRoleId;
                }
            }
        }

        return $mappedRoleIds;
    }
}
