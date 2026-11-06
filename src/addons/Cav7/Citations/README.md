# 7Cav - Citations

Stores the citation images on [NF/Rosters](https://nixfifty.com/products/rosters-and-personnel-status-reports.5/)
award rows and service records as citation blobs, one file per distinct image, in place
of the vendor's one file per row. Uploads keep working as they always have. A sweep
then takes each new file in, and the roster links to the blob. An operator can also
re-encode the corpus to JPEG, revert that, and delete the originals once the archive
holds them.

The addon also holds the generator's templates, signatures and pay grade list, and the
renderer that sets a citation on a plate. Nothing a member sees reads them.

[GLOSSARY.md](GLOSSARY.md) and the suite-wide [GLOSSARY.md](../../../../GLOSSARY.md)
define the terms. [docs/adr](docs/adr/) has the reasons behind the generator.

## Requirements

- XenForo 2.3.0+, NF/Rosters 2.1.5+ and PHP 8.1+. The NF/Rosters floor is in the
  vendor's own `version_id` numbering.
- Imagick with JPEG, PNG and FreeType support. The re-encode, every plate, ink and rank
  art upload, and every draw need it. The addon installs without it, and
  `cav7-citations:reencode` refuses to run.
- GD on the same PHP. Imagick can't report its FreeType version, so a typesetting reads
  it from GD and records `unknown` without it.
- `Cav7/RosterAudit` 1.1.3+ if that addon is installed. An older version logs a line
  every time an upload clears a citation reference.
- Local `data` and `internal_data` directories. The sweep reads files directly and
  Imagick needs real paths, so a board that overrides `fsAdapters['data']` or
  `fsAdapters['internal-data']` can't run this addon.

## Installation

1. Install the release zip through the admin panel, or copy `src/addons/Cav7/Citations`
   to the same path and run `php cmd.php xf-addon:install Cav7/Citations`.
2. Add the nginx lines from the [runbook](docs/runbook.md#nginx).

The install creates the `xf_cav7_citation_*` tables, adds `citation_blob_id` to the two
vendor row tables, and fills the pay grade list from the NF/Rosters rank titles it
recognises. Nothing changes for members until the first sweep.

**Once the sweep has run, the addon can't be turned off.** The vendor's own links point
at legacy files the sweep has deleted, so every swept citation 404s. Uninstalling keeps
the tables, `citation_blob_id`, the blob tree and `internal_data/cav7_citation_files/`,
and reinstalling picks them back up.

`internal_data/cav7_citation_files/` holds plates, signature ink, rank art and the fonts
a published template version pins. The archive doesn't cover it and nothing can re-derive
it, so keep it in the board's backups.

## Using it

Everything runs from `cmd.php`, as the same user PHP-FPM runs as. The
[runbook](docs/runbook.md) has the flags and the order to run things in on a live board.

| Command | What it does |
|---|---|
| `cav7-citations:sweep` | Takes legacy files in, reports damage, deletes orphans and zero-reference blobs. |
| `cav7-citations:reencode` | Replaces eligible source blobs with encoded blobs, after an archive sync. |
| `cav7-citations:revert` | Puts source blobs back, with `plan` and then `apply`. |
| `cav7-citations:purge-sources` | Deletes replaced sources from a list checked against the archive. |

The cron entry **Citations: sweep** runs daily at 03:10 and installs inactive. Enable
it under **Tools > Cron entries** after the first CLI sweep.

**Allow the re-encode**, under **Setup > Options > Citations**, is the one option and is
on by default. With it off the re-encode refuses to run. `revert apply --all` turns it
off.

A citation's URL changes when its image moves into a blob, is re-encoded or is
reverted. The roster always links the current one. A legacy URL pasted somewhere else
404s after the sweep.

Citation images stay public by URL, disciplinary documents included. The addon checks
no permission on a read.

## License

MIT License, see [LICENSE](LICENSE) for details. The fonts in `fonts/` are under the SIL
Open Font License, with each family's licence beside it.

Copyright (c) 2026 7Cav
