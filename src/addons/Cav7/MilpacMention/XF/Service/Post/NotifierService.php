<?php

namespace Cav7\MilpacMention\XF\Service\Post;

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
 * Firing after parent::notify() (rather than as a queued notifier type) keeps it
 * off the time-limited job path, so the small milpac set always fires inline with
 * the first dispatch. The consuming MilpacStash::take() plus the shared $alerted
 * set give the firing rules (§2.5): one alert per member, self-link suppressed,
 * dedup against anything the stock pass already alerted, and no refire from a
 * resumed job (rule 4 parity on the job path).
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

        $milpacUserIds = MilpacStash::take($post);
        if (!$milpacUserIds) {
            return;
        }

        $users = \XF::em()->findByIds(User::class, $milpacUserIds, ['Profile', 'Option']);

        /** @var UserAlertRepository $alertRepo */
        $alertRepo = $this->app->repository(UserAlertRepository::class);

        foreach ($milpacUserIds as $userId)
        {
            if (!isset($users[$userId]))
            {
                continue;
            }

            /** @var User $user */
            $user = $users[$userId];

            // Rule 1 at the firing edge: the core Mention::canNotify self-check does
            // not run for the distinct action, so repeat it here.
            if ($user->user_id == $post->user_id)
            {
                continue;
            }

            // XF alerts a member once across all of a post's notifiers; honour that
            // so a milpac link never double-pings someone the stock pass alerted
            // (rules 2 and 5).
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

            $sent = $alertRepo->alert(
                $user,
                $post->user_id,
                $post->username,
                'post',
                $post->post_id,
                'milpac_mention',
                ['depends_on_addon_id' => 'Cav7/MilpacMention']
            );

            if ($sent)
            {
                $this->setUserAsAlerted($user->user_id);
            }
        }
    }
}
