# ADR-0004: Backstop disconnect cleanup in the sweep; leave the vendor disconnect event alone

- **Status:** Accepted
- **Date:** 2026-07-21
- **Issues:** #155, absorbed into #157
- **Note (2026-07-25):** the "wiring pin" referred to below was deleted as a
  source-text change detector. Do not reinstate it or write another like it; see
  ["What belongs in CI, and what does not"](../../../../../../CONTRIBUTING.md).

## Context

#155 asked us to clean up members left holding managed roles after they unlink from
the forum, on the premise that the addon does nothing on disconnect. The vendor
actually does react. `NF\Discord\XF\Entity\UserConnectedAccount::_postDelete` queues
`DissociateUser`, and on the live configuration (the `nfDiscordAssocKickServer`
option off) that message strips the managed roles the member's groups used to grant.

The problem is that the strip has no retry. `patchGuildRoles` swallows the Discord
API error and returns, with a literal `// todo: error reporting` where a retry would
go. Several ordinary failure modes drop the strip for good: a managed role sitting
above the bot in Discord's role hierarchy, a rate-limited or down bot (about three
thousand members against a budget near sixty role writes a minute), or an unlink that
never fires `_postDelete` at all, such as a raw row delete, an account deletion, or a
provider disable. The member is then left in the guild holding forum-managed roles
with nothing linking them to the forum, and no event will look at them again.

A clean disconnect also deletes the member's grant record and connected account, so
these members leave no forum-side row to find them by. The only evidence they exist
is on the Discord side.

## Decision

The reconciliation sweep (ADR-0002) takes on a second population: unlinked holders,
members in the guild holding managed roles with no `nfDiscord` connected account. It
detects them from the bulk member fetch it already makes for divergence, by taking
the managed-role holders whose Discord id is not among the linked ids, and corrects
them with a direct role strip that removes their managed roles and keeps everything
else, including the Nitro-booster role.

Correction here cannot reuse the per-user sync message the divergence job uses.
`SyncUser` requires a connected account and bails with `user_not_associated` without
one, and there is no forum user to run it for. The strip is a direct
`patchGuildMemberRoles`, driven by its own pure decision unit in the spirit of
`RoleClaim`.

The vendor's disconnect handler is left exactly as it ships. This addon does not
extend or repair `_postDelete`; the sweep is the backstop for the strips that handler
drops.

## Consequences

- Every disconnect-cleanup failure mode is closed by the same periodic pass,
  including the unlink paths the event never sees, without this addon owning or
  duplicating the vendor's disconnect logic.
- The sweep now has two correction paths: the authoritative sync message for
  divergent linked members, and a direct strip for unlinked holders. The direct strip
  is the only place this addon removes Discord roles outside the vendor sync message,
  so it carries its own pure decision. (It also carried its own wiring pin, since
  removed — see the note above.)
- Unlinked-holder strips cannot be recorded against a member, because there is no
  member. They are written to the addon's integration log with a reason and appear in
  Discord's native audit log.
- The sweep only strips and never kicks, so with the assoc-kick option on it is a
  weaker enforcement than the event intends: a role-less guest rather than a removed
  member. It is still stricter than today's dropped-strip state. Making the sweep kick
  is a separate later decision.
- Hardening the disconnect handler (retry, role hierarchy, error reporting) stays
  available as a separate improvement. It is not needed for correctness, because the
  sweep backstops the tail, and it is out of scope for #157.
- Reverting is disabling the addon, which returns disconnect cleanup to the vendor's
  event-only behaviour and its dropped-strip tail.
