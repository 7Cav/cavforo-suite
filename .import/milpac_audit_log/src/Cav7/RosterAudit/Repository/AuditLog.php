<?php

namespace Cav7\RosterAudit\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class AuditLog extends Repository
{
	const TYPE_ROSTER = 'roster';
	const TYPE_ROSTER_USER = 'roster_user';
	const TYPE_USER_AWARD = 'user_award';
	const TYPE_SERVICE_RECORD = 'service_record';
	const TYPE_AWARD = 'award';
	const TYPE_AWARD_GROUP = 'award_group';
	const TYPE_POSITION = 'position';
	const TYPE_POSITION_GROUP = 'position_group';
	const TYPE_RANK = 'rank';
	const TYPE_RECORD_TYPE = 'record_type';
	const TYPE_FIELD = 'field';

	/**
	 * Single source of truth for audited content types. Each entry must match
	 * a getAuditContentType() in the entity extensions, with a matching
	 * cav7_raudit_type_<type> phrase; the AuditLog entity enforces membership
	 * via allowedValues, so an unknown type fails at write time.
	 *
	 * Deliberately not audited: NF\Rosters' RosterPosition and RosterField are
	 * roster<->position/field attachment rows edited as part of roster
	 * configuration, not standalone records. To audit them, add extensions and
	 * TYPE_ constants here — and note RosterPosition has a composite primary
	 * key (roster_id, position_id), which the writer records as "a-b".
	 */
	const CONTENT_TYPES = [
		self::TYPE_ROSTER, self::TYPE_ROSTER_USER, self::TYPE_USER_AWARD,
		self::TYPE_SERVICE_RECORD, self::TYPE_AWARD, self::TYPE_AWARD_GROUP,
		self::TYPE_POSITION, self::TYPE_POSITION_GROUP, self::TYPE_RANK,
		self::TYPE_RECORD_TYPE, self::TYPE_FIELD,
	];

	public function findAuditLogs(): Finder
	{
		return $this->finder('Cav7\RosterAudit:AuditLog')
			->order('log_date', 'DESC');
	}

	public function findAuditLogsForRelation(int $relationId): Finder
	{
		return $this->findAuditLogs()
			->where('relation_id', $relationId);
	}

	/**
	 * The single write path for audit index rows. Goes through the entity so
	 * its declared constraints (required, maxLength, allowedValues on action
	 * and content_type) actually guard what reaches the table.
	 */
	public function insertLogEntry(array $values): \Cav7\RosterAudit\Entity\AuditLog
	{
		/** @var \Cav7\RosterAudit\Entity\AuditLog $log */
		$log = $this->em->create('Cav7\RosterAudit:AuditLog');
		$log->bulkSet($values);
		$log->save();

		return $log;
	}

	public function getLogDetail(int $logId): ?array
	{
		$log = $this->em->find('Cav7\RosterAudit:AuditLog', $logId);
		if (!$log)
		{
			return null;
		}

		$logFile = $this->getLogFilePath($log->log_date);

		try
		{
			if (!file_exists($logFile))
			{
				// Rows are pruned before files at the same cutoff, so a live row
				// without its file is an integrity anomaly, not a pruning artifact.
				\XF::logError(sprintf('Audit log row %d has no detail file: %s', $logId, $logFile));
				return null;
			}

			$handle = fopen($logFile, 'r');
			if (!$handle)
			{
				// The DB row exists but its detail file is unreadable — an integrity
				// problem worth surfacing, distinct from "log not found".
				\XF::logError(sprintf('Audit detail file exists but could not be opened: %s', $logFile));
				return null;
			}

			$badLines = 0;
			while (($line = fgets($handle)) !== false)
			{
				$line = trim($line);
				if ($line === '')
				{
					continue;
				}
				$entry = json_decode($line, true);
				if (!is_array($entry))
				{
					$badLines++;
					continue;
				}
				if (($entry['log_id'] ?? 0) === $logId)
				{
					fclose($handle);
					return $this->prepareDetail($entry);
				}
			}
			fclose($handle);

			if ($badLines)
			{
				\XF::logError(sprintf('Audit detail file %s contains %d undecodable line(s)', $logFile, $badLines));
			}

			// Row exists, file exists, line absent: by the writer's ordering this
			// is a crash artifact — the same integrity class as a missing file,
			// so don't let it be the one variant that stays invisible.
			\XF::logError(sprintf('Audit log row %d has no detail line in %s', $logId, $logFile));
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'Audit log detail read failed: ');
		}

		return null;
	}

	public function getLogDetailsForEntries($entries): array
	{
		$byFile = [];
		foreach ($entries as $entry)
		{
			$file = $this->getLogFilePath($entry->log_date);
			$byFile[$file][$entry->log_id] = true;
		}

		$results = [];
		foreach ($byFile as $file => $ids)
		{
			if (!file_exists($file))
			{
				\XF::logError(sprintf('Audit detail file missing for %d listed row(s): %s', count($ids), $file));
				continue;
			}
			try
			{
				$handle = fopen($file, 'r');
				if (!$handle)
				{
					\XF::logError(sprintf('Audit detail file exists but could not be opened: %s', $file));
					continue;
				}
				$badLines = 0;
				while (($line = fgets($handle)) !== false)
				{
					$line = trim($line);
					if ($line === '')
					{
						continue;
					}
					$entry = json_decode($line, true);
					if (!is_array($entry))
					{
						$badLines++;
						continue;
					}
					$logId = $entry['log_id'] ?? 0;
					if (isset($ids[$logId]))
					{
						$results[$logId] = $this->prepareDetail($entry);
					}
				}
				fclose($handle);

				if ($badLines)
				{
					\XF::logError(sprintf('Audit detail file %s contains %d undecodable line(s)', $file, $badLines));
				}

				$missing = array_diff_key($ids, $results);
				if ($missing)
				{
					// Same crash-artifact class as in getLogDetail().
					\XF::logError(sprintf(
						'Audit detail file %s has no line for row(s): %s',
						$file, implode(', ', array_keys($missing))
					));
				}
			}
			catch (\Throwable $e)
			{
				\XF::logException($e, false, 'Audit log batch detail read failed: ');
			}
		}

		return $results;
	}

	public function pruneAuditLogs(int $cutoff): void
	{
		$db = $this->db();
		do
		{
			// Raw SQL because AbstractAdapter::delete() has no LIMIT support; the
			// LIMIT keeps each statement's lock footprint bounded on large tables.
			$deleted = $db->query(
				'DELETE FROM xf_cav7_roster_audit_log WHERE log_date < ? LIMIT 1000',
				$cutoff
			)->rowsAffected();
		}
		while ($deleted === 1000);
	}

	public function pruneAuditLogFiles(int $cutoff): void
	{
		try
		{
			$basePath = $this->getLogBasePath();
			if (!is_dir($basePath))
			{
				return;
			}

			$cutoffYear = gmdate('Y', $cutoff);
			$cutoffDayFile = gmdate('m-d', $cutoff) . '.jsonl';

			$yearDirs = $this->globOrLogFailure($basePath . '/*', GLOB_ONLYDIR) ?? [];
			foreach ($yearDirs as $yearDir)
			{
				$year = basename($yearDir);

				// Only touch directories that are actually YYYY year buckets; never
				// let a stray directory fall into a string comparison and get deleted.
				if (!preg_match('/^\d{4}$/', $year))
				{
					continue;
				}

				if ($year < $cutoffYear)
				{
					foreach ($this->globOrLogFailure($yearDir . '/*.jsonl') ?? [] as $file)
					{
						$this->deleteLogFile($file);
					}
					$this->removeYearDirIfEmpty($yearDir);
					continue;
				}

				if ($year === $cutoffYear)
				{
					foreach ($this->globOrLogFailure($yearDir . '/*.jsonl') ?? [] as $file)
					{
						$name = basename($file);
						// Guard against non MM-DD.jsonl names so the lexicographic
						// comparison below can't make a wrong delete decision.
						if (!preg_match('/^\d{2}-\d{2}\.jsonl$/', $name))
						{
							continue;
						}
						if ($name < $cutoffDayFile)
						{
							$this->deleteLogFile($file);
						}
					}
				}
			}
		}
		catch (\Throwable $e)
		{
			\XF::logException($e, false, 'Audit log file prune failed: ');
		}
	}

	/**
	 * glob() returning false is an error, not "no matches"; treating the two
	 * the same would silently skip a prune pass over files holding usernames,
	 * IPs and change payloads past their retention.
	 */
	protected function globOrLogFailure(string $pattern, int $flags = 0): ?array
	{
		$paths = glob($pattern, $flags);
		if ($paths === false)
		{
			\XF::logError('Audit log prune: glob() failed for ' . $pattern);
			return null;
		}

		return $paths;
	}

	protected function deleteLogFile(string $file): void
	{
		// A retention failure must be loud: a file that survives its prune holds
		// usernames, IPs and full change payloads past the retention policy.
		if (!@unlink($file))
		{
			\XF::logError('Audit log prune could not delete file: ' . $file);
		}
	}

	protected function removeYearDirIfEmpty(string $yearDir): void
	{
		$remaining = $this->globOrLogFailure($yearDir . '/*');
		if ($remaining === [] && !@rmdir($yearDir))
		{
			\XF::logError('Audit log prune could not remove directory: ' . $yearDir);
		}
	}

	protected function prepareDetail(array $entry): array
	{
		if (isset($entry['old']) && is_array($entry['old']))
		{
			$entry['old'] = $this->flattenValues($entry['old']);
		}
		if (isset($entry['new']) && is_array($entry['new']))
		{
			$entry['new'] = $this->flattenValues($entry['new']);
		}
		return $entry;
	}

	/**
	 * Normalises detail values to display strings. Empty values become '' —
	 * the "—" placeholder is presentation and lives in the templates, so an
	 * empty value stays distinguishable from a literal dash in the data.
	 */
	protected function flattenValues(array $values): array
	{
		foreach ($values as $k => $v)
		{
			if ($v === null || $v === '' || $v === [])
			{
				$values[$k] = '';
			}
			elseif (is_array($v))
			{
				$values[$k] = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
			elseif (is_bool($v))
			{
				$values[$k] = $v ? 'true' : 'false';
			}
			else
			{
				$values[$k] = (string)$v;
			}
		}
		return $values;
	}

	public function getLogBasePath(): string
	{
		return \XF::app()->config('internalDataPath') . '/cav7_roster_audit';
	}

	public function getLogFilePath(int $timestamp): string
	{
		return $this->getLogBasePath()
			. '/' . gmdate('Y', $timestamp)
			. '/' . gmdate('m-d', $timestamp) . '.jsonl';
	}

	/**
	 * Resolves the day file the writer appends to, creating its directory if
	 * needed.
	 *
	 * @throws \RuntimeException if the directory cannot be created
	 */
	public function getWritableLogFilePath(int $timestamp): string
	{
		$logFile = $this->getLogFilePath($timestamp);
		$logDir = dirname($logFile);
		if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir))
		{
			throw new \RuntimeException('Could not create audit log directory: ' . $logDir);
		}

		return $logFile;
	}
}
