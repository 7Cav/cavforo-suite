<?php

namespace Cav7\RosterAudit\Entity;

use Cav7\RosterAudit\Repository\AuditLog as AuditLogRepo;

/**
 * Audit hooks for NF/Rosters entities, attached via class extensions so the
 * vendor add-on is never modified. Apply this trait in an entity class
 * extension and implement getAuditContentType().
 *
 * Failure policy: fail-open. A failed audit write must never block the roster
 * operation itself; the failure is logged to the XF server error log instead.
 * The trade-off is that a persistent failure (full disk, bad permissions on
 * internal_data/cav7_roster_audit) leaves changes unaudited until someone
 * reads the error log. If you need fail-closed auditing, rethrow from the
 * outer catch in writeAuditLog().
 */
trait AuditableEntity
{
	abstract protected function getAuditContentType(): string;

	protected function getAuditRelationId(): int
	{
		return 0;
	}

	/**
	 * Columns excluded from update diffs. An update touching only excluded
	 * columns produces no audit entry; creates and deletes are always logged
	 * in full.
	 */
	protected function getAuditExcludedColumns(): array
	{
		return [];
	}

	protected function _postSave(): void
	{
		parent::_postSave();
		$this->writeAuditLog($this->isInsert() ? AuditLog::ACTION_CREATE : AuditLog::ACTION_UPDATE);
	}

	protected function _postDelete(): void
	{
		parent::_postDelete();
		$this->writeAuditLog(AuditLog::ACTION_DELETE);
	}

	protected function writeAuditLog(string $action): void
	{
		try
		{
			$visitor = \XF::visitor();
			$oldValues = [];
			$newValues = [];
			$excluded = $this->getAuditExcludedColumns();

			// Snapshot raw column values only (getValue / toArray(false), never
			// getters): getters can return arbitrary objects — RosterUser's
			// custom_fields getter returns an XF\CustomField\Set — which neither
			// the diff below nor json_encode can handle.
			if ($action === AuditLog::ACTION_UPDATE)
			{
				foreach (array_keys($this->structure()->columns) as $col)
				{
					if (in_array($col, $excluded, true))
					{
						continue;
					}
					if ($this->isChanged($col))
					{
						$oldValues[$col] = $this->getExistingValue($col);
						$newValues[$col] = $this->getValue($col);
					}
				}
				if (empty($oldValues))
				{
					return;
				}
			}
			elseif ($action === AuditLog::ACTION_CREATE)
			{
				$newValues = $this->toArray(false);
			}
			elseif ($action === AuditLog::ACTION_DELETE)
			{
				$oldValues = $this->toArray(false);
			}

			if (isset($oldValues['custom_fields']) || isset($newValues['custom_fields']))
			{
				$this->diffAuditCustomFields($oldValues, $newValues);
			}

			$ip = '';
			try
			{
				$ip = \XF::app()->request()->getIp() ?: '';
			}
			catch (\Throwable $e)
			{
				if (PHP_SAPI !== 'cli')
				{
					// CLI/cron simply has no request to read an IP from, but a
					// failure during a web request means audit entries are losing
					// their forensic IP and someone should know.
					\XF::logException($e, false, 'Audit log IP lookup failed: ');
				}
			}

			// All primary key parts, so a composite-key entity would log an
			// unambiguous id rather than silently keeping only its first column.
			$keyParts = [];
			foreach ((array) $this->structure()->primaryKey as $keyColumn)
			{
				$keyParts[] = (string) $this->getValue($keyColumn);
			}
			// content_id and username are varchar(50); entity validation rejects
			// over-length values, so truncate rather than lose the whole entry.
			$contentId = mb_substr(implode('-', $keyParts), 0, 50);
			// Empty username (guest/system context) stays empty; the templates
			// substitute the localised cav7_raudit_system phrase on display.
			$username = mb_substr($visitor->username, 0, 50);

			/** @var AuditLogRepo $repo */
			$repo = $this->repository('Cav7\RosterAudit:AuditLog');
			$log = $repo->insertLogEntry([
				'relation_id'  => $this->getAuditRelationId(),
				'user_id'      => $visitor->user_id,
				'username'     => $username,
				'content_type' => $this->getAuditContentType(),
				'content_id'   => $contentId,
				'action'       => $action,
				'log_date'     => \XF::$time,
			]);

			// The JSONL file holds the change detail, correlated to the DB row by
			// log_id. If the file write fails we attempt to delete the row we just
			// inserted, so a DB row should normally have a matching detail line.
			// This is best-effort, not a guarantee: a crash between insert and
			// write, a failed compensating delete, or a rollback of the enclosing
			// save transaction (these hooks run inside it) can desynchronise the
			// two stores in either direction. Readers must tolerate missing detail
			// lines — see Repository\AuditLog::getLogDetail().
			// (We insert first only because the file payload needs the log_id.)
			try
			{
				$line = json_encode([
					'log_id'       => $log->log_id,
					'relation_id'  => $this->getAuditRelationId(),
					'user_id'      => $visitor->user_id,
					'username'     => $username,
					'ip'           => $ip,
					'content_type' => $this->getAuditContentType(),
					'content_id'   => $contentId,
					'action'       => $action,
					'old'          => $oldValues ?: null,
					'new'          => $newValues ?: null,
					'log_date'     => \XF::$time,
				], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);

				if ($line === false)
				{
					throw new \RuntimeException('json_encode returned false: ' . json_last_error_msg());
				}
				if (json_last_error() !== JSON_ERROR_NONE)
				{
					// JSON_PARTIAL_OUTPUT_ON_ERROR let us recover a partial line; record
					// the entry but flag that some values could not be encoded.
					\XF::logError(sprintf(
						'Audit log entry %d for %s#%s recorded with partial detail: %s',
						$log->log_id, $this->getAuditContentType(), $contentId, json_last_error_msg()
					));
				}

				$logFile = $repo->getWritableLogFilePath(\XF::$time);
				$payload = $line . "\n";
				$written = file_put_contents($logFile, $payload, FILE_APPEND | LOCK_EX);
				if ($written === false || $written < strlen($payload))
				{
					throw new \RuntimeException('Incomplete audit log file write to ' . $logFile);
				}
			}
			catch (\Throwable $fileError)
			{
				// Compensate: delete the index row so it doesn't point at a missing
				// detail line.
				try
				{
					$log->delete();
				}
				catch (\Throwable $rollbackError)
				{
					// The orphaned row will surface as "detail unavailable" in the
					// admin UI; leave a breadcrumb for whoever investigates it.
					\XF::logException($rollbackError, false, "Audit log cleanup of orphaned row {$log->log_id} failed: ");
				}
				throw $fileError;
			}
		}
		catch (\Throwable $e)
		{
			// Fail-open by design; see the trait docblock.
			\XF::logException($e, false, 'Audit log write failed: ');
		}
	}

	protected function diffAuditCustomFields(array &$oldValues, array &$newValues): void
	{
		$oldFields = $this->decodeAuditCustomFields($oldValues['custom_fields'] ?? null);
		$newFields = $this->decodeAuditCustomFields($newValues['custom_fields'] ?? null);

		if ($oldFields === null || $newFields === null)
		{
			// Undecodable custom_fields payload: keep the raw value in the diff
			// rather than dropping it — an entry that silently omits part of the
			// change is worse than an ugly one — and leave a breadcrumb.
			\XF::logError(sprintf(
				'Audit log: undecodable custom_fields on %s, raw value recorded undiffed',
				$this->getAuditContentType()
			));
			return;
		}

		unset($oldValues['custom_fields'], $newValues['custom_fields']);

		foreach (array_unique(array_merge(array_keys($oldFields), array_keys($newFields))) as $key)
		{
			$old = $oldFields[$key] ?? null;
			$new = $newFields[$key] ?? null;
			if ($old !== $new)
			{
				$oldValues["field:$key"] = $old;
				$newValues["field:$key"] = $new;
			}
		}
	}

	/**
	 * custom_fields is a JSON_ARRAY column, so raw values arrive as decoded
	 * arrays and the string branch is purely defensive. Null means undecodable;
	 * the caller decides what to do with the raw value.
	 */
	protected function decodeAuditCustomFields($raw): ?array
	{
		if (is_array($raw))
		{
			return $raw;
		}
		if ($raw === null || $raw === '')
		{
			return [];
		}
		if (!is_string($raw))
		{
			return null;
		}
		$decoded = json_decode($raw, true);
		return is_array($decoded) ? $decoded : null;
	}
}
