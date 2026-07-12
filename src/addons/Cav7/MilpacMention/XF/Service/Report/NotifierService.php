<?php

namespace Cav7\MilpacMention\XF\Service\Report;

use Cav7\MilpacMention\MilpacStash;
use XF\Entity\User;
use XF\Repository\UserAlertRepository;

/**
 * The report-comment firing extension (spec §2.4). After the stock notifier pass
 * (the @-mention alert notifyMentioned() raises), this raises the distinct
 * milpac_mention action on content type report for the milpac-only recipients the
 * shared detection hook stashed. It reuses the stock report alert handler
 * (XF\Alert\ReportHandler) — no new content type and no new handler — so the alert
 * deep-links to the report and renders the alert_report_milpac_mention template for
 * free; only the action string is new.
 *
 * The parent notifier is NOT the Post loadNotifiers()/AbstractNotifier shape, nor
 * the profile-post bespoke notify(): its report-comment mention pass is the bespoke
 * notifyMentioned() (no args), which dedups per member through $this->usersAlerted
 * and holds $this->report (Report) and $this->comment (ReportComment). This
 * extension mirrors that shape — parent::notifyMentioned() then fireMilpacMentions(),
 * reading the same $this->usersAlerted the stock pass wrote so a milpac link never
 * double-pings someone already alerted for an @-mention in the same comment.
 *
 * Preferences (spec §3.1): the report surface carries NO opt-out override. Being
 * named in a report cannot be muted in XF, so milpac_mention stays non-toggleable
 * there — there is intentionally no XF\Alert\ReportHandler extension, no
 * getOptOutActions() override, and no alert_opt_out.report_milpac_mention phrase.
 *
 * The report creation path (CreatorService) fires notifyCreate() — moderator emails,
 * no mention alert — so it neither carries nor consumes a milpac stash; only the
 * report-comment path (CommenterService::sendNotifications) reaches notifyMentioned().
 */
class NotifierService extends XFCP_NotifierService
{
    public function notifyMentioned()
    {
        parent::notifyMentioned();

        $this->fireMilpacMentions();
    }

    protected function fireMilpacMentions()
    {
        // Deliberately mirrors the shared per-surface firing pattern established in
        // XF\Service\Post\NotifierService (XenForo's XFCP forces one class-split per
        // surface), so a firing-rule change must land in every surface extension.
        $report = $this->report;
        $comment = $this->comment;

        // Same-instance invariant (load-bearing): MilpacStash keys on
        // spl_object_id($comment), so this take() only finds what the detection hook
        // stashed on the SAME ReportComment instance. CommenterService holds one
        // $comment across CommentPreparerService::setMessage()->prepare() (which binds
        // that comment as the message entity) and sendNotifications()->notifyMentioned(),
        // so the stash is found; the edit path builds no notifier, so a link added by a
        // later edit stashes but never fires (rule §2.5.4). take() is consuming, so a
        // double notifyMentioned() on the same object cannot double-fire.
        $milpacUserIds = MilpacStash::take($comment);
        if (!$milpacUserIds) {
            return;
        }

        // Outer containment: report-comment notifications run notifyMentioned() FULLY
        // INLINE in the member's request (ReportController -> CommenterService::
        // sendNotifications), after the comment is already saved+committed — unlike the
        // Post surface, whose notify() is deferred into XF\Job\Notifier and wrapped in
        // that job runner's own try/catch. With no deferred-job net here, the pre-loop
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
                // Contain each recipient: firing runs after parent::notifyMentioned() on a
                // comment that is already saved+committed, so an alert()/canView() failure
                // must be logged and skipped, never surfaced on the member's action.
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

                    // Rule 1 at the firing edge: repeat the self-check the stock report
                    // notifier does not run for the distinct action. The report notifier
                    // treats the comment author as the sender, so self-skip against the
                    // comment (as XF's own sendMentionNotification does).
                    if ($user->user_id == $comment->user_id)
                    {
                        continue;
                    }

                    // One alert per member across the report's notifiers (rules 2 and 5):
                    // the bespoke report notifier tracks this in $this->usersAlerted, which
                    // the stock @-mention pass just populated.
                    if (!empty($this->usersAlerted[$user->user_id]))
                    {
                        continue;
                    }

                    // Gating parity (§2.6): a member who cannot view the report is filtered
                    // out, exactly as the stock getUsersForMentionedNotification /
                    // sendMentionNotification do (both gate on $this->report->canView()).
                    $canView = \XF::asVisitor($user, function () use ($report) {
                        return $report->canView();
                    });
                    if (!$canView)
                    {
                        continue;
                    }

                    // Deep-link to the report via the stock ReportHandler: content type
                    // 'report', content id $comment->report_id (the Report PK the comment
                    // carries — the exact value XF's own report mention alert uses).
                    $sent = $alertRepo->alert(
                        $user,
                        $comment->user_id,
                        $comment->username,
                        'report',
                        $comment->report_id,
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
