<?php

namespace Cav7\Core\TemplateModification;

/**
 * Which of the shipped modifications are not in force, given what the board
 * says about each of them.
 *
 * A reconciliation rather than a scan. The set of results that ought to exist
 * is built first, from what the add-ons ship, and the board's records are read
 * against it. Reading only the rows that happen to exist is what makes the
 * current situation invisible: XenForo writes a result when it applies a
 * modification to a template, so the states where it applied nothing leave
 * nothing to read, and a reader that walks the rows finds them all healthy.
 *
 * No CI coverage, deliberately, and for a reason of its own: the facts this
 * takes are an internal handoff from `BoardFacts` rather than an interface any
 * caller builds — both callers pass one straight to the other — so a test here
 * would pin the shape of that handoff and break on a refactor that changed no
 * behaviour, while the bugs worth catching (a copy never enumerated, a join
 * that drops a style) live in the gathering it does not touch. Every shape
 * below is produced against a real board in
 * `docs/verification/template-modifications-in-force.md`; what its absence from
 * CI costs is in the addon's README.
 */
class Reconciliation
{
    /**
     * @param list<array> $modifications As `BoardFacts` gathers them.
     *
     * @return list<Failure>
     */
    public static function failures(array $modifications): array
    {
        $failures = [];

        foreach ($modifications as $modification) {
            foreach (self::failuresFor($modification) as $failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /**
     * @return list<Failure>
     */
    protected static function failuresFor(array $modification): array
    {
        // The four states below mean XenForo attempted nothing, so no copy is
        // at fault and none is singled out. Each still names the copies the
        // modification would have reached, because "one inert style" and "every
        // member on the board" are otherwise the same line.
        $attemptedNothing = function (string $shape, string $reason) use ($modification): array
        {
            return [Failure::forModification($shape, $modification, $modification['copies'], $reason)];
        };

        if (!$modification['installed']) {
            return $attemptedNothing(
                Shape::NOT_INSTALLED,
                'the add-on ships this in its _data and the board holds no record of it, so its data was never'
                . ' imported. Nothing hashes _data, so an add-on whose version_id did not move installs its files'
                . ' and imports none of its data while every version stamp reads current. Run "'
                . self::rebuildCommand($modification['addon_id']) . '" and re-run this check'
            );
        }

        // Before the enabled flag rather than after it. An inactive add-on
        // takes every modification it owns with it, so reporting that once for
        // all of them reads as the one fault it is; the other order splits the
        // same fault into two kinds of line depending on a flag nobody has
        // touched.
        if (!$modification['addon_active']) {
            return $attemptedNothing(
                Shape::ADDON_INACTIVE,
                'the owning add-on is installed but not active, and XenForo applies no modification belonging to an'
                . ' inactive add-on. Reactivate the add-on'
            );
        }

        if (!$modification['enabled']) {
            return $attemptedNothing(
                Shape::DISABLED,
                'the record exists and is switched off. Every modification this suite ships is enabled, so somebody'
                . ' disabled this on the board, and no add-on upgrade will turn it back on. Re-enable it under'
                . ' Appearance > Styles & templates > Template modifications'
            );
        }

        if (!$modification['copies']) {
            return $attemptedNothing(
                Shape::TEMPLATE_MISSING,
                'no copy of this template exists on the board, so there is nothing for the modification to be applied'
                . ' to. The vendor renamed or dropped it, and the patch needs pointing at whatever replaced it'
            );
        }

        $failures = [];

        foreach ($modification['copies'] as $copy) {
            $failure = self::failureForCopy($modification, $copy);
            if ($failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }

    /**
     * One template copy, against the result XenForo recorded for it.
     *
     * The results describe the last compile rather than live state, since
     * XenForo writes them when a template is saved. That is still the right
     * signal, and nothing here re-saves anything to freshen it.
     */
    protected static function failureForCopy(array $modification, array $copy): ?Failure
    {
        $result = $copy['result'];
        $isMaster = $copy['style_id'] === 0;

        // A copy that exists with nothing recorded against it. Enabled, on an
        // active add-on, against a template that is right there — so none of
        // the shapes above explains the silence, and the patch is provably not
        // on the copy. This is the case a reader of the existing rows cannot
        // see at all, and the one that must not pass by absence.
        if ($result === null) {
            return Failure::atCopy(
                Shape::MATCHED_NOTHING,
                $modification,
                $copy,
                'XenForo has recorded no result against this copy, so it has not been compiled since the'
                . ' modification was installed and the copy cannot be carrying the patch. Run "'
                . self::rebuildCommand($modification['addon_id']) . '", which re-imports the modification and'
                . ' recompiles every copy of the template it targets, and re-run this check'
            );
        }

        if ($result['status'] !== 'ok') {
            return Failure::atCopy(
                Shape::BAD_STATUS,
                $modification,
                $copy,
                sprintf(
                    'XenForo recorded the status "%s" against this copy, so it could not apply or compile the'
                    . ' modification. The find pattern or the replacement is at fault rather than the board',
                    $result['status']
                )
            );
        }

        if ($result['apply_count'] === 0) {
            // Same symptom, two diagnoses, and the copy says which. A master
            // copy that matches nothing means the vendor's own markup moved and
            // every style inherits the problem; a style copy means somebody
            // edited that style and only what resolves to it is affected.
            return Failure::atCopy(
                Shape::MATCHED_NOTHING,
                $modification,
                $copy,
                $isMaster
                    ? 'the find matched nothing in the master copy, so the vendor\'s own markup has moved: every'
                        . ' style inheriting this copy is unpatched, and so is any style created from now on.'
                        . ' Compare the find against the template the vendor now ships'
                    : 'the find matched nothing in this style\'s own copy, so somebody edited it away from what the'
                        . ' find expects. Compare this copy against the master copy, which the same run reports on'
                        . ' separately'
            );
        }

        return null;
    }

    /**
     * The command both remedies tell an operator to type.
     *
     * Spelled once because a remedy naming a command that does not exist reads
     * exactly like one that does, and the reader finds out by typing it. One
     * spelling is one thing for the dev-stack pass to run.
     */
    protected static function rebuildCommand(string $addOnId): string
    {
        return "php cmd.php xf:addon-rebuild $addOnId";
    }
}
