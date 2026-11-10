<?php

namespace Cav7\Citations\NF\Rosters\Entity;

use NF\Rosters\Entity\RosterUserAward;
use NF\Rosters\Entity\ServiceRecord;
use XF\Mvc\Entity\Entity;

/**
 * The vendor's save actions build a new row here and never hand it back, so the row is kept
 * on the milpac, which the entity manager shares with the controller extension. Only that
 * extension reads it, to attach a pick after the save.
 */
class RosterUser extends XFCP_RosterUser
{
    public ?Entity $newRow = null;

    public function getNewAward(): RosterUserAward
    {
        return $this->newRow = parent::getNewAward();
    }

    public function getNewServiceRecord(): ServiceRecord
    {
        return $this->newRow = parent::getNewServiceRecord();
    }
}
