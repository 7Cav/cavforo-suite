# Domain docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase.

This repo is multi-context. Each addon is its own bounded context, plus there is a suite-wide context for what cuts across them. See `CONTEXT-MAP.md` at the root and `docs/adr/0002-per-addon-bounded-contexts.md`.

## Before exploring, read these

- **`CONTEXT-MAP.md`** at the repo root. It names the contexts and where each one's `CONTEXT.md` and `docs/adr/` live.
- **`CONTEXT.md`** at the repo root, for suite-wide vocabulary, if it exists.
- **`src/addons/Cav7/<AddonId>/CONTEXT.md`** for the addon you are working in, if it exists.
- **`docs/adr/`** at the root for suite-wide decisions, plus **`src/addons/Cav7/<AddonId>/docs/adr/`** for decisions specific to the addon you are touching.

If any of these files don't exist, **proceed silently**. Don't flag their absence; don't suggest creating them upfront. The producer skill (`/grill-with-docs`) creates them lazily when terms or decisions actually get resolved.

## File structure

```
/
├── CONTEXT-MAP.md                          ← names every context
├── CONTEXT.md                              ← suite-wide glossary (lazy)
├── docs/adr/                               ← suite-wide decisions
│   ├── 0001-monorepo-conventions.md
│   └── 0002-per-addon-bounded-contexts.md
└── src/addons/Cav7/
    ├── SteamChecker/
    │   ├── CONTEXT.md                       ← addon glossary (lazy)
    │   └── docs/adr/                        ← addon-specific decisions
    └── <AddonId>/
        ├── CONTEXT.md
        └── docs/adr/
```

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in the relevant `CONTEXT.md`, the addon's first, then the suite-wide one. Don't drift to synonyms the glossary explicitly avoids.

If the concept you need isn't in the glossary yet, that's a signal. Either you're inventing language the project doesn't use (reconsider), or there's a real gap (note it for `/grill-with-docs`).

## Flag ADR conflicts

If your output contradicts an existing ADR, whether suite-wide or the addon's own, surface it explicitly rather than silently overriding:

> _Contradicts ADR-0001 (monorepo conventions), but worth reopening because..._
