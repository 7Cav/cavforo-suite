# ADR-0001: Both overrides ride one class extension

- **Status:** Accepted
- **Date:** 2026-07-21
- **Issues:** implementation decision while building #148

## Context

The fix for #148 is two independent overrides on the same vendor class,
`NF\Discord\ApiMessage\SyncUser`: an eviction on `dispatch()` and the claim on
`syncRoles()`. Neither needs the other. Disabling either one on the dev stack
leaves the other working, which was checked both ways.

That independence suggested registering two class extensions, one per half, so an
operator could turn a half off from the admin panel. XenForo supports it: several
extensions can share a `from_class` and chain in `execute_order`. The arrangement
was built and verified working.

It does not survive the suite's own tooling. `tools/check-data-consistency.php`
matches each `_output/class_extensions/` item to its `_data` record by
`from_class`, keying a lookup array on that value. Two records sharing a
`from_class` collapse to one, and the second item is then compared against the
first record's `to_class` and reported as corrupted. CI fails on a perfectly valid
export.

## Decision

One class extension, `Cav7\DiscordSyncPatch\NF\Discord\ApiMessage\SyncUser`,
carrying both method overrides.

The tooling limitation is real and belongs to the tool, not to this addon, so it
is left in place rather than worked around by widening a shared script for one
addon's convenience.

## Consequences

- Reverting is per addon, not per half: disabling `Cav7/DiscordSyncPatch` returns
  the integration to stock behavior. A half can still be removed on its own, but
  that is a code change rather than an admin-panel toggle.
- `tests/WiringTest.php` pins the single registration and both overrides
  separately, so losing either half fails the build.
- If the consistency checker is ever taught to match `_data` extensions on the
  `from_class` and `to_class` pair, splitting the halves into two registrations
  becomes available again. Nothing else about the fix would need to change.
