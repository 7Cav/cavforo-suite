# Context map

This repo is multi-context. Each addon is its own bounded context with its own domain language and decisions, and there is a suite-wide context for what cuts across them. The reasoning is in [docs/adr/0002-per-addon-bounded-contexts.md](docs/adr/0002-per-addon-bounded-contexts.md).

## Suite-wide context

- Glossary: `CONTEXT.md` at the repo root, for terms shared across addons (created when shared terms need pinning down).
- Decisions: `docs/adr/`, for choices that cut across addons (layout, build and release, `Cav7/Core`).

## Per-addon contexts

Each addon under `src/addons/Cav7/<AddonId>/` owns:

- `CONTEXT.md`: its domain glossary.
- `docs/adr/`: decisions specific to that addon, including any it arrived with when migrated.

| Context | Path | Status |
|---|---|---|
| Suite-wide | `docs/adr/`, `CONTEXT.md` | active |
| `Cav7/Core` | `src/addons/Cav7/Core/` | placeholder |
| `Cav7/SteamChecker` | `src/addons/Cav7/SteamChecker/` | pending migration (#3) |
| `Cav7/ApiKeyManager` | `src/addons/Cav7/ApiKeyManager/` | pending migration (#4) |
| `Cav7/RosterAudit` | `src/addons/Cav7/RosterAudit/` | pending migration (#5) |
| `Cav7/RosterSearch` | `src/addons/Cav7/RosterSearch/` | pending migration (#6) |
| `Cav7/UserGroupsScope` | `src/addons/Cav7/UserGroupsScope/` | pending migration (#7) |
| `Cav7/DotTokenFix` | `src/addons/Cav7/DotTokenFix/` | pending migration (#8) |
| `Cav7/AvatarByRole` | `src/addons/Cav7/AvatarByRole/` | pending migration (#9) |

These docs are created when terms or decisions actually arise (see [docs/agents/domain.md](docs/agents/domain.md)), not upfront, so most per-addon `CONTEXT.md` and `docs/adr/` paths will not exist until an addon is migrated and someone records something.
