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
 * The action hangs off the public account controller and needs no route of its own.
 * The core account/connected-accounts route carries action_prefix=connectedAccount,
 * and XF\Mvc\Router::suffixMatchesRoute() prepends that prefix to whatever trails
 * the part of the path the route format matched — here "discord-resync".
 * XF\Mvc\Dispatcher then normalises the casing of the resulting action name, and
 * PHP's case-insensitive method lookup binds it to the method below. NF/Discord's
 * own actionConnectedAccountDiscordReconnect gets its name the same way, on the same
 * controller, which XenForo chains with this extension.
 *
 * A resync runs blind, with no divergence check first — see ADR-0005. In front of it
 * sit a precondition and two limits. Neither limit is about correctness: both exist
 * so that one member cannot spend the forum's Discord rate limit. Do not read the
 * cooldown as a limit every member meets — general:bypassFloodCheck is granted by
 * ordinary member groups on this forum, not only staff ones, so in that
 * configuration the pending check is the only guard that binds anybody. Whether that
 * grant is right is #167, not this.
 *
 * Assumptions this makes about vendor internals:
 *
 *  - queueSyncJobsForUser() fans a per-user sync out across the server map and
 *    returns nothing, so it cannot report failure. With an empty server map it
 *    iterates nothing and queues nothing while still returning normally, which is
 *    why the queue row is checked for afterwards rather than trusted.
 *  - it does not refuse a member with no linked Discord account either. The
 *    message's setupFromUser() returns a Noop before it ever records a user id, the
 *    repository drops that return and queues the original message anyway, and the
 *    row lands with a null user_id that the lookup below cannot see. So the link is
 *    checked here, ahead of everything else.
 *  - queueServerSyncJobForUser() sets the nfDiscordJustAssociated session flag
 *    whenever it queues for the visitor themselves, which a resync always is. The
 *    vendor's connected-account renderer reads that flag as "this member just linked
 *    their account" and replaces the Join link with a join-pending note on every
 *    joinable server. A resync is not a link, so the flag is cleared again below.
 *  - a queued message is stored under its root class name, so the lookup matches on
 *    NF\Discord\ApiMessage\SyncUser even though this addon extends that class.
 *  - the connected-account renderer builds $syncingServers from the same lookup, so
 *    the note beside the button agrees with what the action itself decided.
 */
class Account extends XFCP_Account
{
    /**
     * One resync per member per five minutes. A constant rather than an option: the
     * addon has no options, no schema and no setup class, so reverting it stays a
     * single toggle.
     */
    protected const RESYNC_COOLDOWN_SECONDS = 300;

    /** Keyed to this action alone. xf_flood_check.flood_action is varchar(25). */
    protected const RESYNC_FLOOD_ACTION = 'cav7_discord_resync';

    public function actionConnectedAccountDiscordResync(): AbstractReply
    {
        $this->assertPostOnly();

        $visitor = \XF::visitor();
        $connectedAccountsLink = $this->buildLink('account/connected-accounts');

        // The precondition, ahead of both guards. The template only offers the
        // button to a linked member, but that is markup, not a guard: the endpoint
        // takes a post from anyone. Queueing without a link writes one unusable row
        // per guild that neither guard below can see, so ask first — and ask before
        // the cooldown, so an unlinked member does not spend one on it either.
        if (empty($visitor->ConnectedAccounts['nfDiscord'])) {
            return $this->error(\XF::phrase('cav7_discord_resync_not_linked'));
        }

        // First guard. One press already fans a message out per guild, so a second
        // press stacks a second set for work the first set has not run yet.
        if ($this->hasPendingDiscordSync($visitor->user_id)) {
            return $this->redirect(
                $connectedAccountsLink,
                \XF::phrase('cav7_discord_resync_pending')
            );
        }

        // Second guard. Refuses with the time remaining, which is the answer the
        // member wants: pressing twice in a row should say when, not just no.
        $this->assertNotFlooding(self::RESYNC_FLOOD_ACTION, self::RESYNC_COOLDOWN_SECONDS);

        $syncRepo = \SV\StandardLib\Helper::repository(\NF\Discord\Repository\Sync::class);
        $syncRepo->queueSyncJobsForUser($visitor);

        // Queueing for yourself is how the vendor spots a fresh link, and it records
        // that in the session. Left set, the page tells the member a join is pending
        // on every server they have not joined and takes the Join link away.
        \XF::session()->remove('nfDiscordJustAssociated');

        // The queueing call cannot fail loudly, so the row is the evidence. Say so
        // plainly when there is none rather than report a success the member would
        // wait on, and hand the cooldown back so the retry is not five minutes away.
        if (!$this->hasPendingDiscordSync($visitor->user_id)) {
            // The member is told to go to staff, so leave staff something to read.
            // An empty server map is the one configuration fault that lands here.
            $serverRepo = \SV\StandardLib\Helper::repository(\NF\Discord\Repository\Server::class);
            \XF::logError(sprintf(
                'Cav7/DiscordSyncPatch: Discord resync for user %d queued nothing; NF/Discord\'s server map holds %d server(s)',
                $visitor->user_id,
                count($serverRepo->getServerMap())
            ));

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
     * member, on any guild. The same lookup the vendor's connected-account renderer
     * makes to build its own pending indicator.
     */
    protected function hasPendingDiscordSync(int $userId): bool
    {
        return (bool) \XF::db()->fetchOne('
            SELECT queue_id
            FROM xf_nf_discord_queue
            WHERE class_name = ? AND user_id = ?
            LIMIT 1
        ', [\NF\Discord\ApiMessage\SyncUser::class, $userId]);
    }

    /**
     * Clears this member's flood entry for this action, where there is one to clear:
     * the cooldown check writes nothing at all for a member holding
     * general:bypassFloodCheck, and refreshes an existing row at least as often as it
     * writes a new one. Scoped to the one action, so nothing else the member is
     * waiting on is handed back with it.
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
