<?php

namespace Cav7\EnlistmentReminder;

/**
 * The rule that decides which enlistment-queue threads have become
 * "un-actioned" and still need a reminder. It is the highest-value seam of the
 * add-on: pure PHP with no XenForo dependency, so the deadline boundary, the
 * processing-status suppression and the once-only guard can be exercised for
 * real in plain PHP rather than pinned by shape.
 *
 * An application is reminded when all three hold (see CONTEXT.md for the
 * domain terms):
 *
 *   - un-reminded: it is not already recorded in the marker table;
 *   - past the deadline: its age since the OP EXCEEDS the deadline;
 *   - no processing status: it carries none of the configured in-processing
 *     prefixes.
 *
 * Issue #186 replaced the old "no clerk has replied" test with that last one.
 * Clerk seats are resolved fresh on every hourly scan, so a reply-authorship
 * rule could be withdrawn retroactively: when the clerk who picked a thread up
 * rotated out of RRD, their reply stopped counting and the bot reminded on work
 * that was already underway. A status prefix is a fact about the thread, so no
 * roster change can undo a pickup that already happened.
 *
 * The XenForo-coupled caller (QueueReminder) supplies the facts: it reads the OP
 * timestamp off each queue thread, asks ProcessingStatus which threads carry an
 * in-processing prefix, and looks up the marker table for the already-reminded
 * flag. Reply authorship plays no part, and neither does sticky status — a
 * thread is pinned only once it is Approved, which is far too late to serve as
 * a trigger.
 */
final class ReminderDecision
{
    /**
     * Whether one queue thread should be reminded now.
     *
     * @param int  $now             current unix time (\XF::$time)
     * @param int  $deadlineSeconds reminder deadline as a span in seconds
     * @param int  $opTimestamp     the OP's post_date
     * @param bool $inProcessing    whether the thread carries an in-processing prefix
     * @param bool $alreadyReminded whether the marker table already holds this thread
     */
    public static function shouldRemind(
        int $now,
        int $deadlineSeconds,
        int $opTimestamp,
        bool $inProcessing,
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

        // A processing status prefix means a clerk has taken the application on,
        // so it is never reminded. Membership in the CONFIGURED set is what the
        // caller must hand in here: every valid queue thread also carries its
        // Enlistment/Re-Enlistment type prefix, so a "has any prefix" test would
        // suppress the whole queue for good. See ProcessingStatus.
        if ($inProcessing) {
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
     *     'in_processing'    => bool,
     *     'already_reminded' => bool,
     *   ]
     *
     * A missing 'in_processing' defaults to false — "no processing status", so
     * remindable. Defaulting the other way would quietly silence the whole queue
     * the moment a caller stopped supplying the fact, which is exactly the
     * failure mode #186 warns about.
     *
     * @param array<int,array<string,mixed>> $threads
     * @return int[] thread ids to remind
     */
    public static function selectThreadsToRemind(
        int $now,
        int $deadlineSeconds,
        array $threads
    ): array {
        $toRemind = [];
        foreach ($threads as $thread) {
            $remind = self::shouldRemind(
                $now,
                $deadlineSeconds,
                (int) $thread['op_timestamp'],
                (bool) ($thread['in_processing'] ?? false),
                (bool) ($thread['already_reminded'] ?? false)
            );
            if ($remind) {
                $toRemind[] = (int) $thread['thread_id'];
            }
        }

        return $toRemind;
    }
}
