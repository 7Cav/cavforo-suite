<?php

namespace Cav7\EnlistmentDefaults\NF\Rosters\Entity;

use Cav7\EnlistmentDefaults\EnlistmentApplier;
use Cav7\EnlistmentDefaults\EnlistmentDecisions;
use Cav7\EnlistmentDefaults\RosterUserGateway;

/**
 * Class extension on the vendor milpac entity. The only hook the addon adds:
 * after a new milpac row is inserted, apply the enlistment defaults (the PUC
 * set and the first service record) inside the same save.
 *
 * Insert-only by design. A move between rosters and an edit are both UPDATEs of
 * an existing row (Service\Profile\Mover saves the same RosterUser, the profile
 * edit saves it too), so EnlistmentDecisions::shouldApply() returns false for
 * them and applies nothing — re-applying the set to an existing milpac is never
 * done.
 *
 * Fail-open: the applier isolates and logs each grant and the record write, so a
 * failure is recorded in the XF error log and the milpac save still succeeds. If
 * the error log seam is what fails, nothing is recorded and the rest of the set
 * is lost; the milpac save survives even then. The note on the last-resort catch
 * below has the reasoning.
 *
 * Re-check on a dev stack after an NF/Rosters or XenForo upgrade: creating a
 * milpac still grants the whole PUC set, each award carrying its citation image,
 * and still writes the enlistment record. That is this extension still firing on
 * insert, and the two vendor row factories RosterUserGateway calls —
 * getNewAward() and getNewServiceRecord() — still being there. No test in this
 * repo can see it: they all compare our code against our own expectations, and
 * a break here is silent in production.
 */
class RosterUser extends XFCP_RosterUser
{
    protected function _postSave(): void
    {
        parent::_postSave();

        // The insert-only rule has one home: the pure, tested
        // EnlistmentDecisions::shouldApply(). Routing the gate through it keeps
        // the shipped gate and the unit test from drifting (AC3/AC4: no re-apply
        // on a move or an edit).
        if (!EnlistmentDecisions::shouldApply($this->isInsert()))
        {
            return;
        }

        // Built before the try so the catch can log through it. The constructor
        // only holds onto the entity, so there is nothing here that can fail.
        $gateway = new RosterUserGateway($this);

        try
        {
            (new EnlistmentApplier($gateway))->apply();
        }
        catch (\Throwable $e)
        {
            // Last-resort guard. The applier already isolates per-grant and the
            // record write; this catches anything before the loop (option reads,
            // award-date lookup) so enlistment can never be blocked.
            //
            // Logged through the gateway so this entry names the milpac exactly
            // as a dropped grant does — one identity stamp, one place.
            //
            // The log call is itself guarded, because sharing that one seam with
            // the applier's inner catches means a fault in it repeats rather than
            // happening once: logFailure throws from a per-grant catch, the throw
            // lands here, and logging it again throws identically. Unguarded, that
            // would fail the milpac save — the recruiter would be told the
            // creation failed over a logging fault. Nothing is left to log the
            // lost entry to, so it is dropped: a guard of last resort has to hold
            // even when the log seam is what broke.
            //
            // What it saves is the milpac save, and only that. The applier's own
            // logFailure calls are not guarded, so the first throw out of the
            // seam escapes the grant loop and apply() together: the grants after
            // it are never attempted and the enlistment record is never written.
            // A broken error log costs the enlistment defaults, not the
            // enlistment. FailureLoggingTest pins that blast radius, so extending
            // the guarding to the applier's calls — which would buy the rest of
            // the set and the record back, in exchange for failures nothing tries
            // to log at all — has to be taken as the policy choice it is.
            try
            {
                $gateway->logFailure($e, 'enlistment defaults failed');
            }
            catch (\Throwable)
            {
            }
        }
    }
}
