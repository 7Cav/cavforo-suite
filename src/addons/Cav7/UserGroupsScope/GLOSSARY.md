# XenForo Group Membership over OAuth

In-house XenForo 2.3 add-on that lets OAuth clients read the authenticated
user's own group membership, so XenForo groups can act as the source of truth
for role-based access in satellite services (first consumer: MediaWiki SSO).

## Language

**Group membership**:
A user's primary group plus their secondary groups, as held by XenForo. The
authoritative input for role-based access decisions in satellite services.
_Avoid_: roles, ranks (those are satellite-side mappings of group membership)

**user:groups (scope)**:
The OAuth scope that grants a client read access to the authenticated user's
own group membership — never anyone else's. Works independently of
`user:read`, and applies equally to OAuth access tokens and scoped API keys.
_Avoid_: folding group visibility into `user:read` (stock scopes keep stock
semantics)

**WAG (Wiki Admin Group)**:
The team that administers the wiki, represented in XenForo by the existing
`Position - WAG HQ` and `Position - WAG Staff` groups. The first concrete
group-membership-to-role mapping a satellite service will consume.
_Avoid_: "Wiki Admin Group" as a literal XenForo group name (the groups are
named `Position - WAG *`)

**Satellite service**:
An application that authenticates its users against XenForo via OAuth and
derives its own access roles from group membership. MediaWiki is the first.
_Avoid_: client app (ambiguous with OAuth "client", which is the registered
credential, not the service)

## Flagged ambiguities

- **Admin tokens see groups without `user:groups`** — stock XenForo includes
  group membership in `/api/me` for users with the `user` admin permission
  under `user:read` alone. This is upstream behavior, not part of this
  add-on's contract. Integrators must request `user:groups` and never infer
  field availability from admin-account testing.

## Example dialogue

> **Dev:** The wiki needs to know if someone is an officer. Do I read their
> rank?
>
> **Expert:** No — you read their group membership through the `user:groups`
> scope, and the wiki maps group IDs to its own roles. "Officer" only exists
> on the wiki side of that mapping.
>
> **Dev:** Can the wiki look up another member's groups to build a roster?
>
> **Expert:** Never. The scope only ever exposes the membership of the user
> who authorized the token. Rosters are a different problem.
