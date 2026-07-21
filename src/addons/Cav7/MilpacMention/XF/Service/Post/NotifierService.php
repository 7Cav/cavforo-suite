<?php

namespace Cav7\MilpacMention\XF\Service\Post;

use Cav7\MilpacMention\MilpacResolver;
use Cav7\MilpacMention\MilpacStash;
use XF\Entity\User;
use XF\Repository\UserAlertRepository;

/**
 * The Post firing extension (spec §2.4). After the stock notifier pass (@-mention,
 * quote, forum/thread watch), this raises the distinct milpac_mention action on
 * content type post for the milpac-only recipients the shared detection hook
 * stashed. It reuses the stock Post alert handler — no new content type and no new
 * handler — so the alert deep-links to the post and renders the
 * alert_post_milpac_mention template for free; only the action string is new.
 *
 * Firing after parent::notify(), the consuming MilpacStash::take() plus the shared
 * $alerted set give the firing rules (§2.5): one alert per member, self-link
 * suppressed, dedup against anything the stock pass already alerted, and no refire
 * from a resumed job.
 *
 * CONTAINMENT IS INLINE-ONLY, like the Report/ProfilePost surfaces — this does NOT
 * ride the job net (an earlier note framing the inline pass as job-protected was
 * wrong). ReplierService/CreatorService::sendNotifications() dispatch through
 * notifyAndEnqueue($timeLimit), which runs the FIRST notify() pass INLINE in the
 * member's request and only defers the overflow into XF\Job\Notifier. Milpac firing
 * is stash-inline-only: a resumed job rebuilds the service on a freshly loaded Post
 * (createForJob -> find(Post)) with a new object id and an empty MilpacStash, so
 * take() returns nothing and fireMilpacMentions() fires on the inline first pass ONLY —
 * never under the job net. This surface therefore ALWAYS runs inline, after the post
 * action is committed, with no deferred-job protection — the Report/ProfilePost
 * posture. So it carries an OUTER try/catch around the pre-loop findByIds()/repository()
 * lookups and the recipient loop (a transient DB fault there would otherwise 500 an
 * already-committed post action and silently drop every recipient), mirroring
 * XF\Service\Report\NotifierService — plus the per-recipient inner guard so one bad
 * recipient cannot cost the rest their alert (matches
 * EnlistmentReminder\QueueReminder::alertClerks's best-effort send).
 */
class NotifierService extends XFCP_NotifierService
{
    public function notify($timeLimit = null)
    {
        parent::notify($timeLimit);

        $this->fireMilpacMentions();
    }

    protected function fireMilpacMentions()
    {
        $post = $this->post;

        // Same-instance invariant (load-bearing): MilpacStash keys on
        // spl_object_id($post), so this take() only finds what the detection hook
        // (PreparerService::stashMilpacMentions) stashed on the SAME Post object
        // instance. ReplierService / CreatorService hold one $post across
        // prepare()+notify(), so the stash is found; a resumed Notifier job runs on
        // a freshly loaded Post with a new object id and an empty stash, so it fires
        // nothing — the once-only / no-refire guarantee (spec §2.5 rules 2 and 4).
        // take() is consuming, so even a double notify() on the same object cannot
        // double-fire. Do NOT re-key the stash on post_id, or that guarantee breaks.
        $milpacUserIds = MilpacStash::take($post);
        if (!$milpacUserIds) {
            return;
        }

        // Outer containment: Post notifications dispatch through notifyAndEnqueue(),
        // whose FIRST notify() pass runs FULLY INLINE in the member's request
        // (ReplierService/CreatorService::sendNotifications), after the post is already
        // saved+committed. Milpac firing is stash-inline-only — a resumed XF\Job\Notifier
        // loads a fresh Post with a new object id and an empty stash, so take() returns
        // nothing and this body NEVER runs under the deferred-job net. With no job net,
        // the pre-loop findByIds()/repository() lookups need the same guard as the loop:
        // an uncaught failure (DB deadlock, dropped connection, timeout) would otherwise
        // become a 500 on an already-committed post action and silently drop every milpac
        // recipient. Mirrors XF\Service\Report\NotifierService.
        try
        {
            // The suppressed-area gate (issue #147). A post's place is its thread's
            // forum node, and an admin can name nodes where milpac mentions do not
            // fire. Suppression is by PLACE, so it decides once for the whole post
            // rather than per recipient: it never asks who can view the post, who
            // wrote it, or who is watching it. Only the alert is withheld — the
            // milpac link in a suppressed node still renders, still resolves, and the
            // $name completer still works there, because detection is untouched.
            //
            // Inside the outer guard on purpose: reading the Thread relation and the
            // options can fault on an already-committed post action, and the catch
            // below turns that into a logged, alert-free pass instead of a 500. A
            // thread that will not load leaves the node id at 0, which the predicate
            // fails OPEN on — suppression is only ever what the admin explicitly named.
            $nodeId = $post->Thread ? (int) $post->Thread->node_id : 0;
            if (MilpacResolver::isSuppressedArea(\XF::options()->cav7MMSuppressedNodeIds ?? [], $nodeId))
            {
                return;
            }

            $users = \XF::em()->findByIds(User::class, $milpacUserIds, ['Profile', 'Option']);

            /** @var UserAlertRepository $alertRepo */
            $alertRepo = $this->app->repository(UserAlertRepository::class);

            foreach ($milpacUserIds as $userId)
            {
                // Contain each recipient: firing runs after parent::notify() on a post
                // that is already saved+committed, so an alert()/canView() failure must be
                // logged and skipped, never surfaced on the member's reply action.
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

                    // Rule 1 at the firing edge: the core Mention::canNotify self-check
                    // does not run for the distinct action, so repeat it here.
                    if ($user->user_id == $post->user_id)
                    {
                        continue;
                    }

                    // XF alerts a member once across all of a post's notifiers; honour
                    // that so a milpac link never double-pings someone the stock pass
                    // alerted (rules 2 and 5).
                    if (!empty($this->alerted[$user->user_id]))
                    {
                        continue;
                    }

                    // Gating parity (§2.6): a member who cannot view the post is filtered
                    // out, exactly as the stock notifier's canUserViewContent does.
                    $canView = \XF::asVisitor($user, function () use ($post) {
                        return $post->canView();
                    });
                    if (!$canView)
                    {
                        continue;
                    }

                    // autoRead=false in the $options array keeps the milpac alert unread when it
                    // is only surfaced in the alerts dropdown/list; it clears when the recipient
                    // views the linked content or explicitly reads the alert, exactly as XF's own
                    // mention alerts do. Without it insertAlert() defaults auto_read=1, which
                    // auto-marks the alert read the moment it shows in the dropdown — a different
                    // schedule than the @-mention it mirrors. depends_on_addon_id stays in the
                    // $extra array — insertAlert() reads the two from separate slots.
                    $sent = $alertRepo->alert(
                        $user,
                        $post->user_id,
                        $post->username,
                        'post',
                        $post->post_id,
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
            // because milpac firing runs inline-only with no deferred-job net (a resumed
            // XF\Job\Notifier loads a fresh Post with an empty stash and fires nothing);
            // keep the inner per-recipient catch too so one bad row still can't kill the
            // rest once the loop is running.
            \XF::logException($e, false, '[Cav7/MilpacMention] firing failed: ');
            return;
        }
    }
}
