# The S1 Citations clerk picks the signatures

2026-11-06. Decided in [#295](https://github.com/7Cav/cavforo-suite/issues/295).

Each signature slot on a template takes one signing billet, and the grant form offers only the signatures S1 keeps on file for that billet. The S1 Citations clerk picks once per grant, and every member of the grant carries the same ones. The generator never looks up who holds a billet.

Signing is ceremonial and separate from approval. The regulations name approving billets, no page says who signs, and today's plates carry the Regimental Commander whoever approved. The roster can say who holds a billet now but not who held it before, because the vendor keeps no history and service records name billets in free text. Resolving the holder by date would need a dated register that S1 keeps by hand and the roster couldn't seed. Fixing the signature on the template version is what produced the drift S1 raised, where several 2026 plates still carried a commander from before the last change of command. Picking leaves the choice with the S1 Citations clerk, who issues the grant anyway, and the billet filter keeps each picker to one or two names.

## Signatures

A signature is one officer signing as one signing billet. It holds the printed lines S1 types for it and an ink-only image at plate scale, and the generator sets the lines in the shipped fonts. The roster isn't read, because its rank title isn't always what a plate prints. An officer's signatures can share one ink image. An acting commander needs no special case, just a signature for the billet whose printed lines say so.

A signature freezes once a grant uses it. A promotion, a rename or a better scan means a new signature, and S1 retires the old one. The grant pins one signature per slot and copies nothing. S1 can edit or delete a signature until a grant uses it.

Signatures are active or retired, like template versions. A retired signature still renders wherever it's pinned, so retiring changes only what the picker offers and can be undone.

## The picker

The picker shows the active signatures for the slot's billet, plus the grant's current signature when correcting. A switch shows the retired ones, for a grant approved before a change of command and issued after it. It preselects when exactly one signature shows by default, and otherwise leaves the slot blank.

An S1 Citations clerk can't issue a grant while a slot is empty. The rule that the form always saves protects text already approved upstream. A missing signature is a gap S1 can close itself, and printing without one is the defect S1 raised. Changing a grant's signature is a logged correction.

## Signing billets

S1 manages signatures and signing billets in the template manager, under Publish templates and manage signatures and pay grades, so a change of command needs nobody outside S1. A billet name is only a matching key, since the printed title comes from the signature, so renaming a billet changes no certificate. A slot's billet freezes with its template version. S1 can remove a billet only when nothing uses it, and billets aren't linked to roster positions.
