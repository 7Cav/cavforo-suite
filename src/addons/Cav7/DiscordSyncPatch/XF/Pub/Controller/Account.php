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
 *  - queueServerSyncJobForUser() sets the nfDiscordJustAssociated session flag
 *    whenever it queues for the visitor themselves, which a resync always is. The
 *    vendor's connected-account renderer reads that flag as "this member just linked
 *    their account" and replaces the Join link with a join-pending note on every
 *    joinable server. It also consumes the flag as it reads it, so leaving it set
 *    costs exactly one render rather than an open-ended wrong state — and that one
 *    render is the connected-accounts page this action redirects to, which makes it
 *    the single worst render to get wrong. The member is sent straight to it and
 *    reads a claim about their guild membership that nothing did. A resync is not a
 *    link, so the flag is cleared again below.
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

        // The other precondition, here for the same reason. The fan-out iterates the
        // server map, so an empty map queues nothing however often it is pressed.
        // Asking after the fact instead would spend the member's cooldown on a fault
        // no retry can clear, and would append an xf_error_log row per press with
        // nothing bounding how many: XF\Error::logException() inserts every call with
        // no dedupe, and assertNotFlooding() returns before FloodCheckService
        // ::checkFlooding() writes anything for a general:bypassFloodCheck holder, so
        // for those members there is no flood entry to withhold in the first place —
        // and on this forum that permission reaches ordinary member groups. Asked
        // here it costs one read, and the phrase carries the diagnosis to staff on
        // the member's behalf. This is a standing fault staff can see in the admin
        // panel, not an event a log has to preserve.
        $serverRepo = $this->repository(\NF\Discord\Repository\Server::class);
        if (!$serverRepo->getServerMap()) {
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
            // Every cause anyone can name has already returned above: no link, and no
            // configured server. Reaching here means the map held servers, the
            // fan-out ran over them, and no row is there — which nothing accounts
            // for. Say that, rather than offer a theory. The obvious theory, that the
            // queue drained in between, does not survive being looked at: a row
            // leaves xf_nf_discord_queue only through Repository\Queue
            // ::archiveQueueEntry(), which runs after a completed Discord round trip,
            // and the window here is one session write. The member is being sent to
            // staff, so this row is what staff have to go on, and it names them.
            \XF::logError(sprintf(
                'Cav7/DiscordSyncPatch: Discord resync for user %d queued nothing, with servers configured and the fan-out having run. Cause unknown — please investigate.',
                $visitor->user_id
            ));

            // The press bought the member nothing, so it costs them nothing. No
            // volume argument holds it back any more: the branch that a retry would
            // repeat identically now returns before the cooldown is reached, and
            // nothing unbounded is written on the way here.
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
            WHERE class_name = ? AND user_id = ?
            LIMIT 1
        ', [\NF\Discord\ApiMessage\SyncUser::class, $userId]);
    }

    /**
     * Clears this member's flood entry for this action, where there is one to clear.
     * A member who got past the cooldown check holds exactly one row for this action
     * afterwards, whether the check refreshed an old row or inserted a new one; a
     * member holding general:bypassFloodCheck holds none, because the check returns
     * before it writes anything. So this either removes exactly what the check wrote
     * or removes nothing. Scoped to the one action, so nothing else the member is
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
