<?php

namespace Cav7\DiscordSyncPatch\XF\Pub\Controller;

use XF\Mvc\Reply\AbstractReply;

/**
 * Issue #158 — a member-facing button that queues a resync of that member's own
 * Discord roles, replacing the standing instruction to disconnect and reconnect
 * their Discord account. Reconnecting only ever worked because inserting a
 * connected-account row makes NF/Discord queue a per-user sync; this asks for that
 * sync directly, without revoking the OAuth link a member may be logging in with.
 *
 * It covers the role sync, which is the part the standing instruction was reached
 * for, and not everything a reconnect does. NF/Discord's UserConnectedAccount queues
 * with queueSyncJobsForUser($user, $this->isInsert()), so a reconnect runs with the
 * asNew flag set and this does not. SyncUser::dispatch() gates its join path on that
 * flag — the read is in dispatch(), not in the syncRoles() this addon overrides — so
 * a member who has LEFT a guild would be re-added by a reconnect on an auto-join
 * server and is not re-added by this.
 *
 * The action hangs off the public account controller and needs no route of its own.
 * The core account/connected-accounts route carries action_prefix=connectedAccount,
 * and XF\Mvc\Router::suffixMatchesRoute() prepends that prefix to whatever trails
 * the part of the path the route format matched — here "discord-resync".
 * XF\Mvc\Dispatcher then normalises the casing of the resulting action name, and
 * PHP's case-insensitive method lookup binds it to the method below. NF/Discord's
 * own actionConnectedAccountDiscordReconnect gets its name the same way, on the same
 * controller, which XenForo chains with this extension.
 *
 * A resync runs blind, with no divergence check first, and what the two limits in
 * front of it are for is recorded in ADR-0005. The cooldown is XenForo's own flood
 * check, which does not apply to anyone holding general:bypassFloodCheck. The
 * pending check does apply to them, but it lapses the moment the queue drains, so
 * what it bounds is duplicate work in flight rather than how often anyone can press.
 * Nothing here rate-limits a bypass holder.
 *
 * The pending check is check-then-act, and only one of its two kinds of caller makes
 * that safe. For an ordinary member the cooldown behind it is atomic — checkFlooding()
 * decides on the row count of an UPDATE and then an INSERT IGNORE — so a second press
 * cannot get past it while the first is still in flight. A bypass holder never
 * reaches that: assertNotFlooding() returns before it touches the database at all,
 * which leaves the plain SELECT below as the only bound, and a plain SELECT
 * serialises nothing. Two genuinely concurrent posts from one bypass holder can
 * therefore both fan out. Presses made one after another are refused as intended. No
 * lock is taken for it: the cost is one duplicate fan-out for someone who
 * double-submits, which the queue absorbs, and ADR-0005 already puts that class of
 * waste inside what the guards accept.
 *
 * Assumptions this makes about vendor internals:
 *
 *  - queueSyncJobsForUser() fans a per-user sync out across the server map and
 *    returns nothing, so it cannot report failure. With an empty server map it
 *    iterates nothing and queues nothing while still returning normally, which is
 *    why the map is read up front and why the queue row is checked for afterwards
 *    rather than trusted.
 *  - it does not refuse a member with no linked Discord account either. The
 *    message's setupFromUser() returns a Noop before it ever records a user id, the
 *    repository drops that return and queues the original message anyway, and the
 *    row lands with a null user_id that the lookup below cannot see. So the link is
 *    checked here, ahead of everything else.
 *  - it does not refuse an integration with no credentials either, and that one
 *    wedges. Api::getDiscordConfiguration() is null on an empty token, client id,
 *    client secret or discord_server_id option, none of which the server rows can
 *    show. The fan-out still queues a row with this member's user_id, so the pending
 *    lookup finds it and the member is told the resync is on its way, while
 *    Repository\Queue::run() opens on Api::factory(null, false), gets null, and
 *    returns before it reads the queue. Nothing then removes the row, run()'s own
 *    age-out included, so the pending guard here refuses every later press
 *    indefinitely. That lockout is this addon's guard, so the configuration is
 *    checked here as a precondition of its own.
 *  - queueServerSyncJobForUser() sets the nfDiscordJustAssociated session flag
 *    whenever it queues for the visitor themselves, which a resync always is. The
 *    vendor's connected-account renderer reads that flag as "this member just linked
 *    their account". A resync is not a link, so the flag is cleared below — but that
 *    settles the flag, not the page. The vendor template tests
 *    $justAssociated || ($isSyncing && $server.canAutoJoinUser($user)), and the right
 *    arm is built from $syncingServers, which the renderer derives from the very
 *    rows this action just wrote. So on an auto-join server, for a member in an
 *    auto-join group, the connected-accounts page this redirects to still says
 *    "Join pending" and still withholds the Join/Rejoin link, while the resync
 *    queues with asNew false and SyncUser::dispatch() never enters the join path.
 *    Those rows are the vendor's to read and there is no suppressing them from here,
 *    so the note beside the button says what a resync covers and what it does not.
 *  - a queued message is stored under its root class name, so the lookup matches on
 *    NF\Discord\ApiMessage\SyncUser even though this addon extends that class.
 *  - the connected-account renderer builds $syncingServers from the same predicate,
 *    but binds it to the user it was handed where this binds the visitor. The
 *    template modification draws the button only when those are the same person, so
 *    wherever the note renders it agrees with what the action itself decides.
 */
class Account extends XFCP_Account
{
    /**
     * One resync per member per five minutes. A constant rather than an option: the
     * addon has no options, no schema and no setup class, so reverting it stays a
     * single toggle.
     */
    protected const RESYNC_COOLDOWN_SECONDS = 300;

    /**
     * Keyed to this action alone, and kept short enough to survive being written:
     * xf_flood_check.flood_action is varchar(25), and a key that gets truncated on
     * the way in stops matching the untruncated one the read looks for.
     */
    protected const RESYNC_FLOOD_ACTION = 'cav7_discord_resync';

    /**
     * How long a queued row still counts as work in flight. Matched to the vendor's
     * own abandonment threshold rather than chosen: Repository\Queue::run() archives
     * any entry with queue_date < \XF::$time - 86400 without a Discord round trip, so
     * past this age a row would be thrown away on sight rather than run.
     *
     * The bound is what stops one swallowed job enqueue becoming a permanent lockout.
     * Queue::queueMessage() inserts the row and then calls enqueueJob(), whose
     * enqueueLater() sits inside an empty catch — the vendor's comment names a
     * deadlock on xf_job. The insert survives, the job does not, and nothing re-drives
     * it: none of NF/Discord's three cron entries reads xf_nf_discord_queue, and
     * run()'s own age-out only runs inside the job that was never enqueued. Unbounded,
     * the pending guard below would then refuse this member every press, forever, with
     * nothing written anywhere to say why.
     */
    protected const RESYNC_PENDING_MAX_AGE_SECONDS = 86400;

    public function actionConnectedAccountDiscordResync(): AbstractReply
    {
        $this->assertPostOnly();

        $visitor = \XF::visitor();
        $connectedAccountsLink = $this->buildLink('account/connected-accounts');

        // The first precondition, ahead of both guards. The template only offers the
        // button to a linked member, but that is markup, not a guard: the endpoint
        // takes a post from any member with a CSRF token, linked or not. What queueing
        // without a link leaves behind is in
        // the docblock; ask first, and ask before the cooldown, so an unlinked member
        // does not spend one on it either.
        if (empty($visitor->ConnectedAccounts['nfDiscord'])) {
            return $this->error(\XF::phrase('cav7_discord_resync_not_linked'));
        }

        // The second precondition, and the one that wedges if it is skipped: it ends
        // in a permanent, silent lockout, which the docblock traces call by call.
        // Note that the server rows below cannot stand in for it — none of the four
        // settings it reads live on them. Asked here, the first press says so.
        // Cheap, too: the provider entity is one find the request has usually made
        // already, and asking before the server map skips the cache rewrite below for
        // a forum that could not sync either way.
        if (\NF\Discord\Api::getDiscordConfiguration() === null) {
            return $this->error(\XF::phrase('cav7_discord_resync_not_configured'));
        }

        // The third precondition, here for the same reason. The fan-out iterates the
        // server map, so an empty map queues nothing however often it is pressed. An
        // empty map is a narrower fact than "no server is configured": getServerMap()
        // falls through to updateServerCache(), which selects
        // findServersForList()->isActive(), so a server row that exists with a guild
        // id and active = 0 arrives here too. The phrase says active for that reason.
        // Asking after the fact instead would spend the member's cooldown on a fault
        // no retry can clear, and would append an xf_error_log row per press with
        // nothing bounding how many: XF\Error::logException() inserts every call with
        // no dedupe, and assertNotFlooding() returns before FloodCheckService
        // ::checkFlooding() writes anything for a general:bypassFloodCheck holder, so
        // for those members there is no flood entry to withhold in the first place.
        // Asking here is not free: getServerMap() falls through to updateServerCache()
        // whenever the map is empty, which is exactly this case, so every press pays
        // a Finder query over the server list plus a rewrite of the nfDiscordServers
        // and nfDiscordConfigured registry keys. It is still the cheaper of the two,
        // because a registry write overwrites one key rather than appending a row:
        // repeated presses leave the same two rows behind, where the alternative
        // leaves a spent cooldown and a fresh error-log row each time. And the phrase
        // carries the diagnosis to staff on the member's behalf. This is a standing
        // fault staff can see in the admin panel, not an event a log has to preserve.
        // array_filter() drops the guild-id-less rows, because the map is not the same
        // thing as the set of guilds a sync can reach. updateServerCache() applies
        // isActive() and stops; it does not apply the vendor's own hasGuildId(), which
        // Finder\Server defines as where('guild_id', '!=', '') for exactly this case.
        // Such a row is reachable and not exotic: active defaults to 1, and the
        // vendor's own upgrade step inserts 'guild_id' => $options['guild_id'] ?? ''
        // through a raw db()->insert with 'ignore', bypassing the entity's
        // required => true. Left in, it is the worst outcome this action has, because
        // every layer reports success: Api::factory('', false) skips the null
        // bail-out, a row queues with the right user_id, the pending lookup finds it,
        // the member is told the resync is queued — and on drain
        // SyncUser::dispatch() reads the empty guild id and returns TRUE, so
        // Queue::run() archives it as a success with no error log and no fail count.
        // The pending guard clears when the row archives, so it repeats forever while
        // no role ever moves and nothing anywhere says so.
        $serverRepo = $this->repository(\NF\Discord\Repository\Server::class);
        $serverMap = array_filter($serverRepo->getServerMap());
        if (!$serverMap) {
            return $this->error(\XF::phrase('cav7_discord_resync_no_servers'));
        }

        // First guard. One press already fans a message out per guild, so a second
        // press stacks a second set for work the first set has not run yet.
        if ($this->hasPendingDiscordSync($visitor->user_id)) {
            return $this->redirect(
                $connectedAccountsLink,
                \XF::phrase('cav7_discord_resync_pending')
            );
        }

        // Second guard. It refuses by throwing, so nothing past this line runs on a
        // refusal, and it refuses with the time remaining — the answer a member who
        // pressed twice wants, which is when rather than just no.
        $this->assertNotFlooding(self::RESYNC_FLOOD_ACTION, self::RESYNC_COOLDOWN_SECONDS);

        $syncRepo = $this->repository(\NF\Discord\Repository\Sync::class);
        $syncRepo->queueSyncJobsForUser($visitor);

        // The vendor's fresh-link flag, cleared for the reason in the docblock.
        \XF::session()->remove('nfDiscordJustAssociated');

        // The queueing call cannot fail loudly, so a row is the only evidence.
        if (!$this->hasPendingDiscordSync($visitor->user_id)) {
            // The three causes anyone can name have already returned above: no link,
            // no credentials, no active server. Reaching here means the map held an
            // active server, the integration had its credentials, the fan-out ran,
            // and no row is there — which nothing left accounts for. Say that, rather
            // than offer a theory. The nearest theory is that the queue drained in
            // between, and it is weak rather than impossible. A row can leave
            // xf_nf_discord_queue two ways: Repository\Queue::archiveQueueEntry(),
            // which run() also calls without a Discord round trip for a group-tainted
            // entry, for one older than 86400s and at fail_count >= 3; and
            // Entity\Server::_postDelete(), which archives and deletes every row for
            // a deleted server's guild. That second one does overlap this window —
            // staff deleting the last server between the fan-out and this re-check
            // would both remove the rows and make the server-map precondition
            // retroactively the cause. For a row inserted milliseconds ago, none of
            // it is more than a guess, and the log says so rather than sending staff
            // off after one. The member is being sent to staff, so this row is what
            // staff have to go on: it names them, and it carries the map.
            //
            // It reports the map this action read, and says so, rather than claiming
            // the fan-out ran over it. queueSyncJobsForUser() takes no map: it calls
            // getServerMap() again itself, so the two are independent reads of a table
            // any concurrent request can rewrite through Entity\Server::_postSave() or
            // _postDelete(). This branch is the one where they most plausibly
            // disagreed, so asserting they matched would rule out the first thing
            // staff should check.
            //
            // Forced, because the default drops it. XF\Error::logException() returns
            // without writing when hasPendingUpgrade() is true, which covers any row
            // in xf_addon carrying is_processing = 1 — set for the length of every
            // add-on install, upgrade, uninstall and rebuild, with the forum still
            // serving members throughout. An NF/Discord upgrade is both the likeliest
            // moment for this branch to fire and a window where the flag is set, and
            // dropping the row there sends the member to staff with nothing to show.
            \XF::logError(sprintf(
                'Cav7/DiscordSyncPatch: Discord resync for user %d queued nothing. The server map read before the fan-out held %d syncable guild(s) [%s]; the fan-out re-reads it and may have seen another. Cause unknown, please investigate.',
                $visitor->user_id,
                count($serverMap),
                implode(', ', $serverMap)
            ), true);

            // The press bought the member nothing, so it costs them nothing. There is
            // no volume argument against that here, but not because nothing unbounded
            // is written: the forced \XF::logError() call above inserts through
            // XF\Error::logException() per call with no dedupe, and a
            // general:bypassFloodCheck holder has no flood entry to spend in the
            // first place, so the cooldown was never bounding them. The defence is
            // that this branch is near-unreachable: the three standing faults return
            // above it, and what is left is a case nothing accounts for, which is why
            // the line above it logs rather than explains.
            $this->releaseResyncCooldown($visitor->user_id);

            return $this->error(\XF::phrase('cav7_discord_resync_unavailable'));
        }

        // No timeframe is promised. The sync drains behind a queue that yields to
        // Discord's rate limits, so any number given here would be a guess the
        // member would read as a deadline.
        return $this->redirect(
            $connectedAccountsLink,
            \XF::phrase('cav7_discord_resync_queued')
        );
    }

    /**
     * Whether a per-user sync is already waiting in NF/Discord's queue for this
     * member, on any guild. Same table and same predicate as the lookup the vendor's
     * connected-account renderer makes to build its own pending indicator; that one
     * selects the guild ids and takes no LIMIT, because it needs the set where this
     * needs only whether the set is empty.
     */
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
            \XF::$time - self::RESYNC_PENDING_MAX_AGE_SECONDS,
        ]);
    }

    /**
     * Clears this member's flood entry for this action, where there is one to clear.
     * A member who got past the cooldown check holds exactly one row for this action
     * afterwards, whether the check refreshed an old row or inserted a new one; a
     * member holding general:bypassFloodCheck writes none on this request, because
     * the check returns before it writes anything. So on the request that calls it
     * this either removes exactly what the check wrote or removes nothing. It is not
     * quite "nothing else can be there": a row written by a press made before the
     * member gained the permission survives up to a day and this clears that too,
     * which costs nobody anything, since the row it clears is a cooldown on this
     * action alone. Scoped to that one action, so nothing else the member is waiting
     * on is handed back with it.
     */
    protected function releaseResyncCooldown(int $userId): void
    {
        \XF::db()->delete(
            'xf_flood_check',
            'user_id = ? AND flood_action = ?',
            [$userId, self::RESYNC_FLOOD_ACTION]
        );
    }
}
