# A separate user:groups scope, not a wider user:read

Stock XenForo 2.3 only exposes `user_group_id`/`secondary_group_ids` over the
API to users holding the `user` admin permission, which blocks group-based
RBAC for satellite services
(MediaWiki SSO being the first). We considered the obvious fix — make
`user:read` on this forum include the visitor's own groups — and rejected it:
that silently changes what a stock scope means on this install, is invisible
in the ACP scope list and on the user consent screen, and hands group
visibility to every present and future client that asks for basic identity.
Instead the add-on registers a dedicated `user:groups` scope, matching how
sibling 7Cav add-ons extend the API (`donation:read` from DonationGoalSync,
`nf_discord:read`):
the grant is explicit, auditable, and removable if upstream ever exposes
groups natively.

## Consequences

- Every client that needs groups must request `user:groups` explicitly.
- Stock quirk remains: admin accounts see groups under `user:read` alone, so
  integrators must not infer field availability from admin-account testing.
