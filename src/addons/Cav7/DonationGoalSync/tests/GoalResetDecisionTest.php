<?php

/**
 * Runs the class extension XenForo actually calls.
 *
 * The override takes over the vendor's reset decision for one configuration —
 * a goal that resets on the 1st of the month — and hands every other shape back
 * to the vendor. Both halves of that are behaviour a caller can see in the
 * returned verdict, so nothing here observes whether the parent was entered:
 * the stub parent answers with a verdict deliberately opposite to the correct
 * one, so a row that returns the right answer has necessarily decided it here,
 * and a row that returns the parent's answer has necessarily deferred. A
 * refactor that consults the parent first and overrides afterwards is invisible
 * to these rows, which is the point.
 *
 * The settings the stub feeds are the verbatim shape read off a live board:
 * {"enabled":"1","months":"1","1st_day":"1"} — every value a string, because
 * that is what XenForo's JSON column hands back. An int-shaped fixture would
 * pass against a shape the vendor never emits.
 *
 * Self-contained: no XenForo, no framework. Exits non-zero on any failure.
 *
 * Run:
 *   php tests/GoalResetDecisionTest.php
 */

// ---------------------------------------------------------------------------
// Stub XFCP proxy base — must exist before Goal.php is required. It stands in
// for Siropu\Donations\Entity\Goal: it serves the two columns the override
// reads, and answers the reset question with a settable sentinel so a deferred
// decision is distinguishable from one made here.
// ---------------------------------------------------------------------------

namespace Cav7\DonationGoalSync\Siropu\Donations\Entity {
    class XFCP_Goal
    {
        /** @var array<string, mixed> entity columns served via __get */
        public $stubValues = [];

        /** @var bool the verdict the vendor would have returned */
        public $stubParentVerdict = false;

        public function __get($name)
        {
            return $this->stubValues[$name] ?? null;
        }

        public function canResetRecurringGoal()
        {
            return $this->stubParentVerdict;
        }
    }
}

// ---------------------------------------------------------------------------
// \XF static facade stub — only the clock the override reads.
// ---------------------------------------------------------------------------

namespace {
    class XF
    {
        public static $time = 0;
    }
}

namespace Cav7\DonationGoalSync\Tests {

    require __DIR__ . '/../RecurringSchedule.php';
    require __DIR__ . '/../Siropu/Donations/Entity/Goal.php';

    use Cav7\DonationGoalSync\Siropu\Donations\Entity\Goal;

    $failures = 0;

    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures;
        if ($ok) {
            echo "PASS: $label\n";
        } else {
            $failures++;
            echo "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n";
        }
    }

    function at(string $utc): int
    {
        return (int) strtotime($utc . ' UTC');
    }

    /** The live shape: every value a string, as the JSON column returns them. */
    function goal(array $recurring, string $startDate, bool $parentVerdict): Goal
    {
        $g = new Goal();
        $g->stubValues = [
            'start_date' => at($startDate),
            'settings'   => $recurring === [] ? [] : ['recurring' => $recurring],
        ];
        $g->stubParentVerdict = $parentVerdict;

        return $g;
    }

    $resetsOn1st = ['enabled' => '1', 'months' => '1', '1st_day' => '1'];
    $rollingDate = ['enabled' => '1', 'months' => '1', '1st_day' => '0'];

    // -----------------------------------------------------------------------
    // The bug, at the seam XenForo calls. The cycle began at 00:30:43 on 2 July
    // and the cron is running at 00:30:31 on 1 August — twelve seconds before
    // the vendor's inherited threshold, which is why the vendor says no. The
    // stub parent says no with it. The override must say yes regardless.
    // -----------------------------------------------------------------------
    \XF::$time = at('2026-08-01 00:30:31');
    check(
        'a goal due today resets even when the cron fires before the old inherited second',
        goal($resetsOn1st, '2026-07-02 00:30:43', false)->canResetRecurringGoal() === true,
        'the vendor withholds the reset here; returning its verdict would reproduce the bug'
    );

    // The first instant of the due day is enough. Nothing later in the day is
    // required, which is the property that removes the race entirely.
    \XF::$time = at('2026-08-01 00:00:00');
    check(
        'a goal is due from the first instant of the 1st',
        goal($resetsOn1st, '2026-07-02 00:30:43', false)->canResetRecurringGoal() === true,
        'midnight is the boundary; requiring any later instant leaves a window to lose'
    );

    // The other direction, so the row above cannot be satisfied by always
    // answering yes. One second before the boundary the goal is not yet due,
    // and the stub parent's opposite verdict must not rescue it.
    \XF::$time = at('2026-07-31 23:59:59');
    check(
        'a goal is not due one second before the 1st',
        goal($resetsOn1st, '2026-07-02 00:30:43', true)->canResetRecurringGoal() === false,
        'the parent says yes here; deferring to it would reset the goal a day early'
    );

    // -----------------------------------------------------------------------
    // Everything the override does not own goes back to the vendor. Both
    // verdicts, so a row cannot pass by returning a constant.
    // -----------------------------------------------------------------------
    foreach ([true, false] as $vendorSays) {
        \XF::$time = at('2026-08-01 00:30:31');
        check(
            'a goal that does not reset on the 1st keeps the vendor\'s verdict (' . var_export($vendorSays, true) . ')',
            goal($rollingDate, '2026-07-02 00:30:43', $vendorSays)->canResetRecurringGoal() === $vendorSays,
            'a rolling anniversary has no day boundary to race, so the vendor keeps it'
        );

        check(
            'a goal with no recurring settings at all keeps the vendor\'s verdict (' . var_export($vendorSays, true) . ')',
            goal([], '2026-07-02 00:30:43', $vendorSays)->canResetRecurringGoal() === $vendorSays,
            'a non-recurring goal is none of this addon\'s business'
        );
    }

    exit($failures === 0 ? 0 : 1);
}
