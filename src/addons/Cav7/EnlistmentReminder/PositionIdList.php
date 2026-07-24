<?php

namespace Cav7\EnlistmentReminder;

/**
 * A comma/whitespace option string parsed to a list of unique int ids — the
 * add-on's shared id-list parser, and — through normalize — the one home for that
 * shaping when the ids arrive already split, as they do in EnlistmentRouting and
 * ProcessingStatus. Since issue #144 split the clerk set by type and issue #186
 * keyed the handled signal off a status prefix, it backs five options:
 * the standard and re-enlistment clerk-position lists
 * (cav7ERStandardClerkPositionIds, cav7ERReenlistClerkPositionIds), their type
 * prefix lists (cav7ERStandardPrefixIds, cav7ERReenlistPrefixIds) fed straight into
 * EnlistmentRouting, and the processing-status prefixes
 * (cav7ERInProcessingPrefixIds) that decide whether a thread reads as handled. This
 * parse decides those sets, so a mis-parse is a behavioural failure the decision
 * tests can't see: drop every id and the run aborts on QueueReminder's empty-clerk
 * or empty-status guard; keep a 0 or a junk token and the seat or prefix match
 * resolves the wrong ids. Kept pure and free of any XenForo dependency — a
 * per-addon twin of RosterPatch's GroupIdList rather than a reach across the
 * bounded context (ADR-0002) — so every branch runs in plain PHP.
 */
final class PositionIdList
{
    /**
     * Parse a comma/whitespace separated option string to int position ids,
     * de-duplicated with first-seen order preserved. A blank or junk-only
     * string parses to [], which each caller reads as "nothing configured" for its
     * own option: no clerk seats to alert, no processing status to recognise, or no
     * prefix that routes to a type. The first two abort the run on their own guard
     * in QueueReminder; the last makes EnlistmentRouting read every thread of that
     * type as unrecognized and skip it.
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

        return self::normalize(preg_split('/[\s,]+/', $raw));
    }

    /**
     * The same id-list shaping applied to an array of raw ids that arrived already
     * split — a config array whose entries may be ints or hand-typed strings. Ints,
     * de-duplicated with first-seen order preserved, re-indexed as a list.
     *
     * This is the CONFIGURED side of every id comparison the add-on makes, and only
     * that side. It is what lets an id set arrive as `['53', '54']` or `[53, 54]` and
     * compare the same under `in_array`'s strict test. The row side is cast at each
     * read site instead — a plain `(int)` on the column — so no caller should read
     * this as normalising database values on their behalf; dropping those casts on
     * that strength would break the compare. ProcessingStatus's docblock spells the
     * division out. EnlistmentRouting shapes its four constructor lists here and
     * ProcessingStatus its configured status set; parse() ends here too, so an option
     * string and an option array take one path.
     *
     * array_filter drops the 0 intval yields for a blank segment, a trailing comma
     * or non-numeric junk, so no stray token becomes a phantom seat and the 0 that
     * means "no prefix" can never match. A negative token survives as an inert id;
     * no real prefix or position id is negative, so it matches nothing.
     *
     * @param array<int|string|null> $ids
     * @return int[]
     */
    public static function normalize(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
