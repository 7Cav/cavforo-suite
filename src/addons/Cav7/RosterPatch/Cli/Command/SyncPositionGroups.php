<?php

namespace Cav7\RosterPatch\Cli\Command;

use Cav7\RosterPatch\Repository\PositionGroupSync;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XF\Cli\Command\AbstractCommand;

/**
 * One-off reconcile for the grant backlog the _postSave hook cannot reach: a
 * position whose group list is already correct but whose seated members still
 * carry the old snapshot, because the hook did not exist when they were seated.
 * Re-applies every position's current groups to the holders whose grant has
 * drifted from it, and reports only those. Idempotent, so it is safe to re-run —
 * a second pass reports nothing left to change. Add -v to list the affected
 * members. Exits non-zero if any holder's grant could not be applied.
 */
class SyncPositionGroups extends AbstractCommand
{
	protected function configure()
	{
		$this
			->setName('cav7-rosterpatch:sync-position-groups')
			->setDescription('Reconciles each roster position\'s group grants to its current holders, reporting only what changed.')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without applying anything.')
			->addOption('position', null, InputOption::VALUE_REQUIRED, 'Reconcile only this position id.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$dryRun = (bool) $input->getOption('dry-run');
		$positionId = $input->getOption('position');

		/** @var PositionGroupSync $repo */
		$repo = \XF::repository('Cav7\RosterPatch:PositionGroupSync');

		$finder = \XF::finder('NF\Rosters:Position')->order('position_id');
		if ($positionId !== null)
		{
			$finder->where('position_id', (int) $positionId);
		}
		$positions = $finder->fetch();

		if (!$positions->count())
		{
			// An explicit --position that matches nothing is an error; an empty
			// roster on a bare run is not (a cron wrapper should not see failure).
			if ($positionId !== null)
			{
				$output->writeln('<error>No position found with id ' . (int) $positionId . '.</error>');
				return 1;
			}
			$output->writeln('No roster positions to reconcile.');
			return 0;
		}

		$positionsWithDrift = 0;
		$membersChanged = 0;
		$failedUserIds = [];

		foreach ($positions AS $position)
		{
			$drifted = $repo->getDriftedHolderUserIds($position);
			if (!$drifted)
			{
				continue; // already in sync — nothing to report
			}

			$failed = $dryRun ? [] : $repo->applyPositionGroups($position, $drifted);
			$changed = count($drifted) - count($failed);

			$positionsWithDrift++;
			$membersChanged += $changed;
			$failedUserIds = array_merge($failedUserIds, $failed);

			$groupStr = $position->extra_group_ids ? implode(',', $position->extra_group_ids) : 'none';
			$output->writeln(sprintf(
				'%sposition %d "%s" [%s]: %d member(s) %s',
				$dryRun ? '[dry-run] ' : '',
				$position->position_id,
				$position->position_title,
				$groupStr,
				$changed,
				$dryRun ? 'would change' : 'changed'
			));

			if ($output->isVerbose())
			{
				$names = $this->usernamesFor(array_diff($drifted, $failed));
				foreach ($names AS $name)
				{
					$output->writeln('    - ' . $name);
				}
			}
		}

		$output->writeln('');
		if ($positionsWithDrift === 0)
		{
			$output->writeln($dryRun
				? 'All matching positions are already in sync — nothing would change.'
				: 'All matching positions are already in sync — nothing changed.');
		}
		else
		{
			$output->writeln(sprintf(
				'%s%d member(s) across %d position(s) %s.',
				$dryRun ? '[dry-run] ' : '',
				$membersChanged,
				$positionsWithDrift,
				$dryRun ? 'would be reconciled' : 'reconciled'
			));
		}

		if ($failedUserIds)
		{
			$output->writeln(sprintf(
				'<error>%d holder(s) could not be reconciled (user id(s): %s) — see the server error log.</error>',
				count($failedUserIds),
				implode(', ', $failedUserIds)
			));
			return 1;
		}

		return 0;
	}

	/**
	 * @param int[] $userIds
	 * @return string[]
	 */
	protected function usernamesFor(array $userIds): array
	{
		if (!$userIds)
		{
			return [];
		}

		return \XF::db()->fetchAllColumn(
			'SELECT username FROM xf_user WHERE user_id IN (' . \XF::db()->quote($userIds) . ') ORDER BY username'
		);
	}
}
