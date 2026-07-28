# Cav7/DiscordSyncPatch

Makes a member's forum user groups authoritative for every Discord role a group
grants. Companion to NF/Discord, whose per-user sync message it extends. For
terms shared across the suite, see the suite-wide
[CONTEXT.md](../../../../CONTEXT.md).

## Language

**Grant record**:
NF/Discord's own note of which Discord roles it handed one member on one guild,
kept as `discord_role_ids` on that member's `xf_nf_discord_sync_log` row. The
integration writes it at the end of every sync and reads it at the start of the
next one to decide which roles it is allowed to take away. It is a memory of what
the sync did, not a statement of what the member should have, which is why it can
end up disagreeing with their groups and why a sync that trusts it alone repeats
the disagreement for good.
_Avoid_: "sync log" for this field alone (the log row also carries the group set,
the active flag and the error phrase), and "granted roles" (the record can name a
role the member no longer holds).

**Managed role**:
A Discord role that at least one XenForo user group grants, as configured on the
user group. Managed is a property of the role and the configuration, never of a
particular member: a role stays managed whether or not anybody currently in the
granting group holds it. Every other role on the guild is outside this addon
entirely, so self-assigned interest and game roles keep working exactly as they
did.
_Avoid_: "synced role" and "bot role" (both read as "a role the integration
happens to have touched", which is the **grant record**'s question, not this one).

**Claim**:
The set of roles one sync run treats as its own for the guild it is syncing, and
may therefore remove from the member. It is the union of that member's **grant
record** and every **managed role** on that guild. Being a union is the point: it
only ever widens what the sync may remove, so a role the integration would clean
up today still gets cleaned up. The claim lives in memory for the length of one
message. It is never stored, because the record it is written into is overwritten
with the member's correct roles before the sync saves.
_Avoid_: treating the claim as a set of roles to grant. It decides removability
only, and never changes which roles a group hands out.

**Divergence**:
A member's **managed role**s on Discord disagreeing with the roles their current
forum groups grant: a managed role they hold that no group grants them, or one a
group grants that they do not hold. Only managed roles count, so a self-assigned
interest or game role is never a divergence, however it got there. Roles the bot
cannot move — **preserved role**s and **out-of-reach role**s — do not count either,
in either direction: a disagreement about one describes work no correction could
carry out, and left in it survives every correction and re-selects the member on
every run. The condition is the same whether it arose from a group change the sync
never applied or from a hand edit made in Discord.
_Avoid_: "mismatch" (reads as any role difference, including the self-assigned
roles this excludes), and "drift" for the whole thing (keep it for the Discord-side
origin alone if origins need naming).

**Stale sync record**:
A member's `xf_nf_discord_sync_log` row for one guild no longer describing a correct,
successful sync: the group set it recorded disagrees with the member's groups now, or
the row is inactive, or it carries an error phrase. It is the forum side's evidence
of likely **divergence**, and only evidence — the roles themselves are not read, and a
role write that failed returns before the row is touched at all, so a dropped write
leaves a record that still looks settled. Note the vendor writes the error phrase and
clears the active flag together, so an errored record is always an inactive one.
_Avoid_: treating it as **divergence** itself (that is a statement about roles on
Discord, which this never looks at), and reading "inactive" as "this member is not
synced" — an inactive record is one of the strongest reasons to sync them.

**Preserved role**:
A Discord role the bot cannot move because Discord owns it: anything an integration
created (flagged `managed` on the role) and the Nitro-booster role (which carries the
`premium_subscriber` tag and is managed besides). Discord rejects a role write that
would add or drop one, and rejects it whole rather than in part, so any set this addon
sends must carry every preserved role the member already holds — otherwise the write
fails entirely and the vendor swallows the refusal. A role can be preserved and
**managed** at once: a user group may well grant a role Discord also owns, and where
the two meet, preserved wins and the role stays.
_Avoid_: reading "preserved" as the opposite of "**managed role**", which is the
nearest trap in this glossary. They answer different questions — managed is "does a
user group grant this?", preserved is "will Discord let the bot move it?" — and
Discord's own `managed` flag means the second, not the first. Note a role the bot
merely sits below is not preserved but an **out-of-reach role**: same treatment,
different remedy.

**Out-of-reach role**:
A Discord role the bot cannot move because of where it sits — at or above the highest
position the bot's own roles reach. Discord refuses any write that would add or remove
one, and refuses it whole, so a set that drops one loses every other change with it.
That is the same shape a **preserved role** produces and takes the same treatment: keep
it in any set sent, and judge no **divergence** on it in either direction. What
separates the two is what an admin should do about it — a preserved role is permanent
and correct, an out-of-reach role is a misconfiguration one drag in the role list
fixes, so this one is reported and that one never is. Position alone decides it, since
Administrator does not bypass the hierarchy, and a role level with the bot counts as
out of reach: Discord breaks that tie on an id ordering its own docs do not state.
Together with **preserved role**s these are the guild's _immovable_ roles, which is the
only thing the strip and the divergence judgement ask about: the two reasons differ in
what an admin should do, never in what a correction may do.
_Avoid_: "role above the bot" for the whole thing (a role level with it counts too),
and calling the *member* out of reach — Discord's target-hierarchy rule covers kicks,
bans and nicknames, not role writes, so the member's own top role blocks nothing here.

**Reconciliation sweep**:
The scheduled pass that finds the members needing correction and corrects only
those, leaving everyone already in agreement untouched. It reconciles two distinct
populations from one guild member fetch: linked members in **divergence**, and
**unlinked holder**s. The two never overlap — a member either has a link or does
not — so the sweep partitions them on that one question.
_Avoid_: "full sync" (reads as re-running the sync for every member, the blind
shape this is defined against). A **resync** is that blind shape narrowed to one
member who asked for it, so it names a different thing rather than this one.

**Resync**:
One member asking the integration to run its per-user sync for them, without
waiting for a group change or for the **reconciliation sweep** to come round. It is
blind: it runs whether or not the member is in **divergence**, because honouring
the request costs about what checking first would. It corrects the member who asked
and nobody else, and it decides nothing an ordinary sync does not already decide.
_Avoid_: "force sync" (nothing is forced; the request is queued and can be
refused), and using it for the **reconciliation sweep**, which is scheduled,
covers a population and corrects only the members it finds divergent.

**Unlinked holder**:
A Discord guild member who holds at least one **managed role** but has no nfDiscord
connected account linking them to a forum user. Only the sync grants managed roles
and the sync requires a link, so an unlinked holder is the residue of a link that
went away: a disconnect whose role strip failed or never fired, a link removed by a
path that bypassed the disconnect event, or a managed role hand-added in Discord.
They carry no forum-side trace — a clean disconnect deletes both the **grant
record** and the connected account — so the sweep can only find them from the
Discord side, and their correct end state is simply no managed roles at all.
_Avoid_: "**divergence**" (that presumes a link and a group set to reconcile
against, neither of which an unlinked holder has), and naming the member an
"orphaned role" (the member is not the role).

**Throttled read**:
A call Discord declined for pacing rather than on its merits — the guild is fine, the
bot's permissions are fine, and the same call would have been served with more room.
It is temporary by definition: the next **reconciliation sweep** run is fifteen minutes
later and makes the same call again. What makes the term worth having is that it is
invisible in the result, since a throttled call and a **refused read** both come back
from NF/Discord as the same `false`; telling them apart takes a second question. Which
question is an implementation detail and has changed once already, so define nothing in
terms of it.
_Avoid_: "rate limit" for the event (that is the rule being applied, not what happened
to this call), and "failed read" (which is what conflating the two costs).

**Refused read**:
A call Discord declined on its merits — a missing permission, a revoked privileged
intent, a role set it rejects whole. It says something is wrong with the guild, the
bot or the request, and the next run will be refused in exactly the same way until
somebody changes one of them. The opposite of a **throttled read** in the only sense
that matters operationally: waiting fixes a throttle and never fixes a refusal.
_Avoid_: "error" (too broad — it covers both of these and a connection that never
reached Discord at all), and "rejected" for the throttle case.
