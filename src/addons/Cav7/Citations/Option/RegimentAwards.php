<?php

namespace Cav7\Citations\Option;

use Cav7\Citations\Grant\GrantRules;
use XF\Entity\Option;
use XF\Option\AbstractOption;

/** Assumes NF\\Rosters:Award is keyed by award id. */
class RegimentAwards extends AbstractOption
{
    public static function verifyOption(&$value, Option $option): bool
    {
        $ids = GrantRules::awardList((string) $value);
        if ($ids === null) {
            $option->error(\XF::phrase('cav7_cit_regiment_awards_not_a_list'), $option->option_id);
            return false;
        }

        $known = $ids === [] ? [] : \XF::finder('NF\Rosters:Award')->whereIds($ids)->fetch()->keys();
        $unknown = array_diff($ids, $known);
        if ($unknown !== []) {
            $option->error(\XF::phrase('cav7_cit_regiment_awards_unknown', ['ids' => implode(', ', $unknown)]), $option->option_id);
            return false;
        }

        $value = implode(',', $ids);
        return true;
    }
}
