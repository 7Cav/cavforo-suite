<?php

namespace Cav7\Citations\NF\Rosters\Entity;

use Cav7\Citations\Entity\CitationRow;
use Cav7\Citations\Storage\RowKind;

class RosterUserAward extends XFCP_RosterUserAward
{
    use CitationRow;

    protected function citationKind(): RowKind
    {
        return RowKind::Award;
    }
}
