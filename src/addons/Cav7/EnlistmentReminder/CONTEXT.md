# Cav7/EnlistmentReminder

Alerts the Processing Clerks when an enlistment has sat in the queue past a
deadline without a clerk picking it up, so applications do not creep toward the
48-hour start-processing SLA unnoticed. Companion to NF/Rosters, SV/MultiPrefix
and the enlistment forum; reads the thread's status prefixes to tell a started
application from an untouched one, and roster positions to decide who to tell.
For **milpac** and other cross-addon terms, see the suite-wide
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
from a **Processing status**, which a clerk applies during processing and which
is never the type. The converse matters just as much: the type prefix is not a
processing status, and treating it as one silences the reminder for the whole
queue. Also _avoid_ "the re-enlistment forum" — both types share node 325; only
the prefix differs.

**Processing Clerk**:
A member seated in one of the RRD enlistment-processing positions — currently RRD
Enlistment Processing Clerk (580), RRD Re-Enlistment Processing Clerk (960), RRD
Senior Processing Clerk (1012), RRD Lead Processing Clerk (579), and RRD
Processing Clerk IT (751). The seat may be held as a member's **primary** roster
position (`position_id`) _or_ as a **secondary** one (`secondary_position_ids`) —
today every holder carries it as a secondary duty, but primary is possible — so
both must be read. Who is seated decides only _who gets told_ about an
**Un-actioned enlistment**, never _whether_ one is un-actioned: the five split by
**Enlistment type** into two overlapping responsibility sets. A Re-Enlistment
routes to the Re-Enlistment (960), Senior (1012) and Lead (579) seats; a Standard
routes to the Enlistment (580), Processing Clerk IT (751), Senior (1012) and Lead
(579) seats. Senior and Lead sit in both.
_Avoid_: conflating clerks with the RRD usergroups (59/60) or the `!vac`
allowed-role groups — those over-cover; clerk membership is by roster position,
not usergroup. Also _avoid_ making a clerk's identity part of the un-actioned
test: seats are re-read on every scan, so a rule that depends on who holds one
un-does itself when a clerk rotates out.

**Processing status**:
The state a clerk has moved an application to, carried as a thread prefix from
the set RRD's queue runs on: _In Progress_ (55), _Hold_ (53), _Approved_ (54).
SV/MultiPrefix lets a thread hold several prefixes at once and stores them all in
`xf_sv_thread_prefix_link`, alongside the **Enlistment type** prefix and the
_decorations_ that ride with a status (S1 66 and RTC 68 alongside Approved, "!!!"
110 alongside In Progress) — listed in `cav7ERDecorativePrefixIds`, and named only
so that a prefix the reminder has no opinion about can be told from one nobody has
told it about yet. A processing status is the only signal that an
application has been picked up. It is a fact about the thread, so unlike the
authorship of a reply it cannot be withdrawn later by a roster change.
The subset of those prefixes the reminder treats as "picked up" is the
_in-processing set_, configured in `cav7ERInProcessingPrefixIds` and defaulting to
all three. A thread carrying any member of that set is **handled**: the reminder
suppresses it, and "handled" is the name the code and these docs use for that one
fact. Its complement is an **Un-actioned enlistment**.
_Avoid_: "does the thread have a prefix" as a stand-in — every valid queue thread
carries its type prefix in that same table, so the loose test reads the entire
queue as handled and silences the reminder with nothing in the log. Also _avoid_
sticky/pinned state: a thread is pinned when it reaches Approved, which is the
end of processing rather than the start, so a pin arrives far too late to be a
trigger.

**Un-actioned enlistment**:
A thread in the **Enlistment queue** that has passed the reminder deadline
carrying no **Processing status**. This is the state the bot reminds on — the
reminder fires only while an application is both unresolved (still in node 325)
and unattended (no clerk has moved it to a status).
_Avoid_: "stale" or "pending" alone — everything in the queue is pending; the
distinguishing fact is the _absence of a processing status_ past the deadline.

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
