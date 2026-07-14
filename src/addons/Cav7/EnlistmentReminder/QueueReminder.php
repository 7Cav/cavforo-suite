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
 *
 * The private alert is routed by enlistment type (issue #144): a thread's primary
 * prefix says whether it is a Standard or a Re-Enlistment, and the pure
 * EnlistmentRouting seam maps that to the clerk positions that own the type. Only
 * the alert audience narrows — pickup still resolves the union of both sets, so a
 * reply from any of the five seats clears the reminder as before. A thread whose
 * prefix marks no known type is not a valid enlistment: it is skipped, with one
 * log breadcrumb if it would otherwise have been reminded.
 */
class QueueReminder
{
    /**
     * Memo backing resolveClerkUserIds(); keyed on the position-id list. See that
     * method for why the run resolves at most three distinct lists.
     *
     * @var array<string,int[]>
     */
    private array $clerkUserIdCache = [];

    /**
     * Scan the queue and remind every un-actioned, not-yet-reminded thread once.
     *
     * @param int $deadlineHours already clamped by the cron entry
     */
    public function remind(int $deadlineHours): void
    {
        $nodeId = (int) \XF::options()->cav7ERQueueNodeId;
        $botUserId = (int) \XF::options()->cav7ERBotUserId;

        $rawStandardPositionIds = (string) \XF::options()->cav7ERStandardClerkPositionIds;
        $rawReenlistPositionIds = (string) \XF::options()->cav7ERReenlistClerkPositionIds;
        $routing = new EnlistmentRouting(
            PositionIdList::parse((string) \XF::options()->cav7ERStandardPrefixIds),
            PositionIdList::parse($rawStandardPositionIds),
            PositionIdList::parse((string) \XF::options()->cav7ERReenlistPrefixIds),
            PositionIdList::parse($rawReenlistPositionIds)
        );

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

        // A prefix listed under BOTH type sets is a config error (story 21): a
        // thread of that prefix fail-safes to the union of both clerk sets so no
        // responsible clerk is silently dropped, but the misconfig is surfaced
        // once here rather than at every routing. route() handles the fail-safe;
        // this only logs it.
        $overlapPrefixIds = $routing->overlappingPrefixIds();
        if ($overlapPrefixIds)
        {
            \XF::logError(sprintf(
                '[Cav7/EnlistmentReminder] Prefix id(s) %s are listed under both cav7ERStandardPrefixIds and cav7ERReenlistPrefixIds; threads of that prefix alert the union of both clerk sets. Fix the overlap so routing is unambiguous.',
                implode(', ', $overlapPrefixIds)
            ));
        }

        $threads = $this->fetchQueueThreads($nodeId);
        if (!$threads)
        {
            return;
        }

        $threadIds = array_map('intval', array_column($threads, 'thread_id'));
        $prefixByThread = [];
        foreach ($threads as $thread)
        {
            $prefixByThread[(int) $thread['thread_id']] = (int) $thread['prefix_id'];
        }

        // Pickup and the mass-remind guard resolve the UNION of both clerk sets —
        // the same coverage the single clerk-position option gave before the split,
        // so any of the five seats replying still clears the reminder (issue #144:
        // only the alert audience narrows, never the pickup).
        $clerkUserIds = $this->resolveClerkUserIds($routing->pickupPositionIds());
        if (!$clerkUserIds)
        {
            // Symmetric with the node/bot guards above: with no configured position
            // resolving to a seated holder, no reply can count as a clerk pickup, so
            // every past-deadline thread — including applications a clerk is actively
            // processing — would be reminded. Abort with a signal rather than
            // mass-remind on a blank or drifted clerk-position option.
            \XF::logError(sprintf(
                '[Cav7/EnlistmentReminder] No clerk resolved from the configured positions (cav7ERStandardClerkPositionIds="%s", cav7ERReenlistClerkPositionIds="%s"); skipping this run so live clerk-handled applications are not reminded.',
                $rawStandardPositionIds, $rawReenlistPositionIds
            ));
            return;
        }
        $replyAuthorIds = $this->fetchReplyAuthorIds($threadIds);
        $alreadyReminded = $this->fetchAlreadyReminded($threadIds);
        // Issue #82: the note's presence is the fallback "already reminded" signal
        // for when the marker WRITE fails persistently while its READ still works.
        // The note lives in xf_post, a different table from the marker, so it
        // survives the marker's own write failure. See fetchAlreadyNoted.
        $alreadyNoted = $this->fetchAlreadyNoted($threadIds, $botUserId);

        // Self-heal the marker (issue #82): a thread the bot has already noted but
        // that is missing from the marker table is one whose marker write failed on
        // an earlier run. Re-attempt the write so a recovered DB backfills it and
        // the thread rejoins the marker fast-path. recordReminder is best-effort and
        // swallows its own throw, so a still-broken write just re-logs the #81
        // breadcrumb while the note keeps the reminder capped at one.
        foreach ($threadIds as $threadId)
        {
            if (isset($alreadyNoted[$threadId]) && !isset($alreadyReminded[$threadId]))
            {
                $this->recordReminder($threadId);
            }
        }

        $facts = [];
        foreach ($threads as $thread)
        {
            $threadId = (int) $thread['thread_id'];
            $facts[] = [
                'thread_id'        => $threadId,
                'op_timestamp'     => (int) $thread['post_date'],
                'reply_author_ids' => $replyAuthorIds[$threadId] ?? [],
                // Reminded when the marker has it OR the bot has already left its
                // note (issue #82). The note gates the whole reminder — note and
                // clerk alert both — so a broken marker write cannot re-post to the
                // applicant or re-ping the clerks.
                'already_reminded' => isset($alreadyReminded[$threadId]) || isset($alreadyNoted[$threadId]),
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
            $route = $routing->route($prefixByThread[$threadId] ?? 0);

            if ($route['type'] === EnlistmentRouting::TYPE_UNRECOGNIZED)
            {
                // Past the deadline, un-picked-up and un-reminded (it passed the
                // decision), but its primary prefix marks no known enlistment type,
                // so it was not made by an intake form and is not a valid enlistment
                // (stories 13-14). Skip the reminder rather than alert everyone, but
                // leave one breadcrumb in case it is a genuinely mis-prefixed real
                // enlistment. No marker is written — a persistent unroutable thread
                // may re-log hourly, like the add-on's other breadcrumbs.
                \XF::logError(sprintf(
                    '[Cav7/EnlistmentReminder] Thread %d is past the deadline with no pickup, but its primary prefix (%d) matches neither enlistment type; skipping the reminder. If this is a real enlistment, check its prefix.',
                    $threadId, $prefixByThread[$threadId] ?? 0
                ));
                continue;
            }

            // The alert narrows to the clerks who own this thread's type; a BOTH
            // (misconfig) thread fell back to the union in route(), already logged
            // above. The note and the marker stay type-agnostic.
            $alertUserIds = $this->resolveClerkUserIds($route['position_ids']);

            // Per-thread guard: one thread that fails to post must not abort the
            // rest of the batch. An un-reminded thread is simply retried next run.
            // The two steps after the note are best-effort and swallow their own
            // throws (alertClerks, recordReminder), so this catch fires only when
            // the note itself fails to post — nothing has reached the applicant —
            // which is why its message reads as an outright reminder failure.
            try
            {
                if ($this->postReminderNote($threadId, $botUserId))
                {
                    // Same success block as the note, before the marker: the note
                    // and the clerk alerts go out together and, once recordReminder
                    // lands, never again (issue #76, per ADR-0001).
                    $this->alertClerks($threadId, $botUserId, $alertUserIds);
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
     * getClerkUserIds() memoized on the position-id list. The pickup union and the
     * per-type alert sets are the only distinct lists a run resolves (at most
     * three: standard, re-enlistment, and their union for a misconfigured prefix),
     * so a queue of any size costs at most three seat queries.
     *
     * @param int[] $positionIds
     * @return int[]
     */
    protected function resolveClerkUserIds(array $positionIds): array
    {
        $key = implode(',', $positionIds);
        if (!array_key_exists($key, $this->clerkUserIdCache))
        {
            $this->clerkUserIdCache[$key] = $this->getClerkUserIds($positionIds);
        }
        return $this->clerkUserIdCache[$key];
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
     * Open, visible threads in the queue node, with the OP's post_date and the
     * primary prefix_id that decides enlistment type (issue #144). Scoping to the
     * one node keeps the Completed/Denied siblings out; discussion_open and
     * discussion_state keep resolved and hidden threads out. Sticky status is
     * deliberately not filtered.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function fetchQueueThreads(int $nodeId): array
    {
        return \XF::db()->fetchAll(
            'SELECT thread_id, post_date, prefix_id
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
     * The subset of the given thread ids that already carry the bot's reminder
     * note, as a thread_id => true map. This is the durability backstop for issue
     * #82. The marker table is both the "already reminded" source of truth AND the
     * thing that can fail to be written, so on a persistent write failure whose
     * read still works (INSERT revoked, a drifted or renamed column, a read-only
     * table) fetchAlreadyReminded keeps returning nothing for a thread the bot has
     * in fact already reminded, and the hourly scan re-posts the note and re-alerts
     * the clerks without bound. Deriving "already reminded" from the note itself,
     * which lives in xf_post — a different table, still writable in that failure
     * mode — caps the whole reminder at one note and one alert per thread.
     *
     * The note is matched on BOTH the bot as author (user_id) AND the reminder
     * phrase as the message. The author gate stops a member quoting or copy-pasting
     * the note from faking the signal; the phrase gate stops the same S6 bot's
     * SteamChecker VAC reply in the same thread from counting. Only visible posts
     * count, mirroring fetchReplyAuthorIds, so a soft-deleted note does not suppress
     * a fresh reminder. Do not "simplify" this away as a redundant re-read of the
     * marker table: it is the marker table's own write failure that it exists to
     * survive, and a ScanWiringTest assertion pins it for that reason.
     *
     * @param int[] $threadIds
     * @return array<int,true>
     */
    protected function fetchAlreadyNoted(array $threadIds, int $botUserId): array
    {
        if (!$threadIds)
        {
            return [];
        }

        $db = \XF::db();
        $noted = $db->fetchAllColumn(
            'SELECT DISTINCT thread_id
                FROM xf_post
                WHERE thread_id IN (' . $db->quote($threadIds) . ')
                    AND user_id = ?
                    AND message_state = ?
                    AND message = ?',
            [$botUserId, 'visible', (string) \XF::phrase('cav7_er_reminder_note')]
        );

        $map = [];
        foreach ($noted as $threadId)
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
        //
        // Best-effort only: the note has already been saved by this point, so this
        // correction must never throw back out of the method. Here the thread was
        // loaded fresh from the DB (at least deadlineHours after creation), so its
        // first_post_id is already persisted and the correction is a no-op — but a
        // DB blip on it must not stop the caller recording the marker, or the note
        // would be re-posted every run. Log any failure non-fatally and return true.
        try
        {
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
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, '[Cav7/EnlistmentReminder] first_post_id correction failed for thread ' . $thread->thread_id . '; note already posted: ');
        }

        return true;
    }

    /**
     * Record the thread in the marker table so it is never reminded again. Written
     * after the note posts, so a failed post is retried next run rather than
     * marked done. INSERT IGNORE tolerates a re-entrant run racing on the same
     * thread without erroring on the duplicate primary key.
     *
     * Best-effort, and last in the sequence: by the time this runs the note is
     * already visible on the applicant's thread and the clerk-alert step has already
     * run. A DB blip on the marker write is therefore a bookkeeping failure —
     * the reminder itself succeeded — so it is caught and logged here with its own
     * wording rather than thrown back to remind()'s catch, which logs a genuine
     * note-post failure. Left unmarked, the thread self-heals: the next run posts
     * again and, once the marker write lands, never again. The distinct message
     * keeps a debugger from chasing a note that in fact posted.
     */
    protected function recordReminder(int $threadId): void
    {
        try
        {
            \XF::db()->query(
                'INSERT IGNORE INTO xf_cav7_enlistment_reminder (thread_id, reminded_date)
                    VALUES (?, ?)',
                [$threadId, \XF::$time]
            );
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, '[Cav7/EnlistmentReminder] marker write failed for thread ' . $threadId . '; the note already posted, so this thread re-reminds next run: ');
        }
    }

    /**
     * Alert the processing clerks who own this thread's enlistment type that it is
     * past the deadline with no pickup, per ADR-0001. Each alert is a direct
     * XenForo notification (content type thread, custom action enlistment_reminder)
     * that lands in the clerk's bell and links straight to the application; the
     * core thread alert handler covers viewability and the one-click through, and
     * the wording is the public:alert_thread_enlistment_reminder template, which
     * now also renders the thread's prefix badge so Senior and Lead can tell a
     * re-enlistment from a standard enlistment straight from the bell. The S6 bot
     * is the sender, matching the visible note, and dependsOnAddOnId ties every
     * alert to this add-on so uninstalling clears any that are still outstanding.
     *
     * The audience is the per-type set EnlistmentRouting resolved for the thread's
     * prefix (issue #144), a subset of the pickup union — primary and secondary
     * seat holders alike. alert() (not insertAlert) is used so a clerk who muted
     * the type in their alert preferences is skipped.
     *
     * Best-effort: this runs after the note has posted, so a repository blip must
     * be logged, never thrown. If it threw, the caller's catch would skip
     * recordReminder and the applicant-visible note would re-post next run. One
     * bad clerk row must not cost the rest their alert either, but alert() already
     * tolerates a missing Option, so a single wrapping guard is enough.
     *
     * @param int[] $clerkUserIds the seat holders to alert for this thread's type
     */
    protected function alertClerks(int $threadId, int $botUserId, array $clerkUserIds): void
    {
        if (!$clerkUserIds)
        {
            return;
        }

        try
        {
            /** @var \XF\Repository\UserAlertRepository $alertRepo */
            $alertRepo = \XF::app()->repository(\XF\Repository\UserAlertRepository::class);

            /** @var \XF\Entity\User|null $botUser */
            $botUser = \XF::em()->find('XF:User', $botUserId);
            $botUsername = $botUser ? $botUser->username : '';

            /** @var \XF\Entity\User $clerk */
            foreach (\XF::em()->findByIds('XF:User', $clerkUserIds) as $clerk)
            {
                $alertRepo->alert(
                    $clerk,
                    $botUserId,
                    $botUsername,
                    'thread',
                    $threadId,
                    'enlistment_reminder',
                    [],
                    ['dependsOnAddOnId' => 'Cav7/EnlistmentReminder']
                );
            }
        }
        catch (\Throwable $e)
        {
            \XF::logException($e, false, '[Cav7/EnlistmentReminder] clerk alert send failed for thread ' . $threadId . '; note already posted: ');
        }
    }
}
