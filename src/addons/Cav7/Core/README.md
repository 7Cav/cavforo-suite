# Cav7/Core

The shared library for the suite. It is a placeholder right now and ships no code.

Once the addons are all in this repo, the code they have in common gets pulled in here so each addon can depend on one source of truth instead of its own copy. Likely first candidates, based on what already repeats across the addons:

- The 7Cav rank and user-group taxonomy (group ids and rank slugs), currently hard-coded in `AvatarByRole`, `ApiKeyManager`, and the roster addons.
- NF/Rosters lookups (resolving a member's roster entry, rank, and gamertag), currently duplicated between `RosterSearch` and `RosterAudit`.
- The API-scope and group-gating pattern shared by `ApiKeyManager` and `UserGroupsScope`.

Extraction is tracked separately from the migration. See issue #1 for the overall plan.

The XenForo floor is set to 2.2.0+ so any addon in the suite can depend on Core regardless of its own floor.
