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
interest or game role is never a divergence, however it got there. The condition
is the same whether it arose from a group change the sync never applied or from a
hand edit made in Discord.
_Avoid_: "mismatch" (reads as any role difference, including the self-assigned
roles this excludes), and "drift" for the whole thing (keep it for the Discord-side
origin alone if origins need naming).

**Reconciliation sweep**:
The scheduled pass that finds the members currently in **divergence** and corrects
only those, leaving members already in agreement untouched.
_Avoid_: "full sync" and "resync" (both read as re-running the sync for every
member, the blind shape this is defined against).
