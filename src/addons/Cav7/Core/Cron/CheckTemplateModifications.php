<?php

namespace Cav7\Core\Cron;

use Cav7\Core\TemplateModification\BoardFacts;
use Cav7\Core\TemplateModification\DiscoveryFailed;
use Cav7\Core\TemplateModification\Reconciliation;

/**
 * Issue #171 — the scheduled half of the template modification check.
 *
 * The command answers the question when somebody asks it. This asks it daily
 * and puts the answer where an administrator already looks: XenForo's error
 * log, which raises the admin dashboard's "server errors have been logged"
 * notice. That notice is the whole point — a patch that stopped reaching the
 * page is otherwise silent until somebody notices the wrong output.
 *
 * It logs on every run, with no state tracking to suppress a repeat. A failure
 * that has been there a week is still a failure, and a mechanism for going
 * quiet about a known one is how the log stops being read.
 */
class CheckTemplateModifications
{
    public static function run(): void
    {
        try {
            $facts = BoardFacts::gather(\XF::app());
        } catch (DiscoveryFailed $e) {
            // Logged as loudly as a failure. A run that could not establish what
            // to check has not cleared the board, and silence here would read as
            // a clean night.
            \XF::logError('Cav7/Core: the template modification check could not be performed: ' . $e->getMessage());
            return;
        }

        foreach (Reconciliation::failures($facts->modifications) as $failure) {
            // One entry per failure rather than one summary. Each names its own
            // remedy, and the log is read one entry at a time.
            \XF::logError('Cav7/Core: template modification not in force — ' . $failure->line());
        }
    }
}
