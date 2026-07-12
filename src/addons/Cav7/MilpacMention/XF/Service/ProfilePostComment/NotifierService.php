<?php

namespace Cav7\MilpacMention\XF\Service\ProfilePostComment;

use Cav7\MilpacMention\MilpacStash;
use XF\Entity\User;
use XF\Repository\UserAlertRepository;

/**
 * The profile-post-comment firing extension (spec §2.4). After the stock notifier
 * pass (profile owner, profile-post author, @-mention, other commenters), this
 * raises the distinct milpac_mention action on content type profile_post_comment
 * for the milpac-only recipients the shared detection hook stashed. It reuses the
 * stock ProfilePostComment alert handler — no new content type and no new handler —
 * so the alert deep-links to the comment and renders the
 * alert_profile_post_comment_milpac_mention template for free; only the action
 * string is new.
 *
 * Like the profile-post notifier, the parent here is the bespoke notify()/no-arg,
 * $this->usersAlerted shape (spec §2.1), not the Post loadNotifiers() one — so this
 * mirrors that: parent::notify() then fireMilpacMentions(), reading the same
 * $this->usersAlerted the stock pass wrote.
 *
 * Preferences (spec §3.1): the comment surface carries NO opt-out override. XF
 * registers the mention opt-out only on XF\Alert\ProfilePostHandler, and the one
 * profile_post row governs comment alerts too; milpac_mention mirrors that, so
 * there is intentionally no XF\Alert\ProfilePostCommentHandler extension.
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
        $comment = $this->comment;

        // Same-instance invariant (load-bearing): MilpacStash keys on
        // spl_object_id($comment), so this take() only finds what the detection hook
        // stashed on the SAME ProfilePostComment instance. CreatorService holds one
        // $comment across setMessage()->prepare() and sendNotifications()->notify(),
        // so the stash is found; the edit path builds no notifier, so a link added by
        // a later edit stashes but never fires (rule §2.5.4). take() is consuming.
        $milpacUserIds = MilpacStash::take($comment);
        if (!$milpacUserIds) {
            return;
        }

        // Outer containment: this surface runs notify() FULLY INLINE in the member's
        // request, after the comment is already saved+committed — unlike the Post
        // surface, whose notify() is deferred into XF\Job\Notifier and wrapped in that
        // job runner's own try/catch. With no deferred-job net here, the pre-loop
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
                // Contain each recipient: firing runs after parent::notify() on a comment
                // that is already saved+committed, so an alert()/canView() failure must be
                // logged and skipped, never surfaced on the member's action. Per-recipient
                // so one bad row cannot cost the rest their alert.
                try
                {
                    if (!isset($users[$userId]))
                    {
                        continue;
                    }

                    /** @var User $user */
                    $user = $users[$userId];

                    // Rule 1 at the firing edge: repeat the self-check the stock notifier
                    // does not run for the distinct action.
                    if ($user->user_id == $comment->user_id)
                    {
                        continue;
                    }

                    // One alert per member across the comment's notifiers (rules 2 and 5):
                    // the bespoke notifier tracks this in $this->usersAlerted.
                    if (!empty($this->usersAlerted[$user->user_id]))
                    {
                        continue;
                    }

                    // Gating parity (§2.6): a member who cannot view the comment is
                    // filtered out, exactly as the stock getUsersForNotification does.
                    $canView = \XF::asVisitor($user, function () use ($comment) {
                        return $comment->canView();
                    });
                    if (!$canView)
                    {
                        continue;
                    }

                    $sent = $alertRepo->alert(
                        $user,
                        $comment->user_id,
                        $comment->username,
                        'profile_post_comment',
                        $comment->profile_post_comment_id,
                        'milpac_mention',
                        ['depends_on_addon_id' => 'Cav7/MilpacMention']
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
            // because this surface fires inline with no deferred-job net (unlike Post);
            // keep the inner per-recipient catch too so one bad row still can't kill the
            // rest once the loop is running.
            \XF::logException($e, false, '[Cav7/MilpacMention] firing failed: ');
            return;
        }
    }
}
