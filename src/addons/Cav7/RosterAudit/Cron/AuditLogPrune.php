<?php

namespace Cav7\RosterAudit\Cron;

use Cav7\RosterAudit\Repository\AuditLog;

class AuditLogPrune
{
	const DEFAULT_RETENTION_DAYS = 1825;
	const MIN_RETENTION_DAYS = 30;

	public static function run(): void
	{
		$days = (int) (\XF::options()->cav7RAuditRetention ?? self::DEFAULT_RETENTION_DAYS);

		// Guard against a misconfigured (0 / negative / implausibly low) value that
		// would silently and irreversibly wipe the audit history. The substitution
		// must be loud: an admin who set a tight retention needs to know data is
		// actually being kept for the 5-year default instead.
		if ($days < self::MIN_RETENTION_DAYS)
		{
			\XF::logError(sprintf(
				'cav7RAuditRetention=%d is below the %d-day minimum; using the %d-day default instead',
				$days, self::MIN_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS
			));
			$days = self::DEFAULT_RETENTION_DAYS;
		}

		// Align the cutoff to the start of its UTC day. Files are pruned at whole-day
		// granularity, so the DB rows must use the same boundary or the two stores
		// diverge for the cutoff day.
		$rawCutoff = \XF::$time - (86400 * $days);
		$cutoff = (int) strtotime(gmdate('Y-m-d', $rawCutoff) . ' 00:00:00 UTC');

		/** @var AuditLog $repo */
		$repo = \XF::repository('Cav7\RosterAudit:AuditLog');

		// Rows first, then files: a failure between the two leaves orphaned files
		// (caught next run) rather than rows pointing at deleted files.
		$repo->pruneAuditLogs($cutoff);
		$repo->pruneAuditLogFiles($cutoff);
	}
}
