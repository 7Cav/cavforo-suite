<?php

namespace Cav7\Citations\NF\Rosters\Entity;

use Cav7\Citations\Entity\CitationRow;
use Cav7\Citations\Storage\RowKind;

class ServiceRecord extends XFCP_ServiceRecord
{
    use CitationRow;

    protected function citationKind(): RowKind
    {
        return RowKind::Record;
    }
}
