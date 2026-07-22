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
 * The action hangs off the public account controller, under the connected-accounts
 * path, so it needs no route of its own: XenForo turns the trailing path segment
 * into the action name. NF/Discord extends the same controller for its join-server
 * and reconnect actions, and XenForo chains extensions, so both apply.
 *
 * A resync runs blind, with no divergence check first — see ADR-0005. What it does
 * have is two guards, and neither is about correctness: both exist so that one
 * member cannot spend the forum's Discord rate limit. Staff normally hold the
 * flood-bypass permission, so the cooldown does not reach them; the pending check
 * is what stops an administrator stacking redundant messages.
 *
 * Assumptions this makes about vendor internals:
 *
 *  - queueSyncJobsForUser() fans a per-user sync out across the server map and
 *    returns nothing, so it cannot report failure. With an empty server map it
 *    iterates nothing and queues nothing while still returning normally, which is
 *    why the queue row is checked for afterwards rather than trusted.
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
    public const RESYNC_COOLDOWN_SECONDS = 300;

    /** Keyed to this action alone. xf_flood_check.flood_action is varchar(25). */
    protected const RESYNC_FLOOD_ACTION = 'cav7_discord_resync';

    public function actionConnectedAccountDiscordResync(): AbstractReply
    {
        $this->assertPostOnly();

        $visitor = \XF::visitor();
        $connectedAccountsLink = $this->buildLink('account/connected-accounts');

        // First guard. A second message would only repeat the work the pending one
        // is about to do, and repeated presses would stack them.
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

        // The queueing call cannot fail loudly, so the row is the evidence. Say so
        // plainly when there is none rather than report a success the member would
        // wait on, and hand the cooldown back so the retry is not five minutes away.
        if (!$this->hasPendingDiscordSync($visitor->user_id)) {
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
     * Drops the flood entry the cooldown check just wrote. Scoped to this member and
     * this action, so nothing else a member is waiting on is handed back with it.
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
