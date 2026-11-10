# 7Cav - Citations

Stores the citation images on [NF/Rosters](https://nixfifty.com/products/rosters-and-personnel-status-reports.5/)
award rows and service records as citation blobs, one file per distinct image, in place
of the vendor's one file per row. Uploads keep working as they always have. A sweep
then takes each new file in, and the roster links to the blob. An operator can also
re-encode the corpus to JPEG, revert that, and delete the originals once the archive
holds them.

The addon also generates citations. A grant holds one citation's text, date and
signatures, and a template sets it on a plate. Attach a grant to a roster row and the
row's Citation link goes to a render, a JPEG drawn from the stored typesetting on each
request. S1 issues and corrects grants at `rosters/citations`, and a row form's citation
picker attaches them. S1 HQ keeps templates at `rosters/citation-templates`, with the pay
grade list and legibility list beside them, and signatures at `rosters/citation-signatures`.

[GLOSSARY.md](GLOSSARY.md) and the suite-wide [GLOSSARY.md](../../../../GLOSSARY.md)
define the terms. [docs/adr](docs/adr/) has the reasons behind the generator.

## Requirements

- XenForo 2.3.0+, NF/Rosters 2.1.5+ and PHP 8.1+. The NF/Rosters floor is in the
  vendor's own `version_id` numbering.
- Imagick with JPEG, PNG and FreeType support. The re-encode, every plate, ink and rank
  art upload, and every draw need it, so XenForo refuses the install without it.
- GD on the same PHP. Imagick can't report its FreeType version, so a typesetting reads
  it from GD and records `unknown` without it.
- `Cav7/RosterAudit` 1.1.3+ if that addon is installed. An older version logs a line
  every time an upload clears a citation reference. It logs every change to a grant link
  with no help from this addon.
- Local `data` and `internal_data` directories. The sweep reads files directly and
  Imagick needs real paths, so a board that overrides `fsAdapters['data']` or
  `fsAdapters['internal-data']` can't run this addon.

## Installation

1. Install the release zip through the admin panel, or copy `src/addons/Cav7/Citations`
   to the same path and run `php cmd.php xf-addon:install Cav7/Citations`.
2. Add the nginx lines from the [runbook](docs/runbook.md#nginx).

The install creates the `xf_cav7_citation_*` tables, adds `citation_blob_id`,
`citation_grant_member_id` and `citation_regiment_grant_id` to the two vendor row tables,
and fills the pay grade list from the NF/Rosters rank titles it recognises. Nothing changes
for members until the first sweep.

**Once the sweep has run, the addon can't be turned off.** The vendor's own links point
at legacy files the sweep has deleted, so every swept citation 404s. Uninstalling keeps
the tables, the columns on the vendor tables, the blob tree and
`internal_data/cav7_citation_files/`, and reinstalling picks them back up.

`internal_data/cav7_citation_files/` holds plates, signature ink, rank art and the fonts
a published template version pins. The archive doesn't cover it and nothing can re-derive
it, so keep it in the board's backups. The [runbook](docs/runbook.md#backups) says how.

## Using it

Everything runs from `cmd.php`, as the same user PHP-FPM runs as. The
[runbook](docs/runbook.md) has the flags and the order to run things in on a live board.

| Command | What it does |
|---|---|
| `cav7-citations:sweep` | Takes legacy files in, reports damage, deletes orphans and zero-reference blobs. |
| `cav7-citations:reencode` | Replaces eligible source blobs with encoded blobs, after an archive sync. |
| `cav7-citations:revert` | Puts source blobs back, with `plan` and then `apply`. |
| `cav7-citations:purge-sources` | Deletes replaced sources from a list checked against the archive. |
| `cav7-citations:retypeset` | Remakes the typesetting of chosen grants with the current renderer. A grant whose typesetting changes moves its revision and logs it, and one with no change keeps its typesetting, stamps and revision and logs only that the check ran. |

The cron entry **Citations: sweep** runs daily at 03:10 and installs inactive. Enable
it under **Tools > Cron entries** after the first CLI sweep.

A citation's URL changes when its image moves into a blob, is re-encoded or is
reverted. The roster always links the current one. A legacy URL pasted somewhere else
404s after the sweep.

Citation images stay public by URL, disciplinary documents included. The addon checks
no permission on a read.

### Options

These are under **Setup > Options > Citations**.

| Option | Default | What it does |
|---|---|---|
| **Allow the re-encode** | Off | With it off the re-encode refuses to run. Turn it on before the first run. `revert apply --all` turns it off. |
| **Regiment awards** | `61` | Comma-separated award ids. A grant of one of these awards is a regiment grant, issued to the whole regiment. Changing the list leaves issued grants alone. |
| **Citation cutover** | Off | With it on, the row forms show the citation picker in place of the upload box, and every row but a Disciplinary record refuses a citation file. Renders and attached citations never read it. |

A row with a grant link refuses a file whatever the cutover says. To put an upload there,
tick "Delete current image" and save, then upload in a second save.

### Permissions

The vendor's own award and record permissions cover the rows, so attaching and removing a
citation need nothing from this list. All six flags start at no, and they split into two
interface groups.

| Permission | Group | Lets a member |
|---|---|---|
| View grants and templates | Citation permissions | Open the grants and the template manager, read-only. |
| Issue and correct grants to members | Citation moderator permissions | Issue, correct, move and add members to member grants. |
| Remove members from grants | Citation moderator permissions | Remove a member from a grant, which clears every row linked to them. |
| Manage regiment grants | Citation moderator permissions | Issue, correct, move and withdraw regiment grants. |
| Edit template drafts | Citation moderator permissions | Create templates and versions, and edit their uploads and fields. |
| Publish templates and manage signatures and pay grades | Citation moderator permissions | Publish and retire versions, and manage signatures, billets and pay grades. |

### Renders

A render's URL is `citations/{grant_member_id}/{token}/{revision}/{filename}.jpg`, or
`citations/regiment/{grant_id}/{token}/{revision}/{filename}.jpg` for a regiment grant.
Anyone with the URL can read it, banned visitors and a closed board included.

No render is stored. Cloudflare holds each one for 8 days, and that cache is what makes a
draw on every request affordable. A correction, or a retypeset that changes a typesetting,
moves the revision, and the old URL redirects to the new one. Removing a member or
withdrawing a regiment grant 404s at the origin at once, but Cloudflare can serve its copy
until it expires, and nothing here purges it.

## License

MIT License, see [LICENSE](LICENSE) for details. The fonts in `fonts/` are under the SIL
Open Font License, with each family's licence beside it.

Copyright (c) 2026 7Cav
