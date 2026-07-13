<?php

namespace Cav7\MilpacMention;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Manager;
use XF\Mvc\Entity\Structure;

/**
 * Addon-wide code-event listeners for Cav7/MilpacMention.
 */
class Listener
{
    /**
     * entity_structure listener (hinted XF\Entity\User): declares the 'Milpac'
     * relation on every User, once for the whole request, so it is a first-class,
     * greppable relation any finder can use — not a request-lifetime side effect of
     * one read method. The find query (MilpacResolver::findMilpacOwningUsers) leans
     * on this declaration for its INNER join via with('Milpac', true).
     *
     * 'Milpac' is the inverse of NF\Rosters:RosterUser's own 'User' relation: one
     * RosterUser row (the member's milpac, /rosters/profile/<relation_id>/) whose
     * user_id column points back at this User. It is TO_ONE by the
     * one-user-one-milpac roster invariant (§4.4) and joins on
     * 'conditions' => 'user_id'. NF/Rosters is a hard require, so
     * NF\Rosters:RosterUser always exists — no dependency guard is needed.
     *
     * There is deliberately NO 'primary' => true here. 'primary' only rides along on
     * RosterUser.User because there the join column (user_id) IS the target XF:User's
     * primary key, so Manager::getRelation can resolve a lazy access by a whereId()
     * PK lookup. This relation points the other way: the target is RosterUser, whose
     * PK is relation_id, not user_id. Setting 'primary' would make a lazy
     * $user->Milpac access resolve as find('NF\Rosters:RosterUser', <user_id>) — a
     * whereId lookup against relation_id — returning the wrong member's milpac or
     * none. It matters more now the relation is always-on: every request exposes
     * $user->Milpac, so this would be wrong for every lazy access, not just the find
     * query. Without 'primary', a lazy access falls through to getRelationFinder,
     * which builds the correct WHERE user_id = <value> from 'conditions'. The find
     * query's INNER join is unaffected either way: Finder::join ignores 'primary' and
     * builds RosterUser.user_id = User.user_id straight from 'conditions'.
     *
     * @param Manager   $em
     * @param Structure $structure
     */
    public static function userEntityStructure(Manager $em, Structure &$structure): void
    {
        $structure->relations['Milpac'] = [
            'entity' => 'NF\Rosters:RosterUser',
            'type' => Entity::TO_ONE,
            'conditions' => 'user_id',
            // deliberately no 'primary' => true — see the method docblock: the inverse
            // join key (user_id) is not RosterUser's PK (relation_id), so 'primary'
            // would misresolve a lazy $user->Milpac to a PK lookup.
        ];
    }
}
