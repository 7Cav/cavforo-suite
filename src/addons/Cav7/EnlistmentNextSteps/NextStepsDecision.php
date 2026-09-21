<?php

namespace Cav7\EnlistmentNextSteps;

/**
 * The one decision behind the next-steps page: does this form submission land
 * on the page, and for which thread? Pure PHP with no XenForo dependency, so
 * the rule runs for real in tests/NextStepsDecisionTest.php. The controller
 * extension (Snog\Forms\Pub\Controller\Form) supplies the three inputs and
 * builds the link from the id that comes back, so a route rename never touches
 * this class.
 *
 * It also owns reading the cav7ENSTriggeringFormIds option, in the shape of
 * Cav7\EnlistmentReminder's PositionIdList: split on any run of commas or
 * whitespace, keep the positive integers, drop 0 and anything else. Two rules
 * from issue #291 follow from that and are pinned by the test: an option that
 * parses to nothing triggers no form, never every form, so a bad edit cannot
 * put the page in front of every form on the board; and a junk token is
 * skipped on its own, so one typo does not switch the page off for the ids
 * beside it.
 */
final class NextStepsDecision
{
    /**
     * @param string $triggeringFormIds the raw option string, as stored
     * @param int $submittedFormId the Advanced Forms posid just submitted
     * @param int|null $createdThreadId the thread that submission created, or
     *        null when it created none
     * @return int|null the thread to show the next-steps page for, or null to
     *         leave the vendor's own reply alone
     */
    public static function threadToShow(string $triggeringFormIds, int $submittedFormId, ?int $createdThreadId): ?int
    {
        if ($createdThreadId === null) {
            return null;
        }

        if (!in_array($submittedFormId, self::parseFormIds($triggeringFormIds), true)) {
            return null;
        }

        return $createdThreadId;
    }

    /**
     * @return int[]
     */
    private static function parseFormIds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $ids = array_map('intval', preg_split('/[\s,]+/', $raw));

        return array_values(array_filter($ids, fn (int $id): bool => $id > 0));
    }
}
