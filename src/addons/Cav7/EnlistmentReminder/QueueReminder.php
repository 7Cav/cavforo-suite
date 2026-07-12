<?php

namespace Cav7\EnlistmentReminder;

/**
 * The XenForo-coupled half of the reminder: it gathers the facts each queue
 * thread needs, hands the decision to the pure ReminderDecision, then posts the
 * neutral bot note and records the marker for every thread that decision picks.
 *
 * The scan is scoped to the configured queue node only (node 325 by default),
 * so the Completed (326) and Denied (327) child forums are never touched — they
 * are different nodes and never appear in the query. Open and visible threads
 * only; sticky status is ignored. A clerk reply is the only "handled" signal, so
 * a recruiter reply, an applicant reply, or the bot's own note never suppress a
 * reminder (none of them is a seated clerk).
 */
class QueueReminder
{
    /**
     * Scan the queue and remind every un-actioned, not-yet-reminded thread once.
     *
     * @param int $deadlineHours already clamped by the cron entry
     */
    public function remind(int $deadlineHours): void
    {
        $nodeId = (int) \XF::options()->cav7ERQueueNodeId;
        $botUserId = (int) \XF::options()->cav7ERBotUserId;
        $clerkPositionIds = $this->parseIdList((string) \XF::options()->cav7ERClerkPositionIds);

        if (!$nodeId)
        {
            \XF::logError('[Cav7/EnlistmentReminder] Queue node id is not configured; nothing to scan.');
            return;
        }
        if (!$botUserId)
        {
            \XF::logError('[Cav7/EnlistmentReminder] Bot user id is not configured; cannot post reminders.');
            return;
        }

        $threads = $this->fetchQueueThreads($nodeId);
        if (!$threads)
        {
            return;
        }

        $threadIds = array_map('intval', array_column($threads, 'thread_id'));
        $clerkUserIds = $this->getClerkUserIds($clerkPositionIds);
        $replyAuthorIds = $this->fetchReplyAuthorIds($threadIds);
        $alreadyReminded = $this->fetchAlreadyReminded($threadIds);

        $facts = [];
        foreach ($threads as $thread)
        {
            $threadId = (int) $thread['thread_id'];
            $facts[] = [
                'thread_id'        => $threadId,
                'op_timestamp'     => (int) $thread['post_date'],
                'reply_author_ids' => $replyAuthorIds[$threadId] ?? [],
                'already_reminded' => isset($alreadyReminded[$threadId]),
            ];
        }

        $toRemind = ReminderDecision::selectThreadsToRemind(
            \XF::$time,
            $deadlineHours * 3600,
            $clerkUserIds,
            $facts
        );

        foreach ($toRemind as $threadId)
        {
            // Per-thread guard: one thread that fails to post must not abort the
            // rest of the batch. An un-reminded thread is simply retried next run.
            try
            {
                if ($this->postReminderNote($threadId, $botUserId))
                {
                    $this->recordReminder($threadId);
                }
            }
            catch (\Throwable $e)
            {
                \XF::logException($e, false, '[Cav7/EnlistmentReminder] reminder failed for thread ' . $threadId . ': ');
            }
        }
    }

    /**
     * Parse a comma/whitespace separated id option to a list of positive ints,
     * the same normalisation SteamChecker uses for its allowed-group option.
     *
     * @return int[]
     */
    protected function parseIdList(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '')
        {
            return [];
        }

        return array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $raw))));
    }

    /**
     * User ids of every member seated in one of the clerk positions, as a
     * primary (position_id) OR a secondary (secondary_position_ids) seat — any
     * one of the configured positions counts. Mirrors RosterPatch's holder query:
     * the INNER JOIN on xf_user drops orphaned roster rows, and each secondary
     * seat is matched with FIND_IN_SET against the vendor's comma list.
     *
     * @param int[] $positionIds
     * @return int[]
     */
    protected function getClerkUserIds(array $positionIds): array
    {
        if (!$positionIds)
        {
            return [];
        }

        $db = \XF::db();

        // One FIND_IN_SET arm per configured position, since the vendor stores
        // secondary seats as a comma list on xf_nf_rosters_user.
        $findInSet = [];
        foreach ($positionIds as $positionId)
        {
            $findInSet[] = 'FIND_IN_SET(' . $db->quote($positionId) . ', ru.secondary_position_ids)';
        }

        $userIds = $db->fetchAllColumn(
            'SELECT DISTINCT ru.user_id
                FROM xf_nf_rosters_user AS ru
                INNER JOIN xf_user AS u ON (u.user_id = ru.user_id)
                WHERE ru.position_id IN (' . $db->quote($positionIds) . ')
                   OR ' . implode(' OR ', $findInSet)
        );

        return array_map('intval', $userIds);
    }

    /**
     * Open, visible threads in the queue node, with the OP's post_date. Scoping
     * to the one node keeps the Completed/Denied siblings out; discussion_open
     * and discussion_state keep resolved and hidden threads out. Sticky status is
     * deliberately not filtered.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function fetchQueueThreads(int $nodeId): array
    {
        return \XF::db()->fetchAll(
            'SELECT thread_id, post_date
                FROM xf_thread
                WHERE node_id = ?
                    AND discussion_state = ?
                    AND discussion_open = 1',
            [$nodeId, 'visible']
        );
    }

    /**
     * Distinct visible-reply author ids per thread, keyed by thread id. Only
     * posts after the OP (position > 0) and only visible ones count, so a deleted
     * clerk reply is not mistaken for a live pickup.
     *
     * @param int[] $threadIds
     * @return array<int,int[]> thread_id => [user_id, ...]
     */
    protected function fetchReplyAuthorIds(array $threadIds): array
    {
        if (!$threadIds)
        {
            return [];
        }

        $db = \XF::db();
        $rows = $db->fetchAll(
            'SELECT DISTINCT thread_id, user_id
                FROM xf_post
                WHERE thread_id IN (' . $db->quote($threadIds) . ')
                    AND position > 0
                    AND message_state = ?',
            ['visible']
        );

        $byThread = [];
        foreach ($rows as $row)
        {
            $byThread[(int) $row['thread_id']][] = (int) $row['user_id'];
        }

        return $byThread;
    }

    /**
     * The subset of the given thread ids already recorded in the marker table,
     * as a thread_id => true map for O(1) lookup while building the facts.
     *
     * @param int[] $threadIds
     * @return array<int,true>
     */
    protected function fetchAlreadyReminded(array $threadIds): array
    {
        if (!$threadIds)
        {
            return [];
        }

        $db = \XF::db();
        $reminded = $db->fetchAllColumn(
            'SELECT thread_id
                FROM xf_cav7_enlistment_reminder
                WHERE thread_id IN (' . $db->quote($threadIds) . ')'
        );

        $map = [];
        foreach ($reminded as $threadId)
        {
            $map[(int) $threadId] = true;
        }

        return $map;
    }

    /**
     * Post the neutral note as the bot by saving a Post entity directly — the
     * same approach SteamChecker uses, since XF 2.3 removed the post Creator
     * service. XF's own Post._postSave hooks update the thread's reply_count and
     * last-post pointers. The visible copy is the applicant-safe reminder phrase;
     * the pointed "pick this up" wording lives in the clerk alert (issue #76, not
     * built here), per ADR-0001.
     *
     * @return bool whether the note was posted
     */
    protected function postReminderNote(int $threadId, int $botUserId): bool
    {
        /** @var \XF\Entity\Thread|null $thread */
        $thread = \XF::em()->find('XF:Thread', $threadId);
        if (!$thread)
        {
            \XF::logError('[Cav7/EnlistmentReminder] Thread ' . $threadId . ' vanished before reminding.');
            return false;
        }

        /** @var \XF\Entity\User|null $botUser */
        $botUser = \XF::em()->find('XF:User', $botUserId);
        if (!$botUser)
        {
            \XF::logError('[Cav7/EnlistmentReminder] Bot user id ' . $botUserId . ' not found.');
            return false;
        }

        /** @var \XF\Entity\Post $post */
        $post = \XF::em()->create('XF:Post');
        $post->thread_id     = $thread->thread_id;
        $post->user_id       = $botUser->user_id;
        $post->username      = $botUser->username;
        $post->post_date     = \XF::$time;
        $post->message       = (string) \XF::phrase('cav7_er_reminder_note');
        $post->message_state = 'visible';
        $post->ip_id         = 0;
        $post->position      = $thread->reply_count + 1;
        $post->save();

        // first_post_id correction, carried over from SteamChecker: if XF's entity
        // manager is holding a Thread whose first_post_id was captured as 0, its
        // post-save flush can leave the bot note as first_post_id and break the
        // thread-list hover card. Pin first_post_id back to the OP explicitly.
        $opPostId = (int) \XF::db()->fetchOne(
            'SELECT post_id FROM xf_post WHERE thread_id = ? AND position = 0 ORDER BY post_id ASC LIMIT 1',
            [$thread->thread_id]
        );
        if ($opPostId)
        {
            \XF::db()->query(
                'UPDATE xf_thread SET first_post_id = ? WHERE thread_id = ?',
                [$opPostId, $thread->thread_id]
            );
        }

        return true;
    }

    /**
     * Record the thread in the marker table so it is never reminded again. Written
     * after the note posts, so a failed post is retried next run rather than
     * marked done. INSERT IGNORE tolerates a re-entrant run racing on the same
     * thread without erroring on the duplicate primary key.
     */
    protected function recordReminder(int $threadId): void
    {
        \XF::db()->query(
            'INSERT IGNORE INTO xf_cav7_enlistment_reminder (thread_id, reminded_date)
                VALUES (?, ?)',
            [$threadId, \XF::$time]
        );
    }
}
