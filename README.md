# Cav7/RosterAudit

Audit trail for the [NF/Rosters](https://nixfifty.com/products/rosters-and-personnel-status-reports.5/) XenForo add-on, built as a companion add-on. It attaches to the vendor entities through XenForo class extensions and ships none of the vendor's code, so NF/Rosters can be updated independently.

## What it does

Every create, update, and delete on the eleven roster entity types (rosters, roster users, awards, award records, service records, ranks, positions, position groups, award groups, record types, custom fields) is recorded in two places:

- An index row in `xf_cav7_roster_audit_log`: who, what type, which record, which action, when.
- A detail line in a JSONL file under `internal_data/cav7_roster_audit/<YYYY>/<MM-DD>.jsonl`: the old and new values, plus the actor's IP. Custom field changes are diffed per field.

Admins read the log at **admin.php?roster-audit/** (filterable by type, action, username, roster member, and date range). The entry appears under the Rosters section of the admin nav and requires the `nfManageRosters` admin permission.

A daily cron prunes both stores. Retention is configurable (`cav7RAuditRetention`, default 1825 days) with a hard 30 day floor so a misconfigured value cannot wipe the history.

## Requirements

- XenForo 2.2+
- PHP 8.0+
- NF/Rosters 2.1.x installed (declared as a dependency in `addon.json`)

## Installation

```
cp -R src/Cav7 <forum-root>/src/addons/
php <forum-root>/cmd.php xf-addon:install Cav7/RosterAudit
```

For release builds, generate hashes first: `php cmd.php xf-addon:build-release Cav7/RosterAudit`.

## Design notes

**Fail-open by design.** A failed audit write is logged to the XF error log and the roster operation proceeds. The alternative (blocking roster operations when the audit store is down) was rejected. The cost: a persistent failure, such as wrong permissions on `internal_data/cav7_roster_audit`, leaves changes unaudited until someone reads the error log. Check the error log as part of routine maintenance.

**Two stores, best-effort consistency.** The DB row is written first (the file payload needs the `log_id`), then the JSONL line. If the file write fails, the row is deleted. This runs inside the entity's save transaction, so a rollback after the hooks can still leave a file line without a row, and a crash can leave a row without a file line. The admin UI tolerates both and the repository logs each anomaly it encounters.

**Image churn is excluded, not suppressed.** The image services stamp a timestamp column on every upload or removal (`award_image`, `rank_image`, `citation_date`, `uniform_date`). Those columns are excluded from update diffs, so image operations do not flood the log, but creates and deletes of the owning records are always recorded in full.

**Uninstall keeps the files.** Uninstalling drops the DB table but leaves the JSONL files in `internal_data/cav7_roster_audit`, since they are audit evidence. Delete them manually if they are no longer needed.

## Layout

```
src/Cav7/RosterAudit/
  Entity/AuditLog.php           index-row entity
  Entity/AuditableEntity.php    the audit engine (trait used by the extensions)
  Repository/AuditLog.php       reads, batched pruning, file handling
  Admin/Controller/AuditLog.php list + detail views
  Cron/AuditLogPrune.php        daily retention prune
  NF/Rosters/Entity/*.php       11 entity class extensions (audit hooks)
  _data/*.xml                   routes, phrases, templates, options, cron, extensions
```
