<?php

namespace Cav7\MilpacMention\XF\Service\ProfilePost;

use Cav7\MilpacMention\MilpacStash;
use XF\Entity\User;
use XF\Repository\UserAlertRepository;

/**
 * The profile-post firing extension (spec §2.4). After the stock notifier pass
 * (profile-owner insert + @-mention), this raises the distinct milpac_mention
 * action on content type profile_post for the milpac-only recipients the shared
 * detection hook stashed. It reuses the stock ProfilePost alert handler — no new
 * content type and no new handler — so the alert deep-links to the profile post
 * and renders the alert_profile_post_milpac_mention template for free; only the
 * action string is new.
 *
 * The parent notifier is NOT the Post loadNotifiers()/AbstractNotifier shape: its
 * bespoke notify() takes no arguments and dedups per member through
 * $this->usersAlerted (spec §2.1). This extension mirrors that shape rather than
 * the Post one — parent::notify() then fireMilpacMentions(), reading the same
 * $this->usersAlerted the stock pass wrote so a milpac link never double-pings
 * someone already alerted for the profile post.
 */
class NotifierService extends XFCP_NotifierService
{
    public function notify()
    {
        parent::notify();

        $this->fireMilpacMentions();
    }

    protected function fireMilpacMentions()
    {
        // Deliberately mirrors the shared per-surface firing pattern established in
        // XF\Service\Post\NotifierService (XenForo's XFCP forces one class-split per
        // surface), so a firing-rule change must land in every surface extension.
        $profilePost = $this->profilePost;

        // Same-instance invariant (load-bearing): MilpacStash keys on
        // spl_object_id($profilePost), so this take() only finds what the detection
        // hook (PreparerService::stashMilpacMentions) stashed on the SAME ProfilePost
        // object instance. CreatorService holds one $profilePost across
        // setMessage()->prepare() and sendNotifications()->notify(), so the stash is
        // found; the edit path (EditorService) builds no notifier, so a link added by
        // a later edit stashes but never fires — rule §2.5.4. take() is consuming, so
        // a double notify() on the same object cannot double-fire.
        $milpacUserIds = MilpacStash::take($profilePost);
        if (!$milpacUserIds) {
            return;
        }

        // Outer containment: this surface runs notify() FULLY INLINE in the member's
        // request, after the profile post is already saved+committed. Every milpac
        // surface fires inline with no deferred-job net — Post included, whose inline
        // notify() pass carries this same outer guard — so the pre-loop
        // findByIds()/repository() lookups need the same guard as the loop: an uncaught
        // failure (DB deadlock, dropped connection, timeout) would otherwise become a
        // 500 on an already-committed action and silently drop every milpac recipient.
        try
        {
            $users = \XF::em()->findByIds(User::class, $milpacUserIds, ['Profile', 'Option']);

            /** @var UserAlertRepository $alertRepo */
            $alertRepo = $this->app->repository(UserAlertRepository::class);

            foreach ($milpacUserIds as $userId)
            {
                // Contain each recipient: firing runs after parent::notify() on a profile
                // post that is already saved+committed, so an alert()/canView() failure
                // must be logged and skipped, never surfaced on the member's post action.
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

                    // Rule 1 at the firing edge: the stock notifier's self-check does not
                    // run for the distinct action, so repeat it here.
                    if ($user->user_id == $profilePost->user_id)
                    {
                        continue;
                    }

                    // XF alerts a member once across a profile post's notifiers; honour
                    // that so a milpac link never double-pings someone the stock pass
                    // alerted (rules 2 and 5). The bespoke notifier tracks this in
                    // $this->usersAlerted (not the Post loadNotifiers $alerted).
                    if (!empty($this->usersAlerted[$user->user_id]))
                    {
                        continue;
                    }

                    // Gating parity (§2.6): a member who cannot view the profile post is
                    // filtered out, exactly as the stock notifier's getUsersForNotification
                    // does.
                    $canView = \XF::asVisitor($user, function () use ($profilePost) {
                        return $profilePost->canView();
                    });
                    if (!$canView)
                    {
                        continue;
                    }

                    // autoRead=false in the $options array keeps the milpac alert unread when
                    // it is only surfaced in the alerts dropdown/list; it clears when the
                    // recipient views the linked content or explicitly reads the alert, exactly
                    // as XF's own mention alerts do (the stock ProfilePost notifier passes the
                    // same flag). Without it insertAlert() defaults auto_read=1, which auto-marks
                    // the alert read the moment it shows in the dropdown — a different schedule
                    // than the @-mention it mirrors. depends_on_addon_id stays in the $extra
                    // array — insertAlert() reads the two from separate slots.
                    $sent = $alertRepo->alert(
                        $user,
                        $profilePost->user_id,
                        $profilePost->username,
                        'profile_post',
                        $profilePost->profile_post_id,
                        'milpac_mention',
                        ['depends_on_addon_id' => 'Cav7/MilpacMention'],
                        ['autoRead' => false]
                    );

                    if ($sent)
                    {
                        $this->usersAlerted[$user->user_id] = true;
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
            // because this surface fires inline with no deferred-job net (as every milpac
            // surface does, Post included); keep the inner per-recipient catch too so one
            // bad row still can't kill the rest once the loop is running.
            \XF::logException($e, false, '[Cav7/MilpacMention] firing failed: ');
            return;
        }
    }
}
