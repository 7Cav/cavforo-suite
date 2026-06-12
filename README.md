# 7Cav - User Groups Scope

A XenForo 2.3 add-on that adds a `user:groups` OAuth scope, letting a client
read the authenticated user's own group membership through `GET /api/me`.

## Why

Stock XenForo 2.3 only includes `user_group_id` and `secondary_group_ids` in
API responses when the authenticated user holds the `user` admin permission
(or the request uses a super-user key). That makes group-based access control
impossible for normal user tokens: an OAuth client can learn who a user is,
but not what groups they belong to. This add-on closes that gap, and
only for the user's own account.

The first consumer is MediaWiki SSO via PluggableAuth/WSOAuth, which maps
XenForo group IDs to wiki groups.

## Behavior

- Registers a `user:groups` API scope, grantable to OAuth clients and
  selectable on API keys.
- When a request's OAuth token or API key carries `user:groups` and the user
  being rendered is the authenticated user, `GET /api/me` includes
  `user_group_id` (int) and `secondary_group_ids` (int array), with the same
  names and types an admin sees.
- The scope works independently of `user:read`. A `user:groups`-only token
  gets a minimal identity stub plus the group fields. Clients that want the
  full profile should request `user:read user:groups`.
- The add-on never exposes another user's groups, regardless of scopes, and
  does not include the moderation fields that sit next to the group fields in
  the stock admin response (`user_state`, `is_discouraged`).

```json
{
    "me": {
        "user_id": 2,
        "username": "Doe.J",
        "user_group_id": 2,
        "secondary_group_ids": [217, 227]
    }
}
```

The example is trimmed to the fields this add-on adds. Real responses also
carry XenForo's standard user fields (avatar URLs, `can_*` booleans, and so
on), and group IDs are specific to each installation.

## Install

Copy the add-on to `src/addons/Cav7/UserGroupsScope` and run:

```
php cmd.php xf-addon:install Cav7/UserGroupsScope
```

Requires XenForo 2.3.0+. No options, no schema changes; uninstalling removes
the scope, class extension, and phrase with no residue.

## Verified behavior

Acceptance-tested on XenForo 2.3.10 (June 2026) against `GET /api/me` with a
non-admin user:

| Credential | Scopes | Group fields |
| --- | --- | --- |
| OAuth token | `user:read` | absent |
| OAuth token | `user:read user:groups` | present |
| OAuth token | `user:groups` | present (stub identity) |
| User API key | `user:read` | absent |
| User API key | `user:read user:groups` | present |
| OAuth token | `user:read user:groups`, fetching another user via `/api/users/{id}` | absent |

The API core refuses banned, rejected, and disabled users before this add-on
runs, so a revoked member cannot fetch their groups with their own token or
key. (A super-user key acting on a banned user's behalf bypasses that check,
as it bypasses all permission checks.)

## Note for integrators

Do not test scope behavior with an account that holds the `user` admin
permission — stock XenForo shows it the group fields under `user:read`
alone, which will mislead you about what regular users receive. Always
request `user:groups` when you need groups.
