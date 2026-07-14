<?php

namespace Cav7\EnlistmentReminder;

/**
 * A comma/whitespace option string parsed to a list of positive, unique ids — the
 * add-on's shared id-list parser. Since issue #144 split the clerk set by type it
 * backs four options: the standard and re-enlistment clerk-position lists
 * (cav7ERStandardClerkPositionIds, cav7ERReenlistClerkPositionIds) and their
 * prefix lists (cav7ERStandardPrefixIds, cav7ERReenlistPrefixIds), the latter fed
 * straight into EnlistmentRouting. This parse decides those sets, so a mis-parse
 * is a behavioural failure the decision tests can't see: drop every id and the
 * run aborts on QueueReminder's empty-clerk guard; keep a 0 or a junk token and
 * the seat or prefix match resolves the wrong ids. Kept pure and free of any
 * XenForo dependency — a per-addon twin of RosterPatch's GroupIdList rather than a
 * reach across the bounded context (ADR-0002) — so every branch runs in plain PHP.
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
