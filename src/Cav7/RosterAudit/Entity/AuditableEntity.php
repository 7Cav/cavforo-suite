<?php

namespace Cav7\RosterAudit\Entity;

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
	/**
	 * One-shot programmatic suppression: set immediately before a save() that
	 * should not produce an audit entry. Consumed by the next audit attempt.
	 * Caveat: a save that fails before _postSave() leaves the flag set, so it
	 * would suppress the next successful save of this instance.
	 */
	public bool $skipAudit = false;

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
		// Reset the one-shot suppression flag before any early return so a
		// suppressed save cannot leak its suppression into a later save.
		$skip = $this->skipAudit;
		$this->skipAudit = false;
		if ($skip)
		{
			return;
		}

		try
		{
			$visitor = \XF::visitor();
			$oldValues = [];
			$newValues = [];
			$excluded = $this->getAuditExcludedColumns();

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
						$newValues[$col] = $this->get($col);
					}
				}
				if (empty($oldValues))
				{
					return;
				}
			}
			elseif ($action === AuditLog::ACTION_CREATE)
			{
				$newValues = $this->toArray();
			}
			elseif ($action === AuditLog::ACTION_DELETE)
			{
				$oldValues = $this->toArray();
			}

			if (isset($oldValues['custom_fields']) || isset($newValues['custom_fields']))
			{
				$this->diffAuditCustomFields($oldValues, $newValues);
			}

			$ip = '';
			try
			{
				$ip = \XF::app()->request()->getIp();
			}
			catch (\Throwable $e)
			{
				// No request context (CLI / cron / job): record the entry without an IP.
			}

			$primaryKey = $this->structure()->primaryKey;
			$contentId = is_array($primaryKey)
				? $this->get($primaryKey[0])
				: $this->get($primaryKey);
			// The insert below bypasses entity validation, so normalise to the
			// varchar(50) columns here rather than risk a rejected insert.
			$contentId = mb_substr((string) $contentId, 0, 50);
			$username = mb_substr($visitor->username ?: 'System', 0, 50);

			$db = $this->db();
			$db->insert('xf_cav7_roster_audit_log', [
				'relation_id'  => $this->getAuditRelationId(),
				'user_id'      => $visitor->user_id,
				'username'     => $username,
				'content_type' => $this->getAuditContentType(),
				'content_id'   => $contentId,
				'action'       => $action,
				'log_date'     => \XF::$time,
			]);
			$logId = $db->lastInsertId();

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
					'log_id'       => $logId,
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
						$logId, $this->getAuditContentType(), $contentId, json_last_error_msg()
					));
				}

				$internalDataPath = \XF::app()->config('internalDataPath');
				$logDir = $internalDataPath . '/cav7_roster_audit/' . gmdate('Y', \XF::$time);
				if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir))
				{
					throw new \RuntimeException('Could not create audit log directory: ' . $logDir);
				}

				$logFile = $logDir . '/' . gmdate('m-d', \XF::$time) . '.jsonl';
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
					$db->delete('xf_cav7_roster_audit_log', 'log_id = ?', $logId);
				}
				catch (\Throwable $rollbackError)
				{
					// The orphaned row will surface as "detail unavailable" in the
					// admin UI; leave a breadcrumb for whoever investigates it.
					\XF::logException($rollbackError, false, "Audit log cleanup of orphaned row {$logId} failed: ");
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
		$oldRaw = $oldValues['custom_fields'] ?? null;
		$newRaw = $newValues['custom_fields'] ?? null;

		$oldFields = is_array($oldRaw) ? $oldRaw : (json_decode($oldRaw ?? '{}', true) ?: []);
		$newFields = is_array($newRaw) ? $newRaw : (json_decode($newRaw ?? '{}', true) ?: []);

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
}
