<?php

namespace Cav7\DiscordSyncPatch;

use NF\Discord\Api;

/**
 * Issue #157 — the reconciliation sweep: finds the members whose Discord roles have
 * fallen out of step with their forum groups, corrects only those, and strips managed
 * roles from anyone holding them with no forum link.
 *
 * This is the adapter. It gathers inputs, calls the decisions, and applies what they
 * return. Every rule it rests on lives in a unit that runs without XenForo and is
 * covered by the ordinary test run: SyncRecordStaleness (the forum-side rule),
 * RoleDivergence (the Discord-side rule), ManagedRoleStrip (what an unlinked holder
 * keeps), MemberCursor (how the member fetch walks), RoleScope (narrowing the
 * configured roles to one guild), and RoleReach (which roles Discord will not let this
 * bot move).
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
 *  - A role Discord manages itself is flagged `managed`, and the Nitro-booster role
 *    additionally carries the premium_subscriber tag. Either marks a role no bot can
 *    move, which is how they are recognised without naming any id.
 *  - A bot may only add or remove roles below its own highest one, and the constraint
 *    is on the roles MOVED rather than on the member holding them — a member whose top
 *    role is above the bot still takes a 200 as long as that role stays in the set.
 *    Administrator does not bypass it. Measured on a real guild for #242; the probe is
 *    in docs/verification/reconciliation-sweep-guards.md.
 *  - getRoles() returns an empty array for a failed request as well as a successful
 *    one, so an empty result is read as failure — every guild has an @everyone role.
 *    It also caches what it got for five minutes without distinguishing the two, which
 *    is why this asks it not to read that cache.
 *  - A Discord 429 reaches this addon as an ordinary `false` — not as an exception,
 *    and not as a decoded error body. What follows from that is in wasThrottled()
 *    below; the evidence is in docs/verification/reconciliation-sweep-guards.md.
 */
class ReconciliationSweep
{
    /** Discord's maximum page size for the guild member list. */
    public const MEMBER_PAGE_LIMIT = 1000;

    /**
     * What one unlinked holder's strip did. Every failed call hands this addon the
     * same `false`, so without telling them apart the per-run log line — the only
     * forum-side trace a strip leaves — reports a permissions problem for a guild
     * that may only have been busy.
     */
    protected const STRIP_DONE = 'stripped';
    protected const STRIP_REFUSED = 'refused';
    protected const STRIP_THROTTLED = 'throttled';
    protected const STRIP_NOT_NEEDED = 'not needed';

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

    /**
     * The three lookups below describe the forum, not a guild, so they are read once
     * for the run rather than once per guild. A sweep is short and works from a
     * snapshot either way; what this avoids is re-reading every connected account and
     * every user group for each guild in the map.
     *
     * @var array<int, string[]>|null
     */
    protected ?array $mappedRoleIdsByGroup = null;

    /** @var array<string, int>|null */
    protected ?array $linkedUserIdsByDiscordId = null;

    /** @var array<int, int[]>|null */
    protected ?array $currentGroupIdsByUserId = null;

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

        // Asked for once, here, rather than before each call below. Every decision
        // this method makes about a failed call rests on wasThrottled(), and the
        // vendor leaves the previous call's retry-after standing on the branch a
        // dropped connection and a Discord 5xx both take — so without this a 429
        // partway through a guild is reported again by every following call until one
        // reaches the vendor's own reset. Issue #248; what the flag does and why it is
        // opt-in is on the extension.
        //
        // factory() returns the extended class, so this is the addon's own Api. If the
        // class extension were ever deactivated this would fatal rather than quietly
        // mis-report, which is the better of the two failures.
        $api->setRetryAfterPerCall(true);

        $groupRoleIds = $this->mappedRoleIdsByGroup();

        $managedRoleIds = RoleScope::forServer(
            $this->allMappedRoleIds($groupRoleIds),
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

        // Read first, and cheaper than the member walk, because nothing on the Discord
        // side can be decided safely without it. Both decisions below take the
        // protected roles as an input, and both are unsafe with a wrong answer.
        $guildRoles = $this->guildRoles($api);
        if ($guildRoles === null) {
            // Both causes refuse the same way, and getRoles() hides the difference by
            // substituting [] for a failed request. Which one it was decides whether
            // an admin has anything to do: a throttle is gone by the next run, an
            // empty answer from a guild that must have an @everyone role is not.
            \XF::logError(sprintf(
                'Cav7/DiscordSyncPatch: the reconciliation sweep could not read guild %s roles, because %s, so it did not check Discord-side divergence or unlinked holders this run. Acting without them would send role sets missing the roles Discord manages itself, which Discord refuses and the integration discards silently.',
                $guildId,
                $this->wasThrottled($api)
                    ? 'Discord rate-limited the bot'
                    : 'the read came back with no roles at all, and every guild has at least an @everyone role'
            ));

            return $divergentUserIds;
        }

        // Where the bot sits decides which roles it can move at all. Unlike the roles
        // read above this fails OPEN, and the asymmetry is deliberate: a wrong-empty
        // preserved set breaks writes that would otherwise have worked, while a
        // wrong-empty out-of-reach set can only fail for the members already failing.
        // Refusing the whole guild's reconciliation over it would trade everyone's
        // correction for a subset's.
        $botRoleIds = $this->botRoleIds($api);
        if ($botRoleIds === null) {
            \XF::logError(sprintf(
                'Cav7/DiscordSyncPatch: the reconciliation sweep could not read its own guild member record on %s, because %s, so it could not tell which roles sit above it. It reconciled the guild anyway; any role at or above its own will be refused by Discord this run.',
                $guildId,
                $this->wasThrottled($api)
                    ? 'Discord rate-limited the bot'
                    : 'the read came back unusable'
            ));
        }

        // What both decisions below actually want: every role no write of ours can
        // move, whichever of the two reasons applies. Null is passed through rather
        // than substituted with an empty array — a bot holding no role reaches nothing,
        // so [] would make the whole guild immovable.
        $immovableRoleIds = RoleReach::immovable($guildRoles, $botRoleIds, $guildId);

        // Only the roles out of reach are worth telling an admin about, so they are
        // asked for separately from the union above.
        $outOfReachRoleIds = $botRoleIds === null
            ? []
            : RoleReach::outOfReach($guildRoles, $botRoleIds, $guildId);

        $this->reportOutOfReach($guildId, array_intersect($outOfReachRoleIds, $managedRoleIds));

        $members = $this->fetchGuildMembers($api, $guildId);
        if ($members === null) {
            // Detection has silently halved. fetchGuildMembers has already said why;
            // the alternative to saying it is a sweep that looks healthy for months
            // while every hand-edited role and every unlinked holder goes unnoticed.
            return $divergentUserIds;
        }
        $linkedUserIdsByDiscordId = $this->linkedUserIdsByDiscordId();
        $currentGroupIdsByUserId = $this->currentGroupIdsByUserId();

        $outcomes = [
            self::STRIP_DONE => 0,
            self::STRIP_REFUSED => 0,
            self::STRIP_THROTTLED => 0,
            self::STRIP_NOT_NEEDED => 0,
        ];

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
                $outcomes[$this->stripUnlinkedHolder(
                    $api,
                    $guildId,
                    $discordId,
                    $heldRoleIds,
                    $managedRoleIds,
                    $immovableRoleIds
                )]++;
                continue;
            }

            $grantedRoleIds = RoleScope::forServer(
                $this->grantedRoleIdsFor($currentGroupIdsByUserId[$userId] ?? [], $groupRoleIds),
                $serverId,
                $defaultServerId
            );

            if (RoleDivergence::diverges($heldRoleIds, $grantedRoleIds, $managedRoleIds, $immovableRoleIds)) {
                $divergentUserIds[$userId] = $userId;
            }
        }

        $stripped = $outcomes[self::STRIP_DONE];
        $refused = $outcomes[self::STRIP_REFUSED];
        $throttled = $outcomes[self::STRIP_THROTTLED];

        if ($stripped > 0 || $refused > 0 || $throttled > 0) {
            // A durable line for the one correction that cannot be recorded against a
            // member. NF/Discord's own log only writes when its extended logging option
            // is on, and an admin asked months later why somebody lost their roles
            // needs an answer that does not depend on that having been enabled. One row
            // per run rather than one per member, and only when a call was made.
            //
            // Refusals are counted apart from strips rather than folded into them. A
            // refused call moved no role, and reporting it as a strip would make this
            // line — the only forum-side trace there is — assert something that did not
            // happen. The refusal to expect is Discord rejecting a role set that omits
            // a role it manages, which it rejects whole.
            //
            // Throttled calls are counted apart from refusals for the same reason one
            // step down. Both moved no role, but a refusal will happen again on the
            // next run and a throttle will not, so folding them together points an
            // admin at permissions the guild may not have a problem with. This is
            // where a throttle is likeliest: the live pass issued eighty patches
            // against ten reads.
            //
            // The pointer to Discord's audit log is attached only when something was
            // actually removed. A run where every strip was refused removes nothing,
            // and sending an admin to an audit log that will not mention this run is
            // the same mistake in prose that reporting a refusal as a strip was in
            // arithmetic. A live-guild pass produced exactly that line: 0 stripped,
            // 80 refused.
            \XF::logError(sprintf(
                'Cav7/DiscordSyncPatch: the reconciliation sweep stripped forum-managed roles from %d guild member(s) on %s who hold no linked forum account, and Discord refused %d strip(s).%s%s',
                $stripped,
                $guildId,
                $refused,
                // Attached only when there were any, for the same reason the audit-log
                // pointer is: a clause about what the next run will retry, printed on a
                // run with nothing to retry, is a sentence that describes no event.
                $throttled > 0
                    ? sprintf(' It was rate-limited on %d more, which the next run will attempt again.', $throttled)
                    : '',
                $stripped > 0
                    ? ' Discord\'s own server audit log records each removal.'
                    : ''
            ));
        }

        return $divergentUserIds;
    }

    /**
     * Applies the strip decision to one unlinked holder.
     *
     * @param string[] $heldRoleIds
     * @param string[] $managedRoleIds
     * @param string[] $immovableRoleIds
     *
     * @return string One of the STRIP_ constants. They are counted apart because they
     *                mean different things: only STRIP_DONE is a correction, and the
     *                other two must never be reported as one.
     */
    protected function stripUnlinkedHolder(
        Api $api,
        string $guildId,
        string $discordId,
        array $heldRoleIds,
        array $managedRoleIds,
        array $immovableRoleIds
    ): string
    {
        $keepRoleIds = ManagedRoleStrip::rolesToKeep($heldRoleIds, $managedRoleIds, $immovableRoleIds);

        // null is not an empty set. Most of the guild holds no managed role at all, and
        // patching them would rewrite thousands of members to exactly what they already
        // have, against a budget near sixty role writes a minute.
        if ($keepRoleIds === null) {
            return self::STRIP_NOT_NEEDED;
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
        //
        // Its result is read rather than discarded. Api::request() returns false for
        // every way this fails — a connect or server exception, a 4xx, a 401 or 403, a
        // body it could not decode — and returns the member object or true otherwise.
        // Discarding it would have the run report a strip for a call Discord refused,
        // which is the one thing the per-run log line exists to be trusted about. The
        // refusal to expect is a role set omitting a role Discord manages: it is
        // rejected whole, and nothing else records that.
        $patched = $api->patchGuildMemberRoles($discordId, $keepRoleIds);

        if ($patched !== false) {
            return self::STRIP_DONE;
        }

        // A refusal and a throttle are both `false` and both moved no role, but they
        // say opposite things about the next run: Discord will refuse the same role
        // set again, and would have served the same throttled call given more room.
        return $this->wasThrottled($api) ? self::STRIP_THROTTLED : self::STRIP_REFUSED;
    }

    /**
     * The whole guild's member list, or null if nothing usable could be read.
     *
     * Every way out of the walk that is not "reached the end" says so, and says which
     * one it was. A partial list is not an error — the members it holds are still
     * reconciled and the rest wait for the next run — but it is never silent, because
     * the difference between a partial view and a complete one is the difference
     * between "no unlinked holders on this guild" and "none in the part we read".
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
            $result = $api->get('guilds/:guildId/members?' . http_build_query($query));

            // A throttled read comes back as the same false a refused one does, so the
            // retry-after is asked for before the result is judged. Read first because
            // it is the more specific answer: every throttled read is also unreadable,
            // and only this tells an admin the difference between waiting and fixing
            // the bot's intents.
            if ($this->wasThrottled($api)) {
                return $this->reportPartialWalk($guildId, $members, 'Discord rate-limited the bot');
            }

            if (!is_array($result)) {
                // Where a revoked GUILD_MEMBERS intent shows up: the endpoint refuses
                // and the vendor turns the refusal into a non-array.
                return $this->reportPartialWalk(
                    $guildId,
                    $members,
                    'a member page could not be read — this endpoint needs the bot to hold the GUILD_MEMBERS privileged intent'
                );
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

        return $this->reportPartialWalk(
            $guildId,
            $members,
            sprintf('the walk hit its %d-page bound with Discord still returning full pages', self::MAX_MEMBER_PAGES)
        );
    }

    /**
     * Whether Discord refused the call just made for pacing rather than on its merits.
     *
     * Every way a call can fail hands this addon the same `false`, so without this a
     * throttle is indistinguishable from a revoked intent or a missing permission —
     * one clears itself by the next quarter-hour and the others never will.
     *
     * The signal is the retry-after, not an exception. `RateLimitedException` cannot
     * reach this addon: `assertNotRateLimited()` throws only when `isThrowOnErrors()`
     * is true, that flag defaults to false, and nothing here sets it.
     * `Api::factory($guildId, false)` passes `$assertConfigured`, not a throw flag —
     * the easy misreading, and the one that had three unreachable catches in this file
     * until #233. What that method always does, throwing or not, is record the
     * retry-after.
     *
     * Ask immediately after the call it is about, and only of an Api that has been
     * asked for a per-call retry-after — which reconcileGuild() does, once, for exactly
     * this method's sake. The vendor writes the field per call but does not clear it on
     * every path out of request(), so before #248 a 429 partway through a guild was
     * read again by every following connect failure or Discord 5xx until one reached
     * the vendor's own reset. Which paths, and why the fix is opt-in, are on
     * Cav7\DiscordSyncPatch\NF\Discord\Api rather than restated here.
     *
     * It is also only ever a 429. The vendor's header-derived branches in
     * `isRateLimited()` never fire, so there is no pre-emptive "nearly out of budget"
     * signal here and nothing to pace against — see issue #249.
     *
     * All of this was measured rather than reasoned about, in
     * docs/verification/reconciliation-sweep-guards.md, which is where the evidence
     * lives and the place to re-run after a vendor upgrade.
     */
    protected function wasThrottled(Api $api): bool
    {
        return $api->getRetryAfter() !== null;
    }

    /**
     * Records a walk that ended somewhere other than the end of the guild, naming the
     * cause rather than the last thing the loop happened to do.
     *
     * @param array<int, array> $members
     * @return array<int, array>|null
     */
    protected function reportPartialWalk(string $guildId, array $members, string $cause): ?array
    {
        \XF::logError(sprintf(
            'Cav7/DiscordSyncPatch: the reconciliation sweep read %d member(s) of guild %s before stopping, because %s. %s',
            count($members),
            $guildId,
            $cause,
            $members
                ? 'The members it did read were reconciled; the rest wait for the next run.'
                : 'No Discord-side divergence or unlinked holders were checked this run.'
        ));

        return $members ?: null;
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
                   sync_log.user_error_phrase
            FROM xf_nf_discord_sync_log AS sync_log
            WHERE sync_log.guild_id = ?
        ', [$guildId]);

        // The linked members' groups, read once for the run. Presence in that map is
        // itself the connected-account requirement — it is built from xf_user joined to
        // the nfDiscord accounts — so this needs neither join of its own. A row whose
        // member is missing from it belongs to someone who has unlinked or been
        // deleted, and the unlinked-holder half covers them from the Discord side.
        $currentGroupIdsByUserId = $this->currentGroupIdsByUserId();

        $divergent = [];
        foreach ($rows as $row) {
            $userId = (int) $row['user_id'];
            if (!isset($currentGroupIdsByUserId[$userId])) {
                continue;
            }

            $isStale = SyncRecordStaleness::isStale(
                $this->splitList($row['user_group_ids']),
                $currentGroupIdsByUserId[$userId],
                (bool) $row['active'],
                $row['user_error_phrase']
            );

            if ($isStale) {
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
     * The roles the bot itself holds on this guild, or null if that could not be read.
     *
     * Two calls, because bots may not read `users/@me/guilds/{id}/member` and the
     * vendor works around it by finding the current user and then looking that id up
     * as an ordinary guild member. One extra round trip per guild per run, against a
     * budget near sixty a minute and a walk that already spends nine.
     *
     * Read here rather than picked out of the member walk deliberately. A partial walk
     * is a normal outcome — a throttle, a revoked intent, the page bound — and taking
     * the ceiling from it would make a decision input hostage to how far the walk got,
     * leaving a run with members to judge and nothing to judge them against.
     *
     * @return string[]|null
     */
    protected function botRoleIds(Api $api): ?array
    {
        $member = $api->getCurrentGuildMember();

        if (!is_array($member) || !isset($member['roles']) || !is_array($member['roles'])) {
            return null;
        }

        return array_map('strval', $member['roles']);
    }

    /**
     * Records the managed roles this bot cannot move, once per guild per run.
     *
     * Unlike a preserved role, which is permanent and correct, this is a
     * misconfiguration: a user group grants a role the bot is positioned below, so that
     * role is not being enforced for anybody and one drag in the role list fixes it.
     * Excluding those roles silently would leave the board quietly not enforcing them
     * with every run reporting clean — the failure this whole change exists to end.
     *
     * The ids are named because they are what the remedy acts on. The line repeats
     * every run while the condition lasts, which is deliberate: it stops the moment the
     * role is moved, and an admin reading the last hour of the error log needs to see a
     * live problem rather than infer one from a silence.
     *
     * @param string[] $outOfReachManagedRoleIds
     */
    protected function reportOutOfReach(string $guildId, array $outOfReachManagedRoleIds): void
    {
        if (!$outOfReachManagedRoleIds) {
            return;
        }

        \XF::logError(sprintf(
            'Cav7/DiscordSyncPatch: on guild %s, %d role(s) a user group grants sit at or above this bot\'s own highest role, so Discord refuses to add or remove them and the sweep leaves them alone: %s. Move the bot\'s role above them in Server Settings > Roles and the next run will enforce them.',
            $guildId,
            count($outOfReachManagedRoleIds),
            implode(', ', $outOfReachManagedRoleIds)
        ));
    }

    /**
     * The guild's roles, or null if they could not be read.
     *
     * Read because nothing on the Discord side can be decided without them: which roles
     * Discord manages itself, and which sit at or above the bot, both come out of this
     * one list. Sending a set that omits either kind makes Discord refuse the whole
     * call, and the vendor swallows that refusal, so the write would be lost with
     * nothing recorded.
     *
     * Fails closed. The vendor's getRoles() returns [] for a request that failed just
     * as readily as for one that succeeded — `$this->get(...) ?: []` — so an empty
     * result cannot be taken at face value. Read as "this guild has no protected
     * roles", a failed fetch would have the sweep send every booster a role set with
     * their booster role missing: the exact call Discord refuses and the vendor
     * swallows, repeated every quarter-hour with nothing logged anywhere.
     *
     * An empty list is unambiguous evidence of that failure rather than a guess, since
     * every guild carries at least the @everyone role.
     *
     * @return array<int, array>|null The guild's roles, or null if unreadable.
     */
    protected function guildRoles(Api $api): ?array
    {
        // Read fresh, not from the vendor's cache. getRoles() saves whatever it got for
        // five minutes INCLUDING the `?: []` it substitutes for a failed request, and
        // serves that back without re-requesting — so one failed read would keep the
        // guard below tripping on a cached empty rather than on Discord's answer. One
        // request per guild per run is nothing against a budget near sixty a minute.
        $roles = $api->getRoles(false);

        if (!$roles) {
            return null;
        }

        return $roles;
    }

    /**
     * Every Discord role each user group grants, in the stored form. RoleScope narrows
     * them to one guild.
     *
     * @return array<int, string[]> Keyed by user group id.
     */
    protected function mappedRoleIdsByGroup(): array
    {
        if ($this->mappedRoleIdsByGroup !== null) {
            return $this->mappedRoleIdsByGroup;
        }

        $rows = \XF::db()->fetchPairs("
            SELECT user_group_id, nfd_server_group_ids
            FROM xf_user_group
            WHERE nfd_server_group_ids <> ''
        ");

        $byGroup = [];
        foreach ($rows as $groupId => $mapped) {
            $byGroup[(int) $groupId] = $this->splitList($mapped);
        }

        return $this->mappedRoleIdsByGroup = $byGroup;
    }

    /**
     * Every role id any user group grants, on any server, flattened out of the
     * per-group lists. RoleScope narrows them to the guild in hand.
     *
     * @param array<int, string[]> $groupRoleIds
     * @return string[]
     */
    protected function allMappedRoleIds(array $groupRoleIds): array
    {
        $all = [];
        foreach ($groupRoleIds as $roleIds) {
            foreach ($roleIds as $roleId) {
                $all[] = $roleId;
            }
        }

        return $all;
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
        if ($this->linkedUserIdsByDiscordId !== null) {
            return $this->linkedUserIdsByDiscordId;
        }

        $rows = \XF::db()->fetchPairs('
            SELECT provider_key, user_id
            FROM xf_user_connected_account
            WHERE provider = ?
        ', ['nfDiscord']);

        $linked = [];
        foreach ($rows as $discordId => $userId) {
            $linked[(string) $discordId] = (int) $userId;
        }

        return $this->linkedUserIdsByDiscordId = $linked;
    }

    /**
     * @return array<int, int[]> Forum user id => their current group ids.
     */
    protected function currentGroupIdsByUserId(): array
    {
        if ($this->currentGroupIdsByUserId !== null) {
            return $this->currentGroupIdsByUserId;
        }

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

        return $this->currentGroupIdsByUserId = $groups;
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
