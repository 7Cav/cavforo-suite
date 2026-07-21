# Cav7/MilpacMention

Treats a linked **milpac** like an `@`-mention: when a member's roster profile
link appears in content, that member is alerted. Companion to NF/Rosters, and to
NF/Tickets as a soft dependency. For **milpac** and other cross-addon terms, see
the suite-wide [CONTEXT.md](../../../../CONTEXT.md).

## Language

**Milpac link**:
The roster-profile URL for one member, `/rosters/profile/<relation_id>/`, written
into a message either by hand or by the `$name` completer. Members built these
links for years before this add-on existed, and several 7Cav workflows treat the
link as the canonical way to say *which member this is about* — so a milpac link
is a **record**, independent of whether anyone is notified about it.
_Avoid_: using "milpac link" and **milpac mention** interchangeably. The link is
the text in the message; the mention is what the add-on does with it.

**Milpac mention**:
The alert raised to a member because their **milpac link** appeared in content —
one `milpac_mention` notification landing in their bell and opening the content.
The mention is the *notification*, not the link that triggered it: suppressing a
mention leaves the link, and the record it carries, completely intact.
_Avoid_: "milpac tag", and "mention" unqualified where an XenForo `@`-mention is
also in play — the two are distinct alerts with distinct firing rules.

**Surface**:
One kind of content a **milpac mention** can fire on. There are five: posts,
profile posts, profile-post comments, report comments, and ticket messages. Each
surface owns its own alert wiring, so a rule that should hold everywhere has to
be stated once per surface rather than in one shared place.
_Avoid_: "context" (overloaded — this repo uses it for bounded contexts) and
"content type" (an XenForo term that also covers types this add-on never fires
on).

**Suppressed area**:
A place where **milpac mentions** do not fire even though the **milpac link**
resolves normally — configured as a deny-list of ticket categories and a
deny-list of forum nodes. Anything not named in those lists behaves exactly as it
does today, so suppression is always an explicit act. Suppression is by *place*,
not by member and not by relationship: it does not consult who is a ticket
participant, who opened the ticket, or who can view it.
_Avoid_: "disabled area" or "muted area" — the add-on is still running there and
the `$name` completer still works; only the alert is withheld. Also _avoid_
describing it as an opt-out, which is the separate per-member preference.

**Award queue**:
A ticket category where a **milpac link** names the *subject* of an award being
processed rather than the member being addressed — S1 Citations (17), Medal
Recommendations (18), Medal Approvals (20) and Medal posting (21). Measured
against the prod mirror, these four sit at 88–95% of milpac links pointing at
someone other than the member who opened the ticket, a band nothing else on the
board occupies; the ordinary S1 and support queues run 42–80%. This is the shape
that makes a **milpac mention** harmful: the subject is alerted, and the alert
line carries the ticket title, so an award is disclosed to its recipient while it
is still pending.
_Avoid_: reading award-shape from the category title. Military Service Awards
(25) reads as an award queue and is not one — members open those tickets about
their own awards.
