# AGENTS.md

Guidance for agents and contributors working in this repo. Start with [README.md](README.md), [CONTRIBUTING.md](CONTRIBUTING.md), and [docs/addon-format.md](docs/addon-format.md).

## Agent skills

### Issue tracker

Issues live in this repo's GitHub Issues, and external PRs are a triage surface too. Use the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five triage roles map to `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, and `wontfix`. See `docs/agents/triage-labels.md`.

### Rejected requests

Enhancements closed as `wontfix` leave a record in `.out-of-scope/`, one file per concept. Read it before triaging a request, so a prior rejection is surfaced rather than re-litigated.

### Domain docs

Multi-context: a suite-wide context plus one per addon. See `docs/agents/domain.md` and `CONTEXT-MAP.md`.

## Writing tests

Tests assert on behaviour. Call the code with real inputs and assert on what comes back; if a refactor that preserves behaviour would break the test, the test is wrong.

Do not test source code as text. Reading a `.php` file and asserting over it with `preg_match` or `str_contains` — that a call appears, that a signature matches, that statements are in a given order, that a variable is spelled a certain way — is not a test of the seam. It fails when the code is tidied, passes when the code is broken, and leaves the seam uncovered while looking covered. A whole layer of these was removed in July 2026; two of the bugs it was hiding were an inverted lookup map and a query that made an addon go permanently silent, both of which passed the full suite.

A seam that needs a live XenForo is not made testable by this. Verify it on a dev stack instead — that is also the only way to catch vendor drift, which no test here can see, since every one of them compares our code against our own expectations. Where a structural fact is worth enforcing and is the same for every addon, put it in `tools/` so it runs against all of them.

Full rule in [CONTRIBUTING.md](CONTRIBUTING.md#what-belongs-in-ci-and-what-does-not). Some ADRs still describe the removed tests as live guarantees; they carry a dated note, and are history either way.

## Writing docs

Keep transient state out of committed docs. Progress, status, who is working on what, and whether something is "coming soon" belong in the issue tracker, which is the one place that stays current. A doc should describe what is true of the code and layout as they stand, so it only goes stale when the code does. Two habits that follow from this:

- Do not give a thing a per-item "status" column or a "tracked in #N" note. If a reader needs the live state, the issue tracker has it; a number copied into prose is stale the moment the issue moves.
- State each fact once, in one home, and link to it from elsewhere. The addon list lives in `README.md`; other docs point at it rather than repeat it, so the two cannot drift apart.

ADRs are the deliberate exception. They are dated, point-in-time records of a decision and are not kept current; read them as history, not as a description of the code today.
