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

**Processing Clerk**:
A member seated in one of the RRD enlistment-processing positions — currently RRD
Enlistment Processing Clerk (580), RRD Re-Enlistment Processing Clerk (960), RRD
Senior Processing Clerk (1012), RRD Lead Processing Clerk (579), and RRD
Processing Clerk IT (751). The seat may be held as a member's **primary** roster
position (`position_id`) _or_ as a **secondary** one (`secondary_position_ids`) —
today every holder carries it as a secondary duty, but primary is possible — so
both must be read. Any one of the five counts, regardless of enlistment type.
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
XenForo **alert** to each current **Processing Clerk** (landing in their
notification bell and linking to the application) and posts one brief, neutral
note in the thread. The pointed "pick this up" wording lives in the private
alert, which only clerks see; the visible in-thread note stays applicant-safe.
Fires **once** per thread. Clerks are alerted directly rather than `@`-mentioned
in the post, so the applicant's thread is not cluttered and non-clerk activity
cannot suppress the signal.
_Avoid_: "tag" / "mention" — clerks are not `@`-mentioned in the post body; they
are alerted directly through XenForo's alert system.
