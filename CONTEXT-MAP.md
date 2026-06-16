# Context map

This repo is multi-context. Each addon is its own bounded context with its own domain language and decisions, and there is a suite-wide context for what cuts across them. The reasoning is in [docs/adr/0002-per-addon-bounded-contexts.md](docs/adr/0002-per-addon-bounded-contexts.md).

## Suite-wide context

- Glossary: `CONTEXT.md` at the repo root, for terms shared across addons (created when shared terms need pinning down).
- Decisions: `docs/adr/`, for choices that cut across addons (layout, build and release, `Cav7/Core`).

## Per-addon contexts

Each addon under `src/addons/Cav7/<AddonId>/` owns:

- `CONTEXT.md`: its domain glossary.
- `docs/adr/`: decisions specific to that addon, including any it arrived with when migrated.

| Context | Path |
|---|---|
| Suite-wide | `docs/adr/`, `CONTEXT.md` |
| `Cav7/Core` | `src/addons/Cav7/Core/` |
| Each addon | `src/addons/Cav7/<AddonId>/` |

The addons themselves are listed in [README.md](README.md); this map only points at where each context lives, so it does not repeat that list.

These docs are created when terms or decisions actually arise (see [docs/agents/domain.md](docs/agents/domain.md)), not upfront, so most per-addon `CONTEXT.md` and `docs/adr/` paths will not exist until someone records something for that addon.
