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
 * thread-prefix link table:
 *
 *   (no status) -> In Progress -> Hold -> Approved
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
 * "!!!" modifier (110) — are deliberately inert here. On the live board they
 * only ever appear alongside a real status, so they add nothing as separate
 * suppressors, and treating them as statuses would widen the rule past what RRD
 * actually operates.
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
     * Ids are normalised to int on both sides, so the strings the DB hands back
     * and the ints PositionIdList parses compare as the same id, and a 0 — the
     * "no prefix" sentinel — can never match even if a junk 0 reaches the
     * configured set.
     *
     * @param array<int,array<string,mixed>> $prefixLinkRows rows of ['thread_id' => .., 'prefix_id' => ..]
     * @param int[]|string[]                 $inProcessingPrefixIds the configured status set
     * @return array<int,true> thread_id => true
     */
    public static function inProcessingThreadIds(array $prefixLinkRows, array $inProcessingPrefixIds): array
    {
        $statusIds = array_values(array_unique(array_filter(array_map('intval', $inProcessingPrefixIds))));
        if (!$statusIds)
        {
            return [];
        }

        $inProcessing = [];
        foreach ($prefixLinkRows as $row)
        {
            $prefixId = (int) ($row['prefix_id'] ?? 0);
            if ($prefixId && in_array($prefixId, $statusIds, true))
            {
                $inProcessing[(int) ($row['thread_id'] ?? 0)] = true;
            }
        }

        return $inProcessing;
    }
}
