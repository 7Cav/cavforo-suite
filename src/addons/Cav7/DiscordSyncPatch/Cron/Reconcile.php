<?php

namespace Cav7\DiscordSyncPatch\Cron;

use Cav7\DiscordSyncPatch\ReconciliationSweep;

/**
 * Issue #157 — the scheduled entry point for the reconciliation sweep.
 *
 * Kept thin deliberately: the run is in ReconciliationSweep, and every rule that run
 * applies is in a pure unit beside it. The interval is set from the XenForo cron admin
 * page rather than an addon option, so there is no configuration to read here.
 */
class Reconcile
{
    public static function run(): void
    {
        (new ReconciliationSweep())->run();
    }
}
