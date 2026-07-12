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
 * CONTAINMENT MATCHES POST, deliberately. Like XF\Service\Post\NotifierService, this
 * notifier extends XF\Service\AbstractNotifier and is dispatched via notifyAndEnqueue()
 * (Service\Ticket\Creator/Replier), so it rides the same XF\Job\Notifier deferred-job
 * net Post relies on — NOT the fully-inline path of the ProfilePost/Report surfaces.
 * So there is no outer try/catch here (matching Post); only the per-recipient inner
 * guard, so one bad recipient cannot cost the rest their alert (matches
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

        // No outer try/catch: like Post, this surface is dispatched via
        // notifyAndEnqueue() and rides XF\Job\Notifier's net, so the pre-loop lookups
        // sit outside any guard (unlike the fully-inline ProfilePost/Report surfaces).
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
                $sent = $alertRepo->alert(
                    $user,
                    $fromUser->user_id,
                    $fromUser->username,
                    'nf_tickets_message',
                    $message->message_id,
                    'milpac_mention',
                    ['depends_on_addon_id' => 'Cav7/MilpacMention']
                );

                if ($sent)
                {
                    $this->setUserAsAlerted($user->user_id);
                }
            }
            catch (\Throwable $e)
            {
                \XF::logException($e, false, '[Cav7/MilpacMention] firing failed: ');
            }
        }
    }
}
