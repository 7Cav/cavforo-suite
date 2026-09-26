# Cav7/FormsUserQuestion

Lets an Advanced Forms form ask for forum users by choosing them, where today it
can only ask for a name typed as text. Companion to [OzzModz] Advanced Forms
(`Snog/Forms`), which owns the forms, their questions and the thread a
submission posts. For **milpac** and other suite-wide terms, see the root
[CONTEXT.md](../../../../CONTEXT.md).

## Language

**forum user**:
An account on the forum, whether or not the person behind it is in the unit or
holds a **milpac**.
_Avoid_: member (in 7Cav usage a member is someone in the unit, usually with a
milpac, and nothing here checks that)

**forum user question**:
An Advanced Forms question answered by choosing **forum users** rather than
typing their names. It comes as two question types: "Forum user", which takes
one, and "Forum users", which takes several.
_Avoid_: member question, username field, text question (the vendor type these
replace, which accepts any string)

**user picker**:
The input a **forum user question** is answered through: a name box that offers
matching forum users as the name is typed.
_Avoid_: member picker, and user picker for the question itself (the picker is
only the input)
