# Every citation is a grant, and a person attaches it

2026-11-06. Decided in [#275](https://github.com/7Cav/cavforo-suite/issues/275), [#301](https://github.com/7Cav/cavforo-suite/issues/301), [#306](https://github.com/7Cav/cavforo-suite/issues/306) and [#307](https://github.com/7Cav/cavforo-suite/issues/307).

The generator replaces the GIMP export and the upload, and nothing else. Who makes which rows, how they're filed and what each row shows stay as they are.

An S1 Citations clerk issues every citation the generator covers, generic or written, as a grant. A grant has one award or record type, one date, one template version, one set of signatories and its members. A written grant also has one citation text, plain text typed once after the proofread. Whoever uploads a citation image today attaches the citation by picking it on the row form, and nothing attaches by itself.

The S1 SOPs put the citation first and split the work. S1 Citations clerks own citations and create no rows. S1 Milpacs clerks create rows and never enter citation text or issue grants. Issuing a citation in the same save that creates its row would break one of those two rules. On the row side the generator replaces only the upload box, so the replacement is a picker in its place.

The text sits on the grant because shared text is the normal case. A written award with several recipients usually gives them one paragraph, and their images differ only in the recipient line. A template per post would have S1 publishing a template for every one of those posts. One correction reaches every member of a grant, and a member who needs different wording goes in a grant of their own.

The grant snapshots each member's name and rank at issue, so a promotion between the grant and the row doesn't change the certificate. There is no approval gate. Seniors check as they do today, and a fix is a logged correction.

## One citation, many rows

A citation can go on any number of rows, and nothing limits a member to one row per award or record type. Today a boot camp graduate's certificate goes on the boot camp record, whether filed as Graduation or Promotion, on the Army Service Ribbon row and, for an honor graduate, on the Honor Graduate Ribbon row. S1 Milpacs picks the same citation on each of those rows, and they all show one render URL.

So a grant keeps one award or record type, and boot camp needs no rule of its own. The row kinds a template version names decide only which templates the grant form lists. The picker lists every citation issued to the member, attached or not, because a list of unattached citations would lose the boot camp certificate after the first row.

A picked citation behaves like an uploaded image. It stays when the row's award or record type changes. Anyone who can remove an image can remove it or swap it, under the same `manageAwards` and `manageRecords` permissions. Deleting the row removes it. A row made before its citation gets it when someone comes back and picks it, the way late images are added today. Disciplinary rows keep the file upload.

## Member additions

A member who belongs to the same approval and was left off joins the issued grant, and takes its text, date, template version and signatories. A member approved later, on another date, gets a grant of their own, since a grant has one date. The form takes the added member's snapshot as at issue and shows the grant's date beside it, so a promotion in between is visible.

An addition is a logged event and not a correction. No other member's render changes, so it moves no revision and their URLs stay the same. It attaches nothing either. Someone picks the citation on the member's row.

Removing a member is the same kind of event, with a reason and a list of the rows it cleared. A member removed by mistake comes back with a new URL and a fresh snapshot.

A [regiment grant](https://github.com/7Cav/cavforo-suite/issues/308) has no members, so none of this applies to it.

## Out of scope

The generator doesn't write the Awards post, doesn't start a grant from a recommendation ticket, and has no page of what's waiting for a row. None of those replaces GIMP or the upload.

## Considered options

- Matching rows to grants by itself, on a row's save or on issue. It changed how S1 Milpacs works, and it needed rules for declines, for a row changing kind and for a waiting list, mostly to handle its own side effects.
- A grant serving several row kinds, with one row of each per member. Picking on each row gives the same result with no rule.
- Leaving the ribbon rows without a citation, and filing boot camp only as Graduation. Both change what happens today.
