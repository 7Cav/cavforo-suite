<?php

namespace Cav7\EnlistmentReminder;

/**
 * The rule that decides which enlistment-queue threads have become
 * "un-actioned" and still need a reminder. It is the highest-value seam of the
 * add-on: pure PHP with no XenForo dependency, so the deadline boundary, the
 * clerk-pickup exclusion and the once-only guard can be exercised for real in
 * plain PHP rather than pinned by shape.
 *
 * An application is reminded when all three hold (see CONTEXT.md for the
 * domain terms):
 *
 *   - un-reminded: it is not already recorded in the marker table;
 *   - past the deadline: its age since the OP EXCEEDS the deadline;
 *   - no clerk pickup: none of its reply authors is a current Processing Clerk.
 *
 * The XenForo-coupled caller (QueueReminder) supplies the facts: it resolves the
 * seated clerks to user ids (primary or secondary seat), reads the OP timestamp
 * and the visible reply authors off each queue thread, and looks up the marker
 * table for the already-reminded flag. Sticky status plays no part — a clerk
 * reply is the only "handled" signal.
 */
final class ReminderDecision
{
    /**
     * Whether one queue thread should be reminded now.
     *
     * @param int   $now             current unix time (\XF::$time)
     * @param int   $deadlineSeconds reminder deadline as a span in seconds
     * @param int[] $clerkUserIds    user ids seated as a current Processing Clerk
     * @param int   $opTimestamp     the OP's post_date
     * @param int[] $replyAuthorIds  distinct user ids of the visible replies
     * @param bool  $alreadyReminded whether the marker table already holds this thread
     */
    public static function shouldRemind(
        int $now,
        int $deadlineSeconds,
        array $clerkUserIds,
        int $opTimestamp,
        array $replyAuthorIds,
        bool $alreadyReminded
    ): bool {
        if ($alreadyReminded) {
            return false;
        }

        // Age must EXCEED the deadline: a thread exactly at the deadline is not
        // yet past it, matching the ">" the acceptance criteria call for.
        if (($now - $opTimestamp) <= $deadlineSeconds) {
            return false;
        }

        // Any reply authored by a current clerk is a pickup — the application is
        // being worked, so it is never reminded. array_intersect compares as
        // strings, so an int seat id and the string id XF hands back from the DB
        // match as the same seat.
        if (array_intersect($clerkUserIds, $replyAuthorIds)) {
            return false;
        }

        return true;
    }

    /**
     * Filter a batch of queue-thread facts to the thread ids that should be
     * reminded, preserving input order.
     *
     * Each thread is an associative array:
     *   [
     *     'thread_id'        => int,
     *     'op_timestamp'     => int,
     *     'reply_author_ids' => int[],
     *     'already_reminded' => bool,
     *   ]
     *
     * @param array<int,array<string,mixed>> $threads
     * @return int[] thread ids to remind
     */
    public static function selectThreadsToRemind(
        int $now,
        int $deadlineSeconds,
        array $clerkUserIds,
        array $threads
    ): array {
        $toRemind = [];
        foreach ($threads as $thread) {
            $remind = self::shouldRemind(
                $now,
                $deadlineSeconds,
                $clerkUserIds,
                (int) $thread['op_timestamp'],
                $thread['reply_author_ids'] ?? [],
                (bool) ($thread['already_reminded'] ?? false)
            );
            if ($remind) {
                $toRemind[] = (int) $thread['thread_id'];
            }
        }

        return $toRemind;
    }
}
