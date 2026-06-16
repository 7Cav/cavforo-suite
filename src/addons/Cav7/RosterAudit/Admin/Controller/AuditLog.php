<?php

namespace Cav7\RosterAudit\Admin\Controller;

use Cav7\RosterAudit\Repository\AuditLog as AuditLogRepo;
use XF\Admin\Controller\AbstractController;
use XF\Mvc\ParameterBag;
use XF\Mvc\Reply\AbstractReply;

class AuditLog extends AbstractController
{
	protected function preDispatchController($action, ParameterBag $params): void
	{
		// Reuses the NF/Rosters admin permission: anyone who can manage rosters
		// can read their audit trail.
		$this->assertAdminPermission('nfManageRosters');
	}

	public function actionIndex(): AbstractReply
	{
		$page = $this->filterPage();
		$perPage = 50;

		$finder = $this->finder('Cav7\RosterAudit:AuditLog')
			->order('log_date', 'DESC');

		$contentType = $this->filter('content_type', 'str');
		if ($contentType)
		{
			$finder->where('content_type', $contentType);
		}

		$action = $this->filter('action', 'str');
		if ($action)
		{
			$finder->where('action', $action);
		}

		$username = $this->filter('username', 'str');
		if ($username)
		{
			$finder->where('username', 'LIKE', $finder->escapeLike($username, '%?%'));
		}

		$relationId = $this->filter('relation_id', 'uint');
		if ($relationId)
		{
			$finder->where('relation_id', $relationId);
		}

		// An unparseable date is a hard error, not an ignorable filter: silently
		// returning unfiltered results would let an admin conclude "nothing
		// happened in that range" mid-investigation.
		$dateStart = $this->filter('date_start', 'str');
		if ($dateStart)
		{
			$startTimestamp = $this->parseFilterDate($dateStart, '00:00:00');
			if ($startTimestamp === null)
			{
				return $this->error(\XF::phrase('cav7_raudit_invalid_date'));
			}
			$finder->where('log_date', '>=', $startTimestamp);
		}

		$dateEnd = $this->filter('date_end', 'str');
		if ($dateEnd)
		{
			$endTimestamp = $this->parseFilterDate($dateEnd, '23:59:59');
			if ($endTimestamp === null)
			{
				return $this->error(\XF::phrase('cav7_raudit_invalid_date'));
			}
			$finder->where('log_date', '<=', $endTimestamp);
		}

		$finder->with(['User', 'RosterUser', 'RosterUser.User']);

		$total = $finder->total();
		$entries = $finder->limitByPage($page, $perPage)->fetch();

		/** @var AuditLogRepo $repo */
		$repo = $this->repository('Cav7\RosterAudit:AuditLog');
		$details = $repo->getLogDetailsForEntries($entries);

		$viewParams = [
			'entries'     => $entries,
			'details'     => $details,
			'page'        => $page,
			'perPage'     => $perPage,
			'total'       => $total,
			'contentType' => $contentType,
			'action'      => $action,
			'username'    => $username,
			'relationId'  => $relationId,
			'dateStart'   => $dateStart,
			'dateEnd'     => $dateEnd,
			'contentTypes' => $this->getContentTypeOptions(),
		];

		return $this->view(
			'Cav7\RosterAudit:AuditLog\List',
			'cav7_raudit_log_list',
			$viewParams
		);
	}

	public function actionDetail(ParameterBag $params): AbstractReply
	{
		$logId = $this->filter('log_id', 'uint');

		$logEntry = $this->em()->find('Cav7\RosterAudit:AuditLog', $logId, ['User', 'RosterUser', 'RosterUser.User']);
		if (!$logEntry)
		{
			return $this->error(\XF::phrase('requested_page_not_found'), 404);
		}

		/** @var AuditLogRepo $repo */
		$repo = $this->repository('Cav7\RosterAudit:AuditLog');
		$detail = $repo->getLogDetail($logId);

		$viewParams = [
			'logEntry' => $logEntry,
			'detail'   => $detail,
		];

		return $this->view(
			'Cav7\RosterAudit:AuditLog\Detail',
			'cav7_raudit_log_detail',
			$viewParams
		);
	}

	/**
	 * Resolve a YYYY-MM-DD filter input to a UTC epoch, interpreting the date in
	 * the viewing admin's timezone. log_date is stored as a UTC epoch, so a naive
	 * strtotime() would interpret the date as UTC and skew day boundaries for
	 * admins in other timezones.
	 */
	protected function parseFilterDate(string $date, string $time): ?int
	{
		try
		{
			$tz = new \DateTimeZone(\XF::visitor()->timezone ?: 'UTC');
			$dt = new \DateTime($date . ' ' . $time, $tz);
			return $dt->getTimestamp();
		}
		catch (\Exception $e)
		{
			return null;
		}
	}

	protected function getContentTypeOptions(): array
	{
		$options = ['' => \XF::phrase('cav7_raudit_all_types')];
		foreach (AuditLogRepo::CONTENT_TYPES as $type)
		{
			$options[$type] = \XF::phrase('cav7_raudit_type_' . $type);
		}
		return $options;
	}
}
