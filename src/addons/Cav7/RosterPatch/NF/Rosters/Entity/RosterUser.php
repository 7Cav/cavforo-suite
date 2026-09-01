<?php

namespace Cav7\RosterPatch\NF\Rosters\Entity;

/**
 * Class extension on NF\Rosters\Entity\RosterUser.
 *
 * A member has at most one milpac, and nothing else enforces it.
 * `xf_nf_rosters_user` indexes user_id without a UNIQUE key, the vendor entity
 * declares no _preSave, and the vendor's Adder validates only that the
 * submitted username resolves to a user. Adding the same member twice therefore
 * succeeded silently, and each insert ran Cav7\EnlistmentDefaults' insert-gated
 * _postSave, so the duplicate drew its own PUC set and enlistment record.
 *
 * The entity is the seam because it is the one both write paths share:
 * Service\Profile\Adder inserts this row and Service\Profile\Mover updates its
 * roster_id, so a guard here covers the add and the move at once, and covers
 * anything added later without a third guard. Raising an entity error from
 * _preSave is what stops the write: XF\Mvc\Entity\Entity::save() turns a
 * pre-save error into a PrintableException, which XenForo renders to the
 * staffer as an ordinary error message, and the row is never inserted, so
 * EnlistmentDefaults' _postSave never runs and no awards are granted.
 *
 * The rule is one milpac per member across every roster, not one per roster
 * (the suite glossary in the root CONTEXT.md), so the read is not scoped to the
 * roster being saved to.
 *
 * The row being saved is excluded by its own relation_id, which is what keeps
 * an ordinary update — a profile edit, a move, a uniform upload — from refusing
 * itself. That exclusion is done here rather than in the query so a test can
 * reach it without a database; the read stays a plain list of the rows this
 * member holds.
 *
 * A member who already holds two rows is refused on every save of either row
 * until the duplicate is resolved. That is deliberate: the state is a data
 * error, and delete still works, which is the way out.
 *
 * Two rows can still be created by two adds racing each other: the read and the
 * insert are not atomic, and only a UNIQUE KEY on the table would close that.
 * The table is the vendor's, and nothing here adds one.
 */
class RosterUser extends XFCP_RosterUser
{
	protected function _preSave(): void
	{
		parent::_preSave();

		$held = $this->db()->fetchAllColumn(
			'SELECT relation_id FROM xf_nf_rosters_user WHERE user_id = ?',
			$this->user_id
		);

		foreach ($held as $relationId)
		{
			if ((int) $relationId !== (int) $this->relation_id)
			{
				$this->error(\XF::phrase('cav7_rpatch_member_already_has_milpac'));
				break;
			}
		}
	}
}
