<?php

namespace Cav7\EnlistmentReminder;

/**
 * The configured clerk-seat option (cav7ERClerkPositionIds) parsed to a list of
 * positive, unique position ids. This parse decides the clerk set, so a mis-parse
 * is a behavioural failure the decision tests can't see: drop every id and the
 * run aborts on QueueReminder's empty-clerk guard; keep a 0 or a junk token and
 * the seat query resolves the wrong positions. Kept pure and free of any XenForo
 * dependency — a per-addon twin of RosterPatch's GroupIdList rather than a reach
 * across the bounded context (ADR-0002) — so every branch runs in plain PHP.
 */
final class PositionIdList
{
    /**
     * Parse a comma/whitespace separated option string to positive int position
     * ids, de-duplicated with first-seen order preserved. A blank or junk-only
     * string parses to [], which the caller treats as "no clerks configured".
     *
     * @return int[]
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '')
        {
            return [];
        }

        // array_filter drops 0 and the empty segments intval yields for a trailing
        // comma or non-numeric junk, so '579,' and 'abc' parse to [579] and [].
        $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $raw)));

        return array_values(array_unique($ids));
    }
}
