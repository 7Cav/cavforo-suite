# AGENTS.md

Guidance for agents and contributors working in this repo. Start with [README.md](README.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [docs/addon-format.md](docs/addon-format.md).

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues, and external PRs are a triage surface too. Use the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five triage roles map to `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, and `wontfix`. See `docs/agents/triage-labels.md`.

### Domain docs

Multi-context: a suite-wide context plus one per addon. See `docs/agents/domain.md` and `CONTEXT-MAP.md`.

## Writing docs

Keep transient state out of committed docs. Progress, status, who is working on what, and whether something is "coming soon" belong in the issue tracker, which is the one place that stays current. A doc should describe what is true of the code and layout as they stand, so it only goes stale when the code does. Two habits that follow from this:

- Do not give a thing a per-item "status" column or a "tracked in #N" note. If a reader needs the live state, the issue tracker has it; a number copied into prose is stale the moment the issue moves.
- State each fact once, in one home, and link to it from elsewhere. The addon list lives in `README.md`; other docs point at it rather than repeat it, so the two cannot drift apart.

ADRs are the deliberate exception. They are dated, point-in-time records of a decision and are not kept current; read them as history, not as a description of the code today.
