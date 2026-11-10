# Citations runbook

Operator steps for citation storage. Run every command as the user PHP-FPM runs as, or
blob files and deletes hit permission errors. `arc:` below is the rclone remote for the
archive and `$BUCKET` its bucket, both held privately.

## nginx

Add these to the server block. The URL lookup happens in PHP, so nginx needs nothing
else for the two file trees.

```nginx
location ^~ /data/roster_citation_blobs/ {
    add_header X-Robots-Tag "noindex" always;
    add_header Cache-Control "max-age=691200";
}
location ^~ /data/roster_award_citations/ {
    add_header X-Robots-Tag "noindex" always;
}
location ^~ /data/roster_record_citations/ {
    add_header X-Robots-Tag "noindex" always;
}
```

A location with its own `add_header` inherits none from the server block, so repeat any
server-level headers here. The legacy trees get their `max-age=691200` from Cloudflare,
since the origin sends none. After deploying, run `curl -sI` on a blob URL and confirm
it shows `x-robots-tag: noindex` and that same `max-age`.

Renders are `.jpg` URLs under `/citations/` with no file behind them, so they have to fall
through `try_files` to `index.php`. A static-image `location` that catches `.jpg` answers
every render with a 404. Keep `/citations/` out of it, and leave its headers to PHP.

## First run on a board

1. Install `Cav7/RosterAudit` 1.1.3 or later, then this addon.
2. Add the nginx lines.
3. `cav7-citations:sweep --dry-run`. Read the orphan list and the damage. Size the run
   from a fresh row count. Disk stays near today's total, because each legacy file goes
   as its row is re-pointed.
4. `cav7-citations:sweep --lift-brake`. The historical orphans need the flag.
5. Enable the cron entry. An active cron on a board with `jobRunTrigger` set to activity
   would start the first sweep under traffic, which is why it installs inactive.
6. Sync the archive and re-encode, below.
7. After cutover, sync and re-encode once more for what arrived in between.

Neither step waits for the PUC strip or the generator. The strip runs after the sweep.
It takes every reference off the six PUC blobs at once, and that stays under the brake.

**The addon can't be turned off once the sweep has run.** Disabling it sends every
swept citation to a legacy URL with no file behind it. Uninstall keeps the tables, the
`citation_blob_id` columns and the blob tree, and a reinstall picks them back up.

## Syncing the archive

Every re-encode run needs a sync first. The bucket only adds keys, and its README still
describes the retired legacy-tree delta sync and can't be edited, so each sync writes
its own manifest and README objects under new names.

```sh
cav7-citations:sweep --verify-hashes    # a damaged blob synced stays in the bucket for good
date -u +%FT%TZ                         # keep this: it is the run's --synced-at
rclone lsf -R --files-only arc:$BUCKET/roster_citation_blobs | sort > before.txt
rclone copy data/roster_citation_blobs arc:$BUCKET/roster_citation_blobs \
    --immutable --exclude index.html
rclone lsf -R --files-only arc:$BUCKET/roster_citation_blobs | sort > after.txt
comm -13 before.txt after.txt > new-keys.txt
```

Verify the new keys the way the first copy was verified.

1. Compare each local MD5 with the object's ETag. Read the ETags with
   `rclone lsf -R --format "ph"`. `rclone lsjson --hash` sends one request per object and
   takes hours.
2. Read every new key back and compare SHA-256.

   ```sh
   (cd data/roster_citation_blobs && xargs -d '\n' sha256sum < ../../new-keys.txt) > new-keys.sha256
   rclone copy --files-from new-keys.txt arc:$BUCKET/roster_citation_blobs readback/
   (cd readback && sha256sum -c ../new-keys.sha256) | grep -c ': OK$'
   wc -l < new-keys.txt
   ```

   The two numbers must match, and neither may be 0, before you call the sync verified.
3. Upload `new-keys.sha256` and a README saying what this sync covers to
   `_MANIFEST/`, named for the date.

The addon has no R2 access and never gets any. It knows about a sync only through
`--synced-at`, which is your word. The check before a purge covers that word being wrong.

## Re-encoding

The addon installs with **Allow the re-encode** off, under Setup > Options > Citations.
Turn it on before the first run, or the command refuses and exits 2.

```sh
cav7-citations:reencode --synced-at=<the time the sync began>
```

The run prints encoded, kept, damage and rows moved, and exits 1 if there was damage. It
touches source blobs created before `--synced-at` only, so rerunning for stray uploads is
safe. Replaced sources stay on disk until you purge them, so the blob tree holds both
copies until then.

Each encoded blob records its libjpeg version, read from `magick -list format`. With no
`magick` binary on the PATH, or none that names its libjpeg, the run refuses and exits 2.

The re-encode keeps a source as `decode_failed` for good. If a run hit an ImageMagick
resource limit rather than a bad file, read the log and clear those blobs by hand:

```sql
UPDATE xf_cav7_citation_blob SET keep_reason = NULL WHERE blob_id IN (...);
```

## Purging replaced sources

This is the only way a replaced source leaves the box, and the one step in the chain
that can't be undone.

```sh
cav7-citations:purge-sources --dry-run --out=purge.txt
cut -c67- purge.txt > purge-keys.txt
rclone check data arc:$BUCKET --files-from purge-keys.txt --one-way --download
cav7-citations:purge-sources --list=purge.txt
```

Each line of the list reads like `sha256sum` output, hash then two spaces then archive
key, so `sha256sum -c` from inside `data/` also works. The purge deletes only what is on
the list, and skips anything that has gained a reference since. No brake applies.

## Reverting

```sh
cav7-citations:revert plan --run=3
grep -E '^[0-9a-f]{64}  ' plan.txt | cut -c67- > fetch-keys.txt
rclone copy --files-from fetch-keys.txt arc:$BUCKET fetched/
cav7-citations:revert apply --run=3 --from=fetched
```

Save the plan's output as `plan.txt`. A plan that lists nothing needs no fetch and no
`--from`. The fetched directory keeps each archive key as its relative path, and PHP-FPM's
user has to be able to read it. Never fetch from the 2026-08-07 legacy mirror, because
its keys are legacy paths.

- A row that moved on since the run is left alone, and so is a row on the encoded blob
  with no log entry.
- A missing or mismatched source is damage, and that row stays encoded. If its blob row
  still exists, the plan won't list it. Fetch the key and put it back at its path, as for
  `blob file missing`.
- A source that comes back from the archive gets a new blob id and URL.
- The revert deletes nothing. The next sweep deletes the zero-reference encoded blobs. If
  that trips the brake, run a dry run and then `--lift-brake`.
- `--all` turns the re-encode off, and turning it back on is a new decision with
  leadership. A reinstall leaves it off too. `--run`, `--blob` and `--row` leave it on.

## Retypesetting

```sh
cav7-citations:retypeset --template-version=7 --dry-run
cav7-citations:retypeset --template-version=7 --reason="ImageMagick upgrade"
```

Choose grants with `--grant` (repeatable), `--template-version` or `--addon-version`. The
dry run lists what would change and writes nothing. A real run needs `--reason`. Each grant
whose typesetting changes logs it and moves its revision, so its URL changes and the old one
redirects. A grant with no change is left as it was. It exits 0 when clean, 1 when a grant failed and 2 when the command was refused.

## Reading damage

The sweep logs up to 100 damage entries a run to the server error log, then one line with
the total. The CLI prints all of it, each line carrying the kind, path, row and hash in
lower-case hex. Code never repairs damage.

| Line | What to do |
|---|---|
| `legacy file on a row with no citation` | Decide whether the row should have it. Re-upload it, or delete the file. |
| `no known image signature` | The row keeps serving the legacy file. Fetch the real one from the archive and re-upload it. |
| `row has a citation but no blob, grant link or legacy file` | Re-upload, or restore the file. |
| `reference to a blob with no row` | Restore the database, or re-upload. |
| `reference on a row with no citation` | A restore put the two out of step. Decide which is right and fix the row by hand. |
| `legacy file kept aside` | A take-in moved the file to `<path>.taken-<hex>` and couldn't finish. Either a newer upload has since taken its path or been taken in, and the row serves that, or putting it back failed, or the blob file the row points at is missing or damaged. If `<path>` is empty and the row has no blob reference, the kept one is the row's citation: rename it back to `<path>` and the next sweep takes it in. In the second case the kept one may be the only good copy. A blob line names the bad file, though a change that keeps its size shows only under `--verify-hashes`. If that line's hash matches the kept one's, copy the kept one to the blob path. Otherwise check the kept one, then delete it by hand. Until then each run reports it again. A sweep resumes any other take-in it finds cut short. |
| `legacy file could not be moved` | Take-in couldn't move the file aside, so it left it in place and skipped the row. Check the directory's ownership and permissions for the PHP user. The row keeps serving the file. |
| `unexpected entry` | Something other than the vendor wrote there. Look at it and remove it by hand. |
| `blob file missing`, `blob file size differs`, `blob file hash mismatch` | Fetch the key from the archive and put it back at that path. The path is the archive key. |
| `blob tree file with no blob row` | Never deleted automatically, because it may be the only copy. Check it against the archive, then remove it by hand. |
| `decode failed` | The source stays and keeps serving. Repair it and re-upload. |
| `render failed` | A render answered 500. The route logs this itself, one line per request, and `row=` names the grant member or grant. Nothing caches the failure, so a fix works at once. The usual cause is a file gone from `internal_data/cav7_citation_files/`, covered under [restores](#restores). |

## Backups

The archive holds citation blobs and nothing else, so the board's own backups have to carry
the rest. Keep these two in them.

- The database. Grants, grant links, typesettings and templates all live there, and so does
  the only map from a row to its blob.
- `internal_data/cav7_citation_files/`. It holds plates, signature ink, rank art and the
  fonts a published version pins. The archive doesn't cover it, and nothing can re-derive
  it.

Dump the database first and copy the directory after. Each file is named by its SHA-256 and
nothing deletes one, so a later copy of the directory holds everything an earlier dump
names. The other order can leave a version pinning a file the backup doesn't have.

## Restores

The blob table and the sweep turn a mismatch between disk and database into reported
damage. Run a dry run before anything else after a restore.

**`internal_data/cav7_citation_files/` lost or damaged.** Restore it from the board's
backup and keep the paths exactly as they were. The sweep never looks in this directory, so
a missing file reports nothing until something draws. Then its render answers 500 and logs
`render failed`, and an issue or correction needing that file fails and writes nothing.
After restoring, check that each file still matches its name:

```sh
cd internal_data/cav7_citation_files
find . -type f ! -name index.html | while read -r f; do
    [ "$(sha256sum "$f" | cut -c1-64)" = "$(basename "${f%.*}")" ] || echo "bad: $f"
done
```

A directory newer than the database is harmless. The extra files sit unused.

**Filesystem restored, legacy files back.** A legacy file beats an existing reference, so
each row serves its restored file at once. The next sweep takes each one in as a source
blob, byte for byte, and re-points its row. The encoded blobs lose those references. No revert is needed, and the sweep
keeps running. A restored file for a row with a grant link is an orphan, and the sweep
deletes it.

**Undoing a re-encode.** Use `revert`, not a restore. The sweep keeps running throughout.

**Database restored to before a re-encode run.** Rows point back at source blobs that may
have left the box, and the sweep reports each as `blob file missing`. The path in that line
is the archive key. Fetch it, copy it to that path under `data/`, and the next sweep passes.
The blob row's id and token derive the key, so nothing else needs to be known.

**Database restored to an older point.** Files of newer rows turn into orphans, and the
sweep deletes them unless the count trips the brake. One day of arrivals trips it. Read
the dry run before lifting anything. Blob files with no row are damage and stay. Grants
and their links go back together, so a grant issued after the restore point is gone with
every link to it, and its render URL 404s. A revision can also go backward, and any URL
ahead of the restored one redirects to it.

**Database restored to before the PUC strip.** The old PUC rows come back with the PUC
sync off. Rerun the strip.
