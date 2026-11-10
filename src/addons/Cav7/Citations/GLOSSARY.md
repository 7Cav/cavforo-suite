# Cav7/Citations

Stores the roster's citation images as citation blobs and moves them out of the
vendor's per-row files. For **citation**, **citation image**, **citation blob**,
**citation reference** and **citation upload**, see the suite-wide
[GLOSSARY.md](../../../../GLOSSARY.md).

## Language

### The trees

**legacy tree**:
The per-record directories the vendor writes citation files into, one file per row,
named by record id.
_Avoid_: legacy path for the tree, which names one file's location

**legacy file**:
One file in the legacy tree. It holds a citation image waiting on the next sweep.
_Avoid_: unmigrated file, since nothing here ever stops arriving

**blob tree**:
The directory tree that holds citation blobs, one file per blob.

**content hash**:
The SHA-256 of a citation blob's bytes as stored. It decides whether two images are
the same blob and plays no part in where a blob lives.
_Avoid_: blob address

### The passes

**sweep**:
The automatic, lossless pass that moves legacy files into citation blobs and keeps
their reference counts.
_Avoid_: migration, which suggests an end, and re-encode, which is lossy.
`Cav7/DiscordSyncPatch` has a sweep of its own, so this word stays out of the
suite-wide glossary

**re-encode**:
The lossy pass an operator runs, turning a source blob into an encoded blob.
_Avoid_: sweep, migration

**revert**:
Putting back the source blob for each row a re-encode run moved onto an encoded blob.
_Avoid_: restore, which means restoring the filesystem or database from backup, and
rollback, which reads as a database transaction

**brake**:
The limit on what one automatic sweep may delete of one kind. Over the limit it
deletes none of that kind, until an operator lifts the brake for a run.

### What the passes find

**source blob**:
A citation blob holding the bytes as uploaded, including one the re-encode looked at
and kept.

**encoded blob**:
A citation blob the re-encode produced from a source blob.

**replaced source**:
A source blob with no references left because a re-encode moved its rows onto an
encoded blob. Only an operator deletes one, after checking the archive holds it.

**zero-reference blob**:
A citation blob no row points at. The sweep deletes it unless it is a replaced source.

**orphan**:
A citation file in the legacy tree that no row owns, decided by a row lookup. A file
whose row is linked to a grant is one too, since that row owns no image. The
legacy-tree counterpart of a zero-reference blob.
_Avoid_: orphan blob

**damage**:
A citation file or blob the sweep or re-encode can't process as it stands. It is
logged and left where it is, and code never repairs it. A person does, from the
archive or by re-upload.
_Avoid_: corruption, which suggests the bytes changed after they were written

**archive**:
The retention-locked R2 bucket, holding the 2026-08-07 mirror plus every blob-tree
sync.
_Avoid_: Drive archive. The Drive is S1's and is never a copy of the roster

**archive key**:
A blob's or legacy file's path under `data/`, which is also its object key in the
archive.
