# 7Cav - User Groups Scope

A XenForo 2.3 add-on that adds a `user:groups` OAuth scope so a client can
read the authenticated user's own group membership through `GET /api/me`.

Stock XenForo 2.3 only includes `user_group_id` and `secondary_group_ids` in
API responses when the authenticated user holds the `user` admin permission
(or the request uses a super-user key). That makes group-based access control
impossible for normal user tokens: an OAuth client can learn who a user is,
but not what groups they belong to. This add-on closes that gap for the
user's own account only.

Terms used below are defined in [CONTEXT.md](CONTEXT.md). The decisions behind
the behaviour are in [docs/adr/](docs/adr/).

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
- The API core refuses banned, rejected, and disabled users before this add-on
  runs, so a revoked member cannot fetch their groups with their own token or
  key. (A super-user key acting on a banned user's behalf bypasses that check,
  as it bypasses all permission checks.)

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

Requires XenForo 2.3.0+. The add-on has no options and makes no schema
changes. Uninstalling removes the scope, the class extension, and the phrase
completely.

## Note for integrators

Do not test scope behavior with an account that holds the `user` admin
permission. Stock XenForo shows that account the group fields under
`user:read` alone, which will mislead you about what regular users receive.
Always request `user:groups` when you need groups.

## Provenance

Imported from https://github.com/7Cav/UserGroupsScope at commit 15d267bd2db8314c563b1786ed6eb583c2673ae7.
