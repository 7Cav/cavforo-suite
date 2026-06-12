<?php

namespace Cav7\RosterAudit\Repository;

use XF\Mvc\Entity\Finder;
use XF\Mvc\Entity\Repository;

class AuditLog extends Repository
{
	/**
	 * Single source of truth for audited content types. Each entry must match
	 * a getAuditContentType() in the entity extensions, with a matching
	 * cav7_raudit_type_<type> phrase.
	 */
	const CONTENT_TYPES = [
		'roster', 'roster_user', 'user_award', 'service_record',
		'award', 'award_group', 'position', 'position_group',
		'rank', 'record_type', 'field',
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
				if (!$entry)
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
					if (!$entry)
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

			$yearDirs = glob($basePath . '/*', GLOB_ONLYDIR) ?: [];
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
					foreach (glob($yearDir . '/*.jsonl') ?: [] as $file)
					{
						$this->deleteLogFile($file);
					}
					$this->removeYearDirIfEmpty($yearDir);
					continue;
				}

				if ($year === $cutoffYear)
				{
					foreach (glob($yearDir . '/*.jsonl') ?: [] as $file)
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
		$remaining = glob($yearDir . '/*');
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

	protected function flattenValues(array $values): array
	{
		foreach ($values as $k => $v)
		{
			if ($v === null || $v === '')
			{
				$values[$k] = '—';
			}
			elseif (is_array($v))
			{
				$values[$k] = $v === [] ? '—' : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

	protected function getLogBasePath(): string
	{
		return \XF::app()->config('internalDataPath') . '/cav7_roster_audit';
	}

	protected function getLogFilePath(int $timestamp): string
	{
		return $this->getLogBasePath()
			. '/' . gmdate('Y', $timestamp)
			. '/' . gmdate('m-d', $timestamp) . '.jsonl';
	}
}
