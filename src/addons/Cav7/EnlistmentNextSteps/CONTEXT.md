# Cav7/EnlistmentNextSteps

Shows an applicant what happens next the moment an enlistment application is
accepted, in place of the vendor's toast and redirect. Companion to `Snog/Forms`
(Advanced Forms), which owns the form and the thread it posts. For **milpac**,
**Enlistment queue** and other shared terms, see the suite-wide
[CONTEXT.md](../../../../CONTEXT.md) and
[Cav7/EnlistmentReminder](../EnlistmentReminder/CONTEXT.md).

## Language

**Next-steps page**:
The page an applicant lands on right after a **triggering form** accepts their
submission. It tells them what happens next and carries one button onward. It
is the whole of the confirmation: no toast precedes it, and nothing records
that the button was pressed.
_Avoid_: confirmation page (the vendor already has a per-form "confirmation
email" and a "confirm" dialog for promotions), thank-you page, receipt

**Triggering form**:
An Advanced Forms form whose accepted submission lands on the **next-steps
page** rather than on the vendor's own return target. Which forms trigger is
board configuration, listed by form ID. A form off the list keeps the vendor's
behaviour untouched, and so does a triggering form whose submission produced
no thread.
_Avoid_: "the enlistment form" as the name for the set (the set is configured,
and can hold Re-Enlistment too)
