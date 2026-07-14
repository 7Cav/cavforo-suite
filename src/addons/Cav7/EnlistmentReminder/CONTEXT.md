# Cav7/EnlistmentReminder

Alerts the Processing Clerks when an enlistment has sat in the queue past a
deadline without a clerk picking it up, so applications do not creep toward the
48-hour start-processing SLA unnoticed. Companion to NF/Rosters and the
enlistment forum; reads roster positions to tell a clerk from a recruiter. For
**milpac** and other cross-addon terms, see the suite-wide
[CONTEXT.md](../../../../CONTEXT.md).

## Language

**Enlistment queue**:
The `Enlistment Papers` forum (node 325), the pending queue that new enlistment
applications post into. A thread stays here until a clerk resolves it, at which
point it is moved to the child forum `Completed Enlistment Papers` (326) or
`Denied Enlistment Papers` (327). So a thread's presence in node 325 means the
application is _unresolved_ — but not necessarily _unattended_, since a clerk can
work a thread here for days before moving it.
_Avoid_: "the enlistment node" as if it were one node — the queue is node 325
specifically, distinct from its Completed (326) / Denied (327) siblings, which
are out of scope for the reminder.

**Enlistment type**:
Which intake form produced a queue thread, carried as the thread's _primary_
prefix: _Standard_ (the "Enlistment" prefix, id 57) or _Re-Enlistment_ (the
"Re-Enlistment" prefix, id 58). The Advanced Forms (`Snog/Forms`) intake form
stamps the prefix at submission, so it is present before any clerk touches the
thread and is reliable when the reminder fires. A queue thread whose primary
prefix is neither — or absent — was not made by an intake form and is not a valid
enlistment; the reminder skips it. Type decides _which_ **Processing Clerks** an
un-actioned thread alerts, not _whether_ the thread is un-actioned.
_Avoid_: reading type from the thread title (the form subjects don't name it) or
from a secondary status prefix (In Progress, Hold, Approved…), which a clerk
applies during processing and which is never the type. Also _avoid_ "the
re-enlistment forum" — both types share node 325; only the prefix differs.

**Processing Clerk**:
A member seated in one of the RRD enlistment-processing positions — currently RRD
Enlistment Processing Clerk (580), RRD Re-Enlistment Processing Clerk (960), RRD
Senior Processing Clerk (1012), RRD Lead Processing Clerk (579), and RRD
Processing Clerk IT (751). The seat may be held as a member's **primary** roster
position (`position_id`) _or_ as a **secondary** one (`secondary_position_ids`) —
today every holder carries it as a secondary duty, but primary is possible — so
both must be read. For a **Clerk pickup**, any one of the five counts, regardless
of type. For the alert half of a **Clerk reminder**, though, the five split by
**Enlistment type** into two overlapping responsibility sets: a Re-Enlistment
routes to the Re-Enlistment (960), Senior (1012) and Lead (579) seats; a Standard
routes to the Enlistment (580), Processing Clerk IT (751), Senior (1012) and Lead
(579) seats — Senior and Lead sit in both, so their union is the same five whose
reply counts as a pickup.
_Avoid_: conflating clerks with the RRD usergroups (59/60) or the `!vac`
allowed-role groups — those over-cover; clerk membership is by roster position,
not usergroup. Distinct from an **RRD Recruiter**, whose reply is not a pickup.

**Clerk pickup**:
The signal that an enlistment has been started: a visible in-thread reply
authored by a current **Processing Clerk**. The OP (the enlistee), the S6 bot's
own posts, and **RRD Recruiter** replies are _not_ pickups. Measured against the
prod mirror, a pickup reliably separates started from un-started applications:
every queue thread older than roughly two days carries one, and completed
applications reach their first pickup ~20 hours after posting on average.
_Avoid_: treating any staff reply, or the bot's own VAC reply, as a pickup.

**Un-actioned enlistment**:
A thread in the **Enlistment queue** that has passed the reminder deadline with
no **Clerk pickup**. This is the state the bot reminds on — the reminder fires
only while an application is both unresolved (still in node 325) and unattended
(no clerk has posted).
_Avoid_: "stale" or "pending" alone — everything in the queue is pending; the
distinguishing fact is the _absence of a pickup_ past the deadline.

**Clerk reminder**:
What the bot does when an enlistment becomes **un-actioned**: it sends a direct
XenForo **alert** to each current **Processing Clerk** responsible for the
thread's **Enlistment type** (landing in their notification bell and linking to
the application) and posts one brief, neutral
note in the thread. The pointed "pick this up" wording lives in the private
alert, which only clerks see; the visible in-thread note stays applicant-safe.
Fires **once** per thread. Clerks are alerted directly rather than `@`-mentioned
in the post, so the applicant's thread is not cluttered and non-clerk activity
cannot suppress the signal.
_Avoid_: "tag" / "mention" — clerks are not `@`-mentioned in the post body; they
are alerted directly through XenForo's alert system.

**Reminder note**:
The single brief, neutral post the S6 bot leaves in an application's thread as
the visible half of a **Clerk reminder** — an audit trail and a recency bump,
separate from the private clerk alert. Its wording is applicant-safe: it carries
no "pick this up" language and `@`-mentions no one. One per thread.
_Avoid_: "the bot's post" (ambiguous — the same S6 bot also posts SteamChecker's
VAC reply in these threads, so "a bot post" and "the reminder note" are not
interchangeable), reminder message.
