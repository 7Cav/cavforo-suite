# Cav7/RosterAudit

Audit trail for the [NF/Rosters](https://nixfifty.com/products/rosters-and-personnel-status-reports.5/) XenForo add-on, built as a companion add-on. It attaches to the vendor code through XenForo class extensions and ships none of the vendor's code, so NF/Rosters can be updated independently.

## What it does

Every create, update, and delete on the eleven roster entity types (rosters, roster users, awards, award records, service records, ranks, positions, position groups, award groups, record types, custom fields) is recorded in two places:

- An index row in `xf_cav7_roster_audit_log`: who, what type, which record, which action, when.
- A detail line in a JSONL file under `internal_data/cav7_roster_audit/<YYYY>/<MM-DD>.jsonl`: the old and new values, plus the actor's IP. Custom field changes are diffed per field.

Admins read the log at **admin.php?roster-audit/** (filterable by type, action, username, roster member, and date range). The entry appears under the Rosters section of the admin nav and requires the `nfManageRosters` admin permission.

A daily cron prunes both stores. Retention is configurable (`cav7RAuditRetention`, default 1825 days). Values under 30 days are treated as misconfiguration: the cron logs an error and keeps the default instead, so a bad value cannot wipe the history.

## Requirements

- XenForo 2.2+
- PHP 8.0+
- NF/Rosters 2.1.5+ installed (declared as a dependency in `addon.json`)

## Installation

1. Copy `src/addons/Cav7/RosterAudit` into your XenForo installation at the same path.
2. Install the addon: `php cmd.php xf-addon:install Cav7/RosterAudit`.

For release builds, generate hashes first: `php cmd.php xf-addon:build-release Cav7/RosterAudit`.

## What to expect

**A failed audit write does not block the roster operation.** It is logged to the XF error log instead. A persistent failure, such as wrong permissions on `internal_data/cav7_roster_audit`, leaves changes unaudited until someone reads that log, so check it as part of routine maintenance.

**The two stores can disagree.** A crash, or a rollback of the save transaction after the entity hooks have run, can leave an index row without its detail line or a detail line without its row. The admin UI tolerates both and the repository logs each anomaly it encounters.

**Image churn is excluded, not suppressed.** The image services stamp a timestamp column on every upload or removal (`award_image`, `rank_image`, `citation_date`, `uniform_date`). Those columns are excluded from update diffs, so image operations do not flood the log, but creates and deletes of the owning records are always recorded in full.

**A position cannot be deleted while members hold it.** The delete is refused while any member still holds the position, primary or secondary, because the vendor's delete would take those members off the roster along with their awards, service records, field values, and uniform. Reassign them first, and each reassignment is audited as a normal update.

**Uninstall keeps the files.** Uninstalling drops the DB table but leaves the JSONL files in `internal_data/cav7_roster_audit`, since they are audit evidence. Delete them manually if they are no longer needed.

## License

MIT License, see [LICENSE](LICENSE) for details.

Copyright (c) 2026 7Cav

## Provenance

Imported from https://github.com/7Cav/milpac_audit_log at commit 2e50b96a99fa0b42bef3d972a26f8d0b3ebed48c.
