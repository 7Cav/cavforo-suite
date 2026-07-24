<?php

namespace Cav7\EnlistmentReminder;

/**
 * Reads a processing status off a queue thread's prefixes (issue #186) — the one
 * fact ReminderDecision now uses to tell a started application from an
 * un-actioned one. Another pure seam of the add-on, a twin of PositionIdList and
 * EnlistmentRouting, so the rule runs for real in plain PHP with no XenForo.
 *
 * RRD works the queue through a status prefix state machine supplied by the
 * SV/MultiPrefix add-on, which stores every prefix a thread carries in its own
 * thread-prefix link table. The statuses are In Progress, Hold and Approved, on
 * top of the un-actioned "no status" a thread starts with; the order they are
 * reached in is CONTEXT.md's to describe, and nothing here depends on it, since
 * membership in a set is order-blind.
 *
 * The trap this seam exists to close: that link table also holds each thread's
 * TYPE prefix — Enlistment (57) or Re-Enlistment (58) — and every valid queue
 * thread carries one. A test of "does this thread have any linked prefix" would
 * therefore read the entire queue as handled and suppress every reminder
 * forever, and it would do so quietly, because nothing errors. The only correct
 * test is membership in the CONFIGURED in-processing set
 * (cav7ERInProcessingPrefixIds), which is what this seam applies.
 *
 * Prefix ids that are not in that set — the S1 (66) and RTC (68) markers, the
 * "!!!" modifier (110) — are deliberately inert here. Inside the queue node they
 * only ever appear alongside a real status, so they add nothing as separate
 * suppressors, and treating them as statuses would widen the rule past what RRD
 * actually operates. One node over that stops holding: a thread in the Completed
 * forum can carry S1/RTC with no status at all. It costs nothing, because the
 * scan never leaves the queue node, and if a bare marker ever did turn up on a
 * queue thread this seam reads it as un-actioned and the thread is reminded. If
 * RRD starts using a marker as a status, the fix is to add its id to
 * cav7ERInProcessingPrefixIds, not to change this code.
 */
final class ProcessingStatus
{
    /**
     * The subset of the given prefix-link rows whose threads carry at least one
     * configured in-processing prefix, as a thread_id => true map for O(1)
     * lookup while building the reminder facts.
     *
     * A thread absent from the result carries no processing status — either it
     * has only its type prefix, or (a mis-prefixed thread) no linked prefix at
     * all. Both are remindable as far as this rule is concerned; the type
     * routing is what later skips a thread whose prefix marks no enlistment.
     *
     * Both sides end up as ints so they compare under in_array's strict test: the
     * configured set through PositionIdList::normalize, each row's ids through a
     * plain cast. The two are not interchangeable. The "a 0 can never match"
     * invariant rests on normalize's array_filter, which drops a junk 0 out of the
     * configured set, so the cast side is free to yield 0 for a missing or
     * unparseable id and simply match nothing. Simplifying normalize away would
     * lose the invariant; simplifying the casts away would break the string/int
     * compare.
     *
     * Rows with no usable thread id are dropped along with rows whose prefix is
     * not a status, so the result really is keyed by thread id as declared and
     * never grows a 0 key.
     *
     * @param array<int,array<string,mixed>> $prefixLinkRows rows of ['thread_id' => .., 'prefix_id' => ..]
     * @param int[]|string[]                 $inProcessingPrefixIds the configured status set, non-empty
     * @return array<int,true> thread_id => true
     * @throws \InvalidArgumentException when the configured set normalises to nothing
     */
    public static function inProcessingThreadIds(array $prefixLinkRows, array $inProcessingPrefixIds): array
    {
        $statusIds = PositionIdList::normalize($inProcessingPrefixIds);
        if (!$statusIds)
        {
            // Answering this would be worse than refusing it. [] reads to every
            // caller as "no thread is being worked", i.e. remind every
            // past-deadline application, which is the regression #186 exists to
            // fix; the only thing standing between that and the queue would be a
            // guard in another class. QueueReminder still owns the friendly path
            // and aborts with an admin-facing message before it gets here. This is
            // the backstop for the next caller: thrown from cron it aborts the run
            // and lands in the error log, which is what that guard chooses anyway.
            throw new \InvalidArgumentException(
                'inProcessingThreadIds needs a non-empty configured status set; with none, no thread can read as handled and every past-deadline application would be reminded.'
            );
        }

        $inProcessing = [];
        foreach ($prefixLinkRows as $row)
        {
            $threadId = (int) ($row['thread_id'] ?? 0);
            $prefixId = (int) ($row['prefix_id'] ?? 0);
            if ($threadId && $prefixId && in_array($prefixId, $statusIds, true))
            {
                $inProcessing[$threadId] = true;
            }
        }

        return $inProcessing;
    }
}
