<?php

namespace Cav7\DiscordSyncPatch;

use NF\Discord\Api;
use NF\Discord\RateLimitedException;

/**
 * Issue #157 — the reconciliation sweep: finds the members whose Discord roles have
 * fallen out of step with their forum groups, corrects only those, and strips managed
 * roles from anyone holding them with no forum link.
 *
 * This is the adapter. It gathers inputs, calls the decisions, and applies what they
 * return. Every rule it rests on lives in a unit that runs without XenForo and is
 * covered by the ordinary test run: SyncRecordStaleness (the forum-side rule),
 * RoleDivergence (the Discord-side rule), ManagedRoleStrip (what an unlinked holder
 * keeps), MemberCursor (how the member fetch walks), and RoleScope (narrowing the
 * configured roles to one guild).
 *
 * Four ways the vendor's queueing does nothing while reporting success are guarded
 * here, because a cron meets all of them every quarter-hour with nobody watching.
 * Each guard is commented where it sits.
 *
 * Assumptions this makes about vendor internals, none of which CI can see:
 *
 *  - `guilds/{guild.id}/members` needs the bot to hold Discord's GUILD_MEMBERS
 *    privileged intent. Without it the fetch fails and only the forum-side half of
 *    detection runs, which is logged rather than passed over.
 *  - Api::request() json-encodes its data argument into the request BODY even on a
 *    GET, so parameters passed that way never reach Discord. preparePath() substitutes
 *    only :guildId, so the query string goes in the path — the vendor's own getGuild()
 *    does exactly this.
 *  - A guild member record is ['user' => ['id' => ...], 'roles' => [...]].
 *  - A member's Discord id is the provider_key of their nfDiscord connected account.
 *  - A role Discord manages itself carries the premium_subscriber tag, which is how
 *    the Nitro-booster role is recognised without naming its id.
 */
class ReconciliationSweep
{
    /** Discord's maximum page size for the guild member list. */
    public const MEMBER_PAGE_LIMIT = 1000;

    /**
     * A bound on the member walk, not a page budget. MemberCursor stops on a short
     * page, so this only fires if Discord keeps returning full pages — a cursor that
     * stopped advancing. At the maximum page size it allows a guild far larger than
     * any this runs against before it gives up and says so, which is the difference
     * between a bad run and a bot hammering a rate-limited endpoint until someone
     * notices.
     */
    protected const MAX_MEMBER_PAGES = 100;

    /**
     * How long a queued row still counts as work in flight. Matched to the vendor's
     * own abandonment threshold, exactly as the resync action matches it:
     * Repository\Queue::run() archives anything older without a Discord round trip.
     */
    protected const PENDING_MAX_AGE_SECONDS = 86400;

    public function run(): void
    {
        // Guard 1. Api::factory() hands back a working object for any non-null guild
        // id whatever the credentials are, so the fan-out would queue rows normally —
        // but Repository\Queue::run() opens on Api::factory(null, false), gets null
        // from exactly this condition, and returns before it reads the queue. Rows
        // would pile up and nothing would ever drain them.
        if (Api::getDiscordConfiguration() === null) {
            return;
        }

        $serverRepo = \SV\StandardLib\Helper::repository(\NF\Discord\Repository\Server::class);

        // Guard 2. updateServerCache() applies isActive() but not the vendor's own
        // hasGuildId(), so a row with active = 1 and an empty guild id reaches the map.
        // Left in, it is the worst outcome available: the message dispatches, reads the
        // empty guild id, returns TRUE, and the queue archives it as a success with no
        // error log and no fail count, while no role has moved.
        $serverMap = array_filter($serverRepo->getServerMap());
        if (!$serverMap) {
            return;
        }

        // The same filtered ids are handed to the fan-out below. Filtering this copy is
        // not enough on its own: queueSyncJobsForUser() takes no map and re-reads
        // getServerMap() itself, unfiltered, so without naming the servers explicitly a
        // correction still fans out against the guild-less row.
        $syncableServerIds = array_map('intval', array_keys($serverMap));
        $defaultServerId = $serverRepo->getDefaultServerId();

        $correctUserIds = [];
        foreach ($serverMap as $serverId => $guildId) {
            foreach ($this->reconcileGuild((int) $serverId, (string) $guildId, $defaultServerId) as $userId) {
                $correctUserIds[$userId] = $userId;
            }
        }

        $this->queueCorrections($correctUserIds, $syncableServerIds);
    }

    /**
     * Reconciles one guild. Strips its unlinked holders directly, and returns the ids
     * of the linked members needing correction for the caller to queue.
     *
     * @return int[]
     */
    protected function reconcileGuild(int $serverId, string $guildId, int $defaultServerId): array
    {
        $api = Api::factory($guildId, false);
        if (!$api) {
            return [];
        }

        $groupRoleIds = $this->mappedRoleIdsByGroup();

        $managedRoleIds = RoleScope::forServer(
            array_merge(...array_values($groupRoleIds) ?: [[]]),
            $serverId,
            $defaultServerId
        );

        // The forum-side half. It costs one query and no Discord budget, and it stands
        // whether or not the member fetch below succeeds.
        $divergentUserIds = $this->forumSideDivergentUserIds($guildId);

        // Nothing on the Discord side is worth asking for if no group grants a role on
        // this guild: every member's managed set is empty, so nobody can diverge from
        // it and no unlinked holder can be holding one.
        if (!$managedRoleIds) {
            return $divergentUserIds;
        }

        $members = $this->fetchGuildMembers($api, $guildId);
        if ($members === null) {
            // Detection has silently halved. Said out loud, because the alternative is
            // a sweep that looks healthy for months while every hand-edited role and
            // every unlinked holder goes unnoticed — which is also what a revoked
            // GUILD_MEMBERS intent looks like from here.
            \XF::logError(sprintf(
                'Cav7/DiscordSyncPatch: the reconciliation sweep could not read guild %s members, so Discord-side divergence and unlinked holders were not checked this run. The bot needs the GUILD_MEMBERS privileged intent for this endpoint.',
                $guildId
            ));

            return $divergentUserIds;
        }

        $preservedRoleIds = $this->preservedRoleIds($api);
        $linkedUserIdsByDiscordId = $this->linkedUserIdsByDiscordId();
        $currentGroupIdsByUserId = $this->currentGroupIdsByUserId();

        $stripped = 0;
        foreach ($members as $member) {
            $discordId = (string) ($member['user']['id'] ?? '');
            if ($discordId === '') {
                continue;
            }

            $heldRoleIds = array_map('strval', $member['roles'] ?? []);
            $userId = $linkedUserIdsByDiscordId[$discordId] ?? null;

            if ($userId === null) {
                // An unlinked holder. There is no forum user to run the vendor's
                // per-user sync for — it bails with user_not_associated — so the roles
                // are patched directly.
                if ($this->stripUnlinkedHolder($api, $guildId, $discordId, $heldRoleIds, $managedRoleIds, $preservedRoleIds)) {
                    $stripped++;
                }
                continue;
            }

            $grantedRoleIds = RoleScope::forServer(
                $this->grantedRoleIdsFor($currentGroupIdsByUserId[$userId] ?? [], $groupRoleIds),
                $serverId,
                $defaultServerId
            );

            if (RoleDivergence::diverges($heldRoleIds, $grantedRoleIds, $managedRoleIds, $preservedRoleIds)) {
                $divergentUserIds[$userId] = $userId;
            }
        }

        if ($stripped > 0) {
            // A durable line for the one correction that cannot be recorded against a
            // member. NF/Discord's own log only writes when its extended logging option
            // is on, and an admin asked months later why somebody lost their roles
            // needs an answer that does not depend on that having been enabled. One row
            // per run rather than one per member, and only when roles actually moved.
            \XF::logError(sprintf(
                'Cav7/DiscordSyncPatch: the reconciliation sweep stripped forum-managed roles from %d guild member(s) on %s who hold no linked forum account. Discord\'s own server audit log records each removal.',
                $stripped,
                $guildId
            ));
        }

        return $divergentUserIds;
    }

    /**
     * Applies the strip decision to one unlinked holder.
     *
     * @param string[] $heldRoleIds
     * @param string[] $managedRoleIds
     * @param string[] $preservedRoleIds
     *
     * @return bool Whether roles were actually removed.
     */
    protected function stripUnlinkedHolder(
        Api $api,
        string $guildId,
        string $discordId,
        array $heldRoleIds,
        array $managedRoleIds,
        array $preservedRoleIds
    ): bool
    {
        $keepRoleIds = ManagedRoleStrip::rolesToKeep($heldRoleIds, $managedRoleIds, $preservedRoleIds);

        // null is not an empty set. Most of the guild holds no managed role at all, and
        // patching them would rewrite thousands of members to exactly what they already
        // have, against a budget near sixty role writes a minute.
        if ($keepRoleIds === null) {
            return false;
        }

        \NF\Discord\Helper::log('[Cav7\DiscordSyncPatch] Stripping managed roles from an unlinked holder', [
            'guild_id' => $guildId,
            'discord_id' => $discordId,
            'reason' => 'holds forum-managed roles with no nfDiscord connected account',
            'held_role_ids' => $heldRoleIds,
            'kept_role_ids' => $keepRoleIds,
        ]);

        // patchGuildMemberRoles REPLACES the member's role set, which is why the
        // decision returns what they should be left holding rather than what to remove.
        $api->patchGuildMemberRoles($discordId, $keepRoleIds);

        return true;
    }

    /**
     * The whole guild's member list, or null if it could not be read.
     *
     * @return array<int, array>|null
     */
    protected function fetchGuildMembers(Api $api, string $guildId): ?array
    {
        $members = [];
        $after = null;

        for ($page = 0; $page < self::MAX_MEMBER_PAGES; $page++) {
            $query = ['limit' => self::MEMBER_PAGE_LIMIT];
            if ($after !== null) {
                $query['after'] = $after;
            }

            // The query goes in the PATH. Passed as the vendor's data argument it would
            // be json-encoded into the body of a GET and Discord would receive a request
            // carrying no parameters at all — the first page, forever.
            try {
                $result = $api->get('guilds/:guildId/members?' . http_build_query($query));
            } catch (RateLimitedException $e) {
                // Whatever was read stays usable; the rest of the guild waits for the
                // next run. Returning null here would throw away a complete prefix and
                // report the run as a total failure.
                break;
            }

            if (!is_array($result)) {
                return $members ? $members : null;
            }

            foreach ($result as $member) {
                $members[] = $member;
            }

            $after = MemberCursor::next(
                array_map(static fn (array $m): string => (string) ($m['user']['id'] ?? ''), $result),
                self::MEMBER_PAGE_LIMIT
            );

            if ($after === null) {
                return $members;
            }
        }

        \XF::logError(sprintf(
            'Cav7/DiscordSyncPatch: the reconciliation sweep stopped walking guild %s after %d pages without reaching the end of the member list. Reconciliation ran against a partial view.',
            $guildId,
            self::MAX_MEMBER_PAGES
        ));

        return $members;
    }

    /**
     * The linked members whose sync record no longer describes a correct, successful
     * sync on this guild.
     *
     * Requires both a connected account and a sync log row. A member with a connected
     * account and no row was never synced here — the join path's business, not
     * reconciliation's — and a member with a row and no account has unlinked, which the
     * unlinked-holder half covers from the Discord side.
     *
     * @return int[] Keyed by user id.
     */
    protected function forumSideDivergentUserIds(string $guildId): array
    {
        $rows = \XF::db()->fetchAll('
            SELECT sync_log.user_id,
                   sync_log.user_group_ids,
                   sync_log.active,
                   sync_log.user_error_phrase,
                   user.user_group_id,
                   user.secondary_group_ids
            FROM xf_nf_discord_sync_log AS sync_log
            INNER JOIN xf_user AS user ON (user.user_id = sync_log.user_id)
            INNER JOIN xf_user_connected_account AS account
                ON (account.user_id = sync_log.user_id AND account.provider = ?)
            WHERE sync_log.guild_id = ?
        ', ['nfDiscord', $guildId]);

        $divergent = [];
        foreach ($rows as $row) {
            $isStale = SyncRecordStaleness::isStale(
                $this->splitList($row['user_group_ids']),
                $this->currentGroupIds($row['user_group_id'], $row['secondary_group_ids']),
                (bool) $row['active'],
                $row['user_error_phrase']
            );

            if ($isStale) {
                $userId = (int) $row['user_id'];
                $divergent[$userId] = $userId;
            }
        }

        return $divergent;
    }

    /**
     * Queues one correction per member, through the vendor's own per-user sync so the
     * fix inherits everything this addon already patches into that path.
     *
     * @param int[] $userIds
     * @param int[] $syncableServerIds
     */
    protected function queueCorrections(array $userIds, array $syncableServerIds): void
    {
        if (!$userIds) {
            return;
        }

        $syncRepo = \SV\StandardLib\Helper::repository(\NF\Discord\Repository\Sync::class);
        $users = \XF::em()->findByIds(\XF\Entity\User::class, $userIds);

        foreach ($users as $user) {
            // Guards 3 and 4 together. Queue::queueMessage() inserts the row and then
            // calls enqueueJob(), whose enqueueLater() sits inside an empty catch: the
            // row can survive while the job that would drain it does not, and nothing
            // re-drives it. Re-queueing a member in that state adds ninety-six dead rows
            // a day and — because #158's resync action refuses while a row is pending —
            // locks them out of the manual button at the same time.
            if ($this->hasPendingDiscordSync($user->user_id)) {
                continue;
            }

            // skipLoggingChanges stays false, so each correction is recorded against the
            // member. NF/Discord's own dormant cron passes true here and its corrections
            // land invisibly; an admin needs to be able to see what the sweep changed.
            // The server ids are named because the fan-out re-reads the unfiltered map.
            $syncRepo->queueSyncJobsForUser($user, false, false, false, $syncableServerIds);
        }
    }

    protected function hasPendingDiscordSync(int $userId): bool
    {
        return (bool) \XF::db()->fetchOne('
            SELECT queue_id
            FROM xf_nf_discord_queue
            WHERE class_name = ? AND user_id = ? AND queue_date > ?
            LIMIT 1
        ', [
            \NF\Discord\ApiMessage\SyncUser::class,
            $userId,
            \XF::$time - self::PENDING_MAX_AGE_SECONDS,
        ]);
    }

    /**
     * Roles Discord manages itself, which a bot can neither grant nor remove. Sending a
     * role set that omits one makes Discord refuse the whole call, and the vendor
     * swallows that refusal, so the strip would be lost with nothing recorded.
     *
     * @return string[]
     */
    protected function preservedRoleIds(Api $api): array
    {
        try {
            $roles = $api->getRoles(true);
        } catch (RateLimitedException $e) {
            $roles = [];
        }

        $preserved = [];
        foreach ($roles as $role) {
            if (array_key_exists('premium_subscriber', $role['tags'] ?? [])) {
                $preserved[] = (string) $role['id'];
            }
        }

        return $preserved;
    }

    /**
     * Every Discord role each user group grants, in the stored form. RoleScope narrows
     * them to one guild.
     *
     * @return array<int, string[]> Keyed by user group id.
     */
    protected function mappedRoleIdsByGroup(): array
    {
        $rows = \XF::db()->fetchPairs("
            SELECT user_group_id, nfd_server_group_ids
            FROM xf_user_group
            WHERE nfd_server_group_ids <> ''
        ");

        $byGroup = [];
        foreach ($rows as $groupId => $mapped) {
            $byGroup[(int) $groupId] = $this->splitList($mapped);
        }

        return $byGroup;
    }

    /**
     * @param int[]                 $groupIds
     * @param array<int, string[]>  $groupRoleIds
     * @return string[]
     */
    protected function grantedRoleIdsFor(array $groupIds, array $groupRoleIds): array
    {
        $granted = [];
        foreach ($groupIds as $groupId) {
            foreach ($groupRoleIds[(int) $groupId] ?? [] as $roleId) {
                $granted[] = $roleId;
            }
        }

        return $granted;
    }

    /**
     * @return array<string, int> Discord id => forum user id.
     */
    protected function linkedUserIdsByDiscordId(): array
    {
        $rows = \XF::db()->fetchPairs('
            SELECT provider_key, user_id
            FROM xf_user_connected_account
            WHERE provider = ?
        ', ['nfDiscord']);

        $linked = [];
        foreach ($rows as $discordId => $userId) {
            $linked[(string) $discordId] = (int) $userId;
        }

        return $linked;
    }

    /**
     * @return array<int, int[]> Forum user id => their current group ids.
     */
    protected function currentGroupIdsByUserId(): array
    {
        $rows = \XF::db()->fetchAll('
            SELECT user.user_id, user.user_group_id, user.secondary_group_ids
            FROM xf_user AS user
            INNER JOIN xf_user_connected_account AS account
                ON (account.user_id = user.user_id AND account.provider = ?)
        ', ['nfDiscord']);

        $groups = [];
        foreach ($rows as $row) {
            $groups[(int) $row['user_id']] = $this->currentGroupIds(
                $row['user_group_id'],
                $row['secondary_group_ids']
            );
        }

        return $groups;
    }

    /**
     * The same set XenForo's own User::current_user_group_ids returns: the primary
     * group and the secondary ones together.
     *
     * @return int[]
     */
    protected function currentGroupIds($primaryGroupId, $secondaryGroupIds): array
    {
        $groupIds = array_map('intval', $this->splitList($secondaryGroupIds));
        $groupIds[] = (int) $primaryGroupId;

        return array_values(array_unique($groupIds));
    }

    /**
     * Splits a LIST_COMMA column. Both the sync log's group set and a user group's role
     * mapping are stored that way.
     *
     * @return string[]
     */
    protected function splitList($value): array
    {
        if (is_array($value)) {
            return array_map('strval', $value);
        }

        $parts = [];
        foreach (explode(',', (string) $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts;
    }
}
