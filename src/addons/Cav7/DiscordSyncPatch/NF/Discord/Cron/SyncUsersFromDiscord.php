<?php

namespace Cav7\DiscordSyncPatch\NF\Discord\Cron;

/**
 * Issue #157, ADR-0003 — vetoes NF/Discord's own scheduled reconciler so this addon
 * is the only thing keeping a member's Discord roles in step with their forum groups.
 *
 * The vendor's cron re-runs the full per-user sync for every connected member on a
 * rotation, blindly: a member fetch and an unconditional role write each, thousands of
 * Discord calls to correct a guild where a handful of members have actually diverged.
 * It also queues with change logging suppressed, so its corrections land invisibly.
 *
 * Today it is dormant — it returns early unless nfDiscordEnableReverseSync is true,
 * and the vendor defines that option nowhere, so it reads false. That is not something
 * to rely on: defining the option is one row, and the same option gates the vendor's
 * genuinely reverse-direction code. With the option ever turned on, two schedules
 * would drive the same roles, and if the vendor's logic diverged from ours they would
 * push different answers at each other indefinitely.
 *
 * The scheduled path resolves a cron entry's class through extendClass before invoking
 * it (XF\Job\Cron), the same seam this addon already uses for the sync message, so
 * every scheduled firing runs this override.
 *
 * It does NOT bind one path: XF\Admin\Controller\CronEntryController::actionRun calls
 * call_user_func([$entry->cron_class, $entry->cron_method]) on the raw class, so an
 * admin pressing "Run" against the vendor's entry in the control panel reaches the
 * vendor's own method. That is a deliberate, single, human-initiated act rather than a
 * second reconciler running on a schedule, which is what ADR-0003 is about, and the
 * vendor's own option gate still stops it there. Worth knowing before treating this
 * override as absolute — and worth firing through the scheduled path, not the admin
 * button, when verifying it.
 *
 * Reverting is disabling the addon, which returns the vendor's cron to its own
 * still-dormant behaviour.
 *
 * The parent is never called. Job\ReverseSync and Service\ReverseSync are left alone:
 * nothing reaches them today and this cron was not their trigger.
 */
class SyncUsersFromDiscord extends XFCP_SyncUsersFromDiscord
{
    public static function syncUsers(): void
    {
    }
}
