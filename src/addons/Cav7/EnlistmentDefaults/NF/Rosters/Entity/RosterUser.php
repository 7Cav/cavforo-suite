<?php

namespace Cav7\EnlistmentDefaults\NF\Rosters\Entity;

use Cav7\EnlistmentDefaults\EnlistmentApplier;
use Cav7\EnlistmentDefaults\RosterUserGateway;

/**
 * Class extension on the vendor milpac entity. The only hook the addon adds:
 * after a new milpac row is inserted, apply the enlistment defaults (the PUC
 * set and the first service record) inside the same save.
 *
 * Insert-only by design. A move between rosters and an edit are both UPDATEs of
 * an existing row (Service\Profile\Mover saves the same RosterUser, the profile
 * edit saves it too), so they fall through the isInsert() gate and apply
 * nothing — re-applying the set to an existing milpac is never done.
 *
 * Fail-open: the applier isolates and logs each grant and the record write, so a
 * failure is recorded in the XF error log and the milpac save still succeeds.
 */
class RosterUser extends XFCP_RosterUser
{
    protected function _postSave(): void
    {
        parent::_postSave();

        if (!$this->isInsert())
        {
            return;
        }

        try
        {
            $gateway = new RosterUserGateway($this);
            (new EnlistmentApplier($gateway))->apply();
        }
        catch (\Throwable $e)
        {
            // Last-resort guard. The applier already isolates per-grant and the
            // record write; this catches anything before the loop (option reads,
            // award-date lookup) so enlistment can never be blocked.
            \XF::logException(
                $e,
                false,
                'Cav7/EnlistmentDefaults: enlistment defaults failed: '
            );
        }
    }
}
