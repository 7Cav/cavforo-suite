<?php

namespace Cav7\EnlistmentReminder;

/**
 * Reads a processing status off a queue thread's prefixes (issue #186) — the one
 * fact ReminderDecision uses to tell a started application from an un-actioned
 * one. Another pure seam of the add-on, a twin of PositionIdList and
 * EnlistmentRouting, so the rule runs for real in plain PHP with no XenForo.
 *
 * It is also the VALUE that fact travels in (issue #192). The constructor is
 * private and fromPrefixLinks is the only way in, so every ProcessingStatus in
 * existence was built by the membership rule below. A caller cannot hand the
 * decision rule its own idea of which threads are being worked, because it cannot
 * make one of these; the rule is not a convention the wiring is trusted to follow
 * but the only door there is. Before this, the fact reached the decision as a
 * plain bool per thread, and what kept the wiring honest was a regex over the
 * scanner's source text.
 *
 * RRD works the queue through a status prefix state machine supplied by the
 * SV/MultiPrefix add-on, which stores every prefix a thread carries in its own
 * thread-prefix link table. CONTEXT.md's "Processing status" term is the one home
 * for which statuses those are and what order they run in; nothing here depends on
 * either, because membership in a set is order-blind.
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
     * The threads carrying a status, as a thread_id => true map for O(1) lookup
     * while the decision runs. Not `readonly`: the add-on declares a PHP 8.0
     * floor and that keyword arrived in 8.1. Nothing writes to it after
     * construction.
     *
     * @var array<int,true>
     */
    private array $threadIds;

    /** @param array<int,true> $threadIds */
    private function __construct(array $threadIds)
    {
        $this->threadIds = $threadIds;
    }

    /**
     * Whether this thread carries one of the configured in-processing prefixes.
     *
     * A thread the rule never marked answers false, which covers both the thread
     * holding only its type prefix and the mis-prefixed thread with no linked
     * prefix at all. Both are remindable as far as this rule is concerned; the
     * type routing is what later skips a thread whose prefix marks no enlistment.
     */
    public function has(int $threadId): bool
    {
        return isset($this->threadIds[$threadId]);
    }

    /**
     * Read the processing status of every thread in the given prefix-link rows:
     * the threads carrying at least one configured in-processing prefix are the
     * ones has() answers true for.
     *
     * The only way to obtain a ProcessingStatus, so every one in existence was
     * built by the rule below rather than by a caller's own idea of what "being
     * processed" means.
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
     * not a status, so thread 0 — a thread that does not exist — never reads as
     * being worked.
     *
     * @param array<int,array<string,mixed>> $prefixLinkRows rows of ['thread_id' => .., 'prefix_id' => ..]
     * @param int[]|string[]                 $inProcessingPrefixIds the configured status set, non-empty
     * @throws \InvalidArgumentException when the configured set normalises to nothing
     */
    public static function fromPrefixLinks(array $prefixLinkRows, array $inProcessingPrefixIds): self
    {
        $statusIds = PositionIdList::normalize($inProcessingPrefixIds);
        if (!$statusIds)
        {
            // Answering this would be worse than refusing it. A set that marks
            // nothing reads to every caller as "no thread is being worked", i.e.
            // remind every past-deadline application, which is the regression #186
            // exists to fix; the only thing standing between that and the queue
            // would be a guard in another class. QueueReminder still owns the
            // friendly path and aborts with an admin-facing message before it gets
            // here. This is the backstop for the next caller: thrown from cron it
            // aborts the run and lands in the error log, which is what that guard
            // chooses anyway.
            throw new \InvalidArgumentException(
                'ProcessingStatus needs a non-empty configured status set; with none, no thread can read as handled and every past-deadline application would be reminded.'
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

        return new self($inProcessing);
    }
}
