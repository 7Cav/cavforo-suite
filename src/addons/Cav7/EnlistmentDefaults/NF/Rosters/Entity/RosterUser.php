<?php

namespace Cav7\EnlistmentDefaults\NF\Rosters\Entity;

use Cav7\EnlistmentDefaults\EnlistmentApplier;
use Cav7\EnlistmentDefaults\EnlistmentDecisions;
use Cav7\EnlistmentDefaults\RosterUserGateway;

/**
 * Gives a new milpac its PUC rows and enlistment record inside the save that inserts it.
 * A failure is logged and never fails the save (ADR-0002).
 *
 * Re-check on a dev stack after an NF/Rosters or XenForo upgrade: with the PUC sync on,
 * creating a milpac still adds one linked PUC row per live regiment grant and the
 * enlistment record, and moving a milpac between rosters adds nothing.
 */
class RosterUser extends XFCP_RosterUser
{
    protected function _postSave(): void
    {
        parent::_postSave();

        if (!EnlistmentDecisions::shouldApply($this->isInsert()))
        {
            return;
        }

        $gateway = new RosterUserGateway($this);

        try
        {
            (new EnlistmentApplier($gateway))->apply();
        }
        catch (\Throwable $e)
        {
            // Guarded too: a broken error log throws here exactly as it did inside the
            // applier, and that must not fail the milpac save.
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
