# Cav7/Citations

Stores the roster's citation images as citation blobs and moves them out of the
vendor's per-row files, and holds the generator that replaces S1's GIMP export and
the upload. For **milpac**, **citation**, **citation image**, **citation blob**,
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

### The people and the paper

**S1 Citations clerk**:
A member of S1's Citations sub-department, who proofreads recommendations and issues
every grant, generic or written. Never creates roster rows.
_Avoid_: a bare clerk

**S1 Milpacs clerk**:
A member of S1's MILPACS sub-department, who creates the roster rows a post calls for
and attaches their citations. Never enters citation text or issues grants.
_Avoid_: a bare clerk

**recommendation**:
The request a member files for an award, with its proposed prose, that S1 Citations
works from. Nothing has been granted yet.
_Avoid_: citation, the 7Cav Medal App's word for it

**Drive**:
The Citations Department's shared Google Drive, where an S1 Citations clerk files each
exported citation before an S1 Milpacs clerk uploads it. It sits upstream of the roster.
_Avoid_: Drive archive, and never call it the archive

**disciplinary document**:
The filed form a disciplinary row's citation image shows: a letter of reprimand, a
negative counseling statement, an Article 15 hearing or an Article 32 court-martial
hearing. It isn't a citation, because nothing is granted.
_Avoid_: disciplinary citation

**cutover**:
The single moment the generator goes live. S1 Citations stops producing images, and
citation upload closes on every row kind but Disciplinary.
_Avoid_: cutover per award, since there is one moment

### What a citation says

**generic citation**:
A citation whose text is the same for every recipient issued under its template
version, such as EIB. Its text belongs to the template version, so its row carries none.
_Avoid_: fixed citation

**written citation**:
A citation whose text was written for its recipient. The counterpart of a generic
citation.

**citation text**:
The prose paragraph on a written citation, as an S1 Citations clerk entered it after
proofreading. Plain text that belongs to the grant, and never holds the name, rank or
dates, which are separate fields.

### Templates

**template**:
A certificate design an S1 Citations clerk picks when issuing a grant. It sets the
plate, the fields the form asks for, and where and how each field is set.
_Avoid_: template for S1's GIMP files, which are design files. The suite-wide
**template copy** means a XenForo template, so this word stays out of the suite-wide
glossary

**template version**:
One frozen state of a template, frozen when it is published. Each generated citation
pins one.

**template manager**:
The front-end pages where S1 keeps templates and their versions, signatures, signing
billets and the pay grade list.
_Avoid_: template editor, and citation editor, which reads as the grant form

**plate**:
The artwork image a template is set on, fixed text included and signature left out.

**design file**:
An S1 `.xcf` file a plate is exported from. Never read at render time.
_Avoid_: template, S1's word for it today

**field**:
A box on a template version where the generator sets something: a text field, a
signature slot or rank art. A text field holds literal text and values the generator
knows, such as name, rank, pay grade and the date, plus the template's own inputs.

**template input**:
A plain-text value a template asks the S1 Citations clerk for, scoped to the grant or
to each member, such as a class number or a unit line.
_Avoid_: custom field, which XenForo and NF/Rosters use for profile fields

**rank art**:
A field holding one image per rank, frozen with its template version and picked by the
rank snapshot, such as promotion insignia.
_Avoid_: rank image, the roster's rank icon

**retired**:
Of a template version or a signature, no longer offered on new grants and still rendered
wherever it is pinned.

### Signatures

**signatory**:
The officer whose signature a citation carries.
_Avoid_: sign-off, which the leadership thread also used for the S1 Citations clerk's
proofread and for go-live approval

**signing billet**:
The billet a signature slot takes, such as Regimental Commander. It matches a signature
to a slot and is never printed.
_Avoid_: role, which already means XenForo user groups and Discord roles in this suite,
and position outside roster-schema talk

**signature slot**:
A field on a template where a signature is set. Each one takes one signing billet.

**signature**:
One officer signing as one signing billet, with the printed lines S1 typed for it and an
ink-only image. Active or retired, and frozen once a grant uses it.
_Avoid_: signature asset

### Grants

**grant**:
A citation an S1 Citations clerk issues to one or more members, or to the whole
regiment, on one date, with one template, one set of signatories and, when written, one
citation text.
_Avoid_: grant for one dated PUC award row, which is `Cav7/EnlistmentDefaults`' sense

**grant member**:
One member of a grant, holding their own name and rank snapshot. It exists before any
row is attached to it, and one can be attached to any number of rows.

**regiment grant**:
A grant issued to the whole regiment, with no grant members, that every milpac carries.
The PUC today.
_Avoid_: unit citation, since MUC, SUA, AVUA and JMU are unit citations issued to
members, and open grant, which is retired and had members

**rank snapshot**:
A rank's printed title, pay grade and abbreviation, copied at issue, on each grant member
or once on the grant as the template declares. A rename in NF/Rosters never reaches it.

**row kind**:
An award row, or a service record of one record type. Award rows have no record type, so
record type alone can't name them.

**grant link**:
A row's pointer at one grant member, or at a regiment grant. Like a citation image, it
stays until someone removes or swaps it, the row is deleted, or its grant member is
removed.
_Avoid_: citation reference, which points at a blob

**attach**:
Putting a generated citation on a row by picking it in the citation picker.
_Avoid_: pickup, the retired automatic matching

**citation picker**:
The list on a row form, in place of the file upload, of every citation issued to that
member, attached or not.

### Renders

**render**:
The image the generator draws for one grant member, or for a regiment grant, when someone
opens it. Derived and never the record, because the grant is.
_Avoid_: citation image, which is the image in a row's citation slot, and generated image

**typesetting**:
How one grant member's citation, or a regiment grant's, was set: each text field's words,
type size, line breaks and positions, and whether it fell below its legibility threshold.
_Avoid_: layout, which means the certificate's shape, and render, the image drawn from it

**retypeset**:
An operator remaking chosen citations' typesettings with the current renderer, to bring a
renderer fix to citations already issued.
_Avoid_: correction, because no input to the grant changes
