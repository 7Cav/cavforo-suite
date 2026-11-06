# 7Cav - Citations

Stores the citation images on [NF/Rosters](https://nixfifty.com/products/rosters-and-personnel-status-reports.5/)
award rows and service records as citation blobs, one file per distinct image, in place
of the vendor's one file per row. Uploads keep working as they always have. A sweep
then takes each new file in, and the roster links to the blob. An operator can also
re-encode the corpus to JPEG, revert that, and delete the originals once the archive
holds them.

The terms this README uses are defined in [GLOSSARY.md](GLOSSARY.md) and the suite-wide
[GLOSSARY.md](../../../../GLOSSARY.md).

## Requirements

- XenForo 2.3.0+
- NF/Rosters 2.1.5+ (declared in `addon.json`; the floor is the version the code was
  read against, in the vendor's own `version_id` numbering)
- PHP 8.1+
- Imagick with JPEG support, for the re-encode only. The addon installs without it,
  and `cav7-citations:reencode` refuses to run.
- `Cav7/RosterAudit` 1.1.3+ if that addon is installed. An older version logs a line
  every time an upload clears a citation reference.
- A local data directory. The sweep reads files directly, so a board that overrides
  `fsAdapters['data']` can't run it.

## Installation

1. Install the release zip through the admin panel, or copy `src/addons/Cav7/Citations`
   to the same path and run `php cmd.php xf-addon:install Cav7/Citations`.
2. Add the nginx lines from the [runbook](docs/runbook.md#nginx).

The install creates four `xf_cav7_citation_*` tables and adds `citation_blob_id` to
the two vendor row tables. Nothing changes for members until the first sweep.

**Once the sweep has run, the addon can't be turned off.** With it disabled or
uninstalled, the vendor's own links point at legacy files the sweep has deleted, so
every swept citation 404s. Uninstalling keeps the tables, the column and the blob tree,
and reinstalling picks them back up.

## Using it

Everything runs from `cmd.php`, as the same user PHP-FPM runs as. The
[runbook](docs/runbook.md) has the order to run things in on a live board.

| Command | What it does |
|---|---|
| `cav7-citations:sweep` | Takes legacy files in, reports damage, deletes orphans and zero-reference blobs. Takes `--dry-run`, `--lift-brake` and `--verify-hashes`. |
| `cav7-citations:reencode --synced-at=<ISO 8601>` | Replaces eligible source blobs with encoded blobs, after an archive sync. |
| `cav7-citations:revert plan\|apply <scope>` | Puts source blobs back. The scope is `--run=N`, `--all`, `--blob=ID` or `--row=award:ID`. `apply` takes `--from=<dir>`. |
| `cav7-citations:purge-sources` | Deletes replaced sources from a list checked against the archive. `--dry-run --out=<file>` writes the list and `--list=<file>` acts on it. |

The cron entry **Citations: sweep** runs daily at 03:10 and installs inactive. Enable
it under **Tools > Cron entries** after the first CLI sweep.

One option, under **Setup > Options > Citations**: **Allow the re-encode**, on by
default. With it off the re-encode refuses to run. `revert apply --all` turns it off.

A citation's URL changes when its image moves into a blob, is re-encoded or is
reverted. The roster always links the current one. A legacy URL pasted somewhere else
404s after the sweep.

Citation images stay public by URL, disciplinary documents included. The addon checks
no permission on a read.

## License

MIT License, see [LICENSE](LICENSE) for details.

Copyright (c) 2026 7Cav
