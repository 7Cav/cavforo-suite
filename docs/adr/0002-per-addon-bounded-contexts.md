# 0002 - One bounded context per addon

## Status

Accepted

## Context

The suite holds several addons that are developed, versioned, and released independently. Several arrived from their own repositories already carrying their own ADRs and domain notes. Their vocabularies overlap on the shared 7Cav domain (ranks, roster, milpac records, user groups, API scopes), but each addon also has language and decisions that only make sense inside it, for example DotTokenFix's tokenizer behavior or AvatarByRole's avatar variant rules.

We need to decide whether domain docs and ADRs are one global set or split per addon.

## Decision

Treat each addon as its own bounded context. A `CONTEXT-MAP.md` at the repo root records the layout. Each addon owns its domain glossary (`CONTEXT.md`) and its decision records (`docs/adr/`) under `src/addons/Cav7/<AddonId>/`. Decisions that cut across addons (the monorepo conventions, build and release, the shared `Cav7/Core`) live in the root `docs/adr/`.

These docs are created lazily, when a term or decision actually needs pinning down, not upfront.

## Consequences

- Addons keep the ADRs they arrive with, in their own directory. Numbering never collides, because each addon has its own sequence.
- A contributor reading one addon sees that addon's language and decisions without wading through the others.
- Consumer skills detect the layout from `CONTEXT-MAP.md` and read the root plus the relevant addon's docs.
- When code moves into `Cav7/Core`, the decision behind the move cuts across addons and belongs in the root `docs/adr/`.
