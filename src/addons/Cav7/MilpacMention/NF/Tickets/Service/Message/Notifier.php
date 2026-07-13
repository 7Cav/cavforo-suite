<?php

namespace Cav7\MilpacMention\NF\Tickets\Service\Message;

use Cav7\MilpacMention\MilpacStash;
use XF\Entity\User;
use XF\Repository\UserAlertRepository;

/**
 * The NF/Tickets ticket-message firing extension (spec §2.4) — the fifth and last
 * mention surface (spec §2.1). After the stock notifier pass (@-mention, quote,
 * queue/category/ticket watch), this raises the distinct milpac_mention action on
 * content type nf_tickets_message for the milpac-only recipients the shared detection
 * hook stashed. It reuses the stock NF\Tickets\Alert\Message handler — no new content
 * type and no new handler — so the alert deep-links to the ticket message and renders
 * the alert_nf_tickets_message_milpac_mention template for free; only the action
 * string is new.
 *
 * SOFT DEPENDENCY (spec §1, §2.1). NF/Tickets is NOT a hard require. This extension's
 * from_class is NF\Tickets\Service\Message\Notifier, so on a site without NF/Tickets
 * that class never loads, XF never calls extendClass() for it (and even if it did,
 * XF\Extension::extendClass fails safe when the base class is absent), so the XFCP
 * proxy is never built and this class never runs. The four core surfaces work
 * unchanged and this ticket surface stays inert. XF's class_extension has no per-row
 * addon-dependency guard; the from_class-never-loads dormancy IS the mechanism, the
 * same way Cav7/CalendarPatch and Cav7/RosterPatch sit dormant against their vendor
 * classes.
 *
 * §8.1 — the legacy alias does NOT bypass detection. NF/Tickets builds the shared
 * message preparer through the legacy XF\Service\Message\Preparer alias
 * (Service\Message\Preparer::getMessagePreparer -> Helper::service(
 * \XF\Service\Message\Preparer::class, 'nf_tickets_message', $this->message)). Both
 * XF\Extension::addClassExtension AND ::extendClass funnel every from_class through
 * XF::getClassForAlias, which appends the "Service" suffix to reach
 * XF\Service\Message\PreparerService — the exact class the shared detection hook
 * registers against. So NF/Tickets' own preparer extension (registered under the
 * alias) and our detection hook (registered under the canonical name) collapse to one
 * classExtensions key and build one XFCP chain; detection runs on the ticket
 * save/preparer path and actually stashes. It is not bypassed.
 *
 * SAME-INSTANCE STASH. The third preparer arg, $this->message, becomes the preparer's
 * getMessageEntity() — the Message the detection hook stashes against — and
 * Service\Ticket\Creator/Replier::sendNotifications() build THIS notifier with that
 * very same $this->message (Helper::service(Notifier::class, $this->message, ...)), so
 * take($message) reads back exactly what detection stashed. A resumed XF\Job\Notifier
 * loads a fresh Message with a new object id and an empty stash, so it fires nothing
 * (spec §2.5 rules 2 and 4).
 *
 * CONTAINMENT IS INLINE-ONLY, like the Report/ProfilePost surfaces — this does NOT
 * ride the job net (an earlier note claiming a Post-style deferred net was wrong).
 * Service\Ticket\Creator/Replier::sendNotifications() dispatch through
 * notifyAndEnqueue($timeLimit), which runs the FIRST notify() pass INLINE in the
 * member's request and only defers the overflow into XF\Job\Notifier. Milpac firing is
 * stash-inline-only: a resumed job rebuilds the service on a freshly loaded Message
 * with a new object id and an empty MilpacStash (see SAME-INSTANCE STASH above), so
 * take() returns nothing and fireMilpacMentions() fires on the inline first pass ONLY —
 * never under the job net. This surface therefore ALWAYS runs inline, after the ticket
 * action is committed, with no deferred-job protection — exactly the Report/ProfilePost
 * posture, NOT Post's. So it carries an OUTER try/catch around the pre-loop
 * findByIds()/repository() lookups and the recipient loop (a transient DB fault there
 * would otherwise 500 an already-committed ticket action and silently drop every
 * recipient), mirroring XF\Service\Report\NotifierService — plus the per-recipient
 * inner guard so one bad recipient cannot cost the rest their alert (matches
 * EnlistmentReminder\QueueReminder::alertClerks's best-effort send).
 */
class Notifier extends XFCP_Notifier
{
    public function notify($timeLimit = null)
    {
        parent::notify($timeLimit);

        $this->fireMilpacMentions();
    }

    protected function fireMilpacMentions()
    {
        // Deliberately mirrors the shared per-surface firing pattern established in
        // XF\Service\Post\NotifierService (XenForo's XFCP forces one class-split per
        // surface), so a firing-rule change must land in every surface extension.
        $message = $this->message;

        // Same-instance invariant (load-bearing): MilpacStash keys on
        // spl_object_id($message), so this take() only finds what the detection hook
        // (PreparerService::stashMilpacMentions) stashed on the SAME Message object
        // instance. Creator/Replier hold one $message across prepare()+notify(), so
        // the stash is found; a resumed Notifier job runs on a freshly loaded Message
        // with a new object id and an empty stash, so it fires nothing — the once-only
        // / no-edit-refire guarantee (spec §2.5 rules 2 and 4). take() is consuming, so
        // even a double notify() on the same object cannot double-fire. Do NOT re-key
        // the stash on message_id, or that guarantee breaks.
        $milpacUserIds = MilpacStash::take($message);
        if (!$milpacUserIds) {
            return;
        }

        // Outer containment: ticket-message notifications run notifyAndEnqueue()'s
        // FIRST notify() pass FULLY INLINE in the member's request (Service\Ticket\
        // Creator/Replier::sendNotifications), after the ticket action is already
        // saved+committed. Milpac firing is stash-inline-only — a resumed XF\Job\Notifier
        // loads a fresh Message with an empty stash and fires nothing — so this body
        // NEVER runs under the deferred-job net (unlike Post, which this surface does not
        // match). With no job net, the pre-loop findByIds()/repository() lookups need the
        // same guard as the loop: an uncaught failure (DB deadlock, dropped connection,
        // timeout) would otherwise become a 500 on an already-committed ticket action and
        // silently drop every milpac recipient. Mirrors XF\Service\Report\NotifierService.
        try
        {
            $users = \XF::em()->findByIds(User::class, $milpacUserIds, ['Profile', 'Option']);

            /** @var UserAlertRepository $alertRepo */
            $alertRepo = $this->app->repository(UserAlertRepository::class);

            foreach ($milpacUserIds as $userId)
            {
                // Contain each recipient: firing runs after parent::notify() on a ticket
                // message that is already saved+committed, so an alert()/canView() failure
                // must be logged and skipped, never surfaced on the member's reply action.
                // Per-recipient so one bad row cannot cost the rest their alert (matches
                // EnlistmentReminder\QueueReminder::alertClerks's best-effort send).
                try
                {
                    if (!isset($users[$userId]))
                    {
                        continue;
                    }

                    /** @var User $user */
                    $user = $users[$userId];

                    // Rule 1 at the firing edge: the stock NF\Tickets Mention::canNotify
                    // self-check ($user->user_id !== $message->user_id) does not run for the
                    // distinct action, so repeat it here against the REAL author (user_id),
                    // not the possibly-anonymized sender resolved below.
                    if ($user->user_id == $message->user_id)
                    {
                        continue;
                    }

                    // XF alerts a member once across a ticket message's notifiers; honour
                    // that so a milpac link never double-pings someone the stock pass
                    // (quote / @-mention / watch) alerted (rules 2 and 5). The
                    // AbstractNotifier shape tracks this in $this->alerted (as Post does),
                    // NOT the bespoke $this->usersAlerted of ProfilePost/Report.
                    if (!empty($this->alerted[$user->user_id]))
                    {
                        continue;
                    }

                    // Gating parity (§2.6): a member who cannot view the ticket message is
                    // filtered out, exactly as the stock notifier's canUserViewContent does
                    // (\XF::asVisitor($user, fn() => $message->canView())). Message::canView()
                    // delegates to the Ticket's canView() plus the message-state checks, so a
                    // member who cannot view the ticket gets no alert.
                    $canView = \XF::asVisitor($user, function () use ($message) {
                        return $message->canView();
                    });
                    if (!$canView)
                    {
                        continue;
                    }

                    // Sender attribution matches the stock ticket mention alert exactly:
                    // resolve the from-user through getAnonymousUser(true) UNDER the
                    // recipient's own visitor, so an anonymized ticket masks the author for
                    // recipients who may not see through it — the same call the stock
                    // NF\Tickets Message\Mention::sendAlert makes. The other four surfaces
                    // read $entity->user_id/username straight because their content types
                    // have no anonymous authors; tickets do, so this surface differs here.
                    $fromUser = \XF::asVisitor($user, function () use ($message) {
                        return $message->getAnonymousUser(true);
                    });

                    // Deep-link to the ticket message via the stock NF\Tickets\Alert\Message
                    // handler: content type 'nf_tickets_message', content id
                    // $message->message_id (the Message PK — the exact value the stock
                    // ticket mention alert uses).
                    //
                    // autoRead=false in the options arg (arg 8) keeps the milpac alert unread
                    // until the recipient views it, exactly as XF's own mention alerts do (the
                    // ticket surface inherits the same flag from XF\Notifier\AbstractNotifier);
                    // without it insertAlert() defaults auto_read=1 and the alert clears on a
                    // different schedule than the @-mention it mirrors (spec §2.5).
                    // depends_on_addon_id stays in the extra arg (arg 7) — insertAlert() reads
                    // the two from separate slots.
                    $sent = $alertRepo->alert(
                        $user,
                        $fromUser->user_id,
                        $fromUser->username,
                        'nf_tickets_message',
                        $message->message_id,
                        'milpac_mention',
                        ['depends_on_addon_id' => 'Cav7/MilpacMention'],
                        ['autoRead' => false]
                    );

                    if ($sent)
                    {
                        $this->setUserAsAlerted($user->user_id);
                    }
                }
                catch (\Throwable $e)
                {
                    \XF::logException($e, false, "[Cav7/MilpacMention] firing failed for user $userId: ");
                }
            }
        }
        catch (\Throwable $e)
        {
            // The outer guard covers the pre-loop findByIds()/repository() lookups
            // because this surface fires inline with no deferred-job net (unlike Post);
            // keep the inner per-recipient catch too so one bad row still can't kill the
            // rest once the loop is running.
            \XF::logException($e, false, '[Cav7/MilpacMention] firing failed: ');
            return;
        }
    }
}
