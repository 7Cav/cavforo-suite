# Cav7/EnlistmentNextSteps

Lands a recruit on a next-steps page the moment their Enlistment application is
accepted, in place of the "Successfully Submitted!" toast and the jump straight
to their own thread. Companion to [OzzModz] Advanced Forms (`Snog/Forms`);
attaches through one XenForo class extension over the vendor's public form
controller and ships none of the vendor's code. For **Enlistment queue** and
**Processing Clerk**, see
[Cav7/EnlistmentReminder's CONTEXT.md](../EnlistmentReminder/CONTEXT.md). For
**milpac** and other suite-wide terms, see the root
[CONTEXT.md](../../../../CONTEXT.md).

## Language

**next-steps page**:
The page a recruit lands on after a **triggering form** is accepted: a title,
RRD's paragraph on what happens next, the enlistment process image, and one
"I Understand" button that goes to the recruit's own thread in the Enlistment
queue. It opens for anyone the thread itself would open for, and for nobody
else. It is the whole of the confirmation: no toast precedes it, nothing
records that the button was pressed, and no private message is sent about it.
_Avoid_: confirmation page (Advanced Forms already has a per-form
"confirmation email" and a "confirm" dialog for promotions; this page is
neither), thank-you page (the vendor's "thanks" field is the toast this page
replaces), receipt, landing page.

**triggering form**:
An Advanced Forms form whose accepted submission shows the **next-steps page**.
Which forms trigger is the `cav7ENSTriggeringFormIds` option, a list of form
IDs; the default is `1`, the Enlistment application. The Re-Enlistment form
is added in the admin panel when RRD wants it. A form outside the option keeps the
vendor's own reply, as does a triggering form whose submission produced no
thread.
_Avoid_: enlistment form (the option can hold any form, and form 1 is one of
several the vendor serves), application (the thread is the application; the
form is what produces it).

**vendor reply**:
What Advanced Forms answers a submission with when this addon stays out of the
way: a redirect to the form's configured return target, carrying the form's
"thanks" text as a toast. The addon leaves both fields as they are, so they
still apply on every path the next-steps page does not take.
_Avoid_: default redirect (the target is per form, set by the admin, and not a
default of anything).
