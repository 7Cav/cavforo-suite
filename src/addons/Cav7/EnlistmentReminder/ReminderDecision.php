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
 *
 * The processing status arrives as the ProcessingStatus that seam minted, not as
 * a boolean the caller computed (issue #192). ProcessingStatus is the one home
 * for why that distinction is worth a type.
 */
final class ReminderDecision
{
    /**
     * Whether one queue thread should be reminded now.
     *
     * @param int              $now             current unix time (\XF::$time)
     * @param int              $deadlineSeconds reminder deadline as a span in seconds, positive
     * @param int              $opTimestamp     the OP's post_date
     * @param int              $threadId        the thread being decided over
     * @param ProcessingStatus $processing      which threads a clerk has taken on
     * @param bool             $alreadyReminded whether the marker table already holds this thread
     * @throws \InvalidArgumentException on a non-positive deadline
     */
    public static function shouldRemind(
        int $now,
        int $deadlineSeconds,
        int $opTimestamp,
        int $threadId,
        ProcessingStatus $processing,
        bool $alreadyReminded
    ): bool {
        self::assertPositiveDeadline($deadlineSeconds);

        if ($alreadyReminded) {
            return false;
        }

        // Age must EXCEED the deadline: a thread exactly at the deadline is not
        // yet past it, matching the ">" the acceptance criteria call for.
        if (($now - $opTimestamp) <= $deadlineSeconds) {
            return false;
        }

        // A processing status prefix means a clerk has taken the application on,
        // so it is never reminded.
        if ($processing->has($threadId)) {
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
     *     'already_reminded' => bool,
     *   ]
     *
     * The processing status is NOT one of the per-thread facts (issue #192). It
     * arrives once, as the set ProcessingStatus minted from the prefix-link rows,
     * so a thread the caller says nothing about is simply one the set never
     * marked: un-actioned, and remindable. There is no "the fact was omitted"
     * state left to choose a default for.
     *
     * @param array<int,array<string,mixed>> $threads
     * @param ProcessingStatus               $processing which threads a clerk has taken on
     * @return int[] thread ids to remind
     * @throws \InvalidArgumentException on a non-positive deadline
     */
    public static function selectThreadsToRemind(
        int $now,
        int $deadlineSeconds,
        array $threads,
        ProcessingStatus $processing
    ): array {
        // Checked here too, not just per thread, so an empty batch cannot slip a
        // bad deadline past unremarked.
        self::assertPositiveDeadline($deadlineSeconds);

        $toRemind = [];
        foreach ($threads as $thread) {
            $threadId = (int) $thread['thread_id'];
            $remind = self::shouldRemind(
                $now,
                $deadlineSeconds,
                (int) $thread['op_timestamp'],
                $threadId,
                $processing,
                (bool) ($thread['already_reminded'] ?? false)
            );
            if ($remind) {
                $toRemind[] = $threadId;
            }
        }

        return $toRemind;
    }

    /**
     * A deadline of zero or less puts every open application past it at once, so
     * the whole queue is reminded in one run. The cron entry clamps the configured
     * option to a one-hour floor, but that clamp is two classes away and this seam
     * is the one that decides; refusing the value here makes it independently safe
     * for the next caller, in the same spirit as ProcessingStatus refusing an
     * empty status set.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertPositiveDeadline(int $deadlineSeconds): void
    {
        if ($deadlineSeconds <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'The reminder deadline must be a positive span in seconds; got %d, which would put every open application past the deadline at once.',
                $deadlineSeconds
            ));
        }
    }
}
