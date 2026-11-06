# A field is a box holding a pattern

2026-11-06. Decided in [#304](https://github.com/7Cav/cavforo-suite/issues/304).

A field is a box on a template version, and there are three kinds: a text field, a signature slot and rank art. A text field holds a pattern, which is literal text plus placeholders from a fixed list the renderer knows and the plain-text template inputs each template declares. Everything else that varies between certificates is a choice of template.

Name, rank, pay grade, date and citation text carry rules the generator has to know, such as a snapshot at issue, a prefill from the milpac and a date format. Class numbers and unit lines have no structured source and no rule, so the S1 Citations clerk types them. Fixed columns would take a release for every new input. Inputs declared entirely per template would leave the generator unable to prefill or snapshot a rank. So the model is a hybrid.

Recipient and date lines put fixed words and values on one centred line, and a centred line can't be split into plate pixels with a typed hole in it. So the renderer sets the whole line from the pattern, fixed words included, in the shipped fonts.

## Patterns

A generic citation's text is a text field whose pattern is all literal. A pattern can mark a segment optional, and the segment prints only when every value in it is filled, so a blank pay grade drops its brackets too. A field with nothing left isn't drawn.

Each text field is set as stored or in capitals. A title-case setting would mangle mixed-case surnames, and NF/Rosters already stores rank titles in title case.

The renderer formats dates and nobody types them, because a typed date once put the wrong year on a whole batch. The place and the fixed wording are literal text, so today's date shapes survive as patterns.

## Template inputs

A template input is plain text, scoped to the grant or to each member, and required or optional. A required input blocks issue, as an empty signature slot does, because the S1 Citations clerk has the value in hand. Changing a value is a logged correction.

A key means one thing across every version of its template. Moving a grant to another version carries values over by key, keeps the ones the new version doesn't use, and asks for any required input the grant lacks.

Class numbers are a grant-scoped input. Unit lines are an input too, never derived from the roster. No table holds a unit hierarchy, and certificates that print a unit line follow no rule about which unit it names. S1 sets the scope per template.

A version with a member-scoped input, a name or a member's rank can't back a regiment grant, since a regiment grant has no members.

## Rank

At issue the rank snapshot copies the printed title, pay grade and abbreviation with the rank's id. A render reads only the copied text, and the id only picks rank art from the pinned version, so a rename or deletion in NF/Rosters changes no issued certificate.

The template declares where the rank comes from. It's each member's rank, one rank the grant sets for all its members, or none. The row kind can't decide this, because the Promotion record type also holds certificates that aren't promotions.

NF/Rosters has no pay grade, so the addon keeps one row per rank with its pay grade and abbreviation. S1 edits that list in the template manager, and an edit reaches only later grants.

A rank-art field holds one image per rank, frozen with its version. A grant-set rank can only be one the version has art for, so a promotion can't be issued without its insignia. With a member's rank, a rank with no image leaves the box empty.

## Variants are templates

Variants such as valor titles, badge levels, Good Conduct knots and honor graduate are each a template of their own, sharing a plate where the art allows, and the S1 Citations clerk picks one. Each choice already matches a separate award or a tier S1 exports as its own image, so none of them needs a field. A template version names the awards or record types it serves and may name more than one.
