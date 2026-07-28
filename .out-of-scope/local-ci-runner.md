# One command that runs the whole CI check set

This repo has no aggregate `ci`/`check` entry point — no `tools/ci.sh`, no
`Makefile` target, no `composer` script. `tools/` holds the individual checks and
`.github/workflows/ci.yml` composes them. Running the full set before a push
means running them yourself, or pushing and reading the result.

## Why this is out of scope

The request fuses two problems with very different prices.

**Nobody knows what the full check set is** is a knowledge problem, and it is
already answered. `CONTRIBUTING.md` enumerates what CI runs in prose — the lint
sweep, `addon.json` and `_data` validation, the `_output`/`_data` consistency
check, the packaging dry run, the real package-and-read-the-archive, and the
README catalog check — and `tools/README.md` documents each script behind them.

**Any local copy of the list drifts from CI** is a duplication problem, and it is
the one that makes this expensive. A committed runner is a second home for the
check set, which runs straight into the rule `AGENTS.md` already states:

> State each fact once, in one home, and link to it from elsewhere. The addon
> list lives in `README.md`; other docs point at it rather than repeat it, so the
> two cannot drift apart.

There is a house pattern for a fact that genuinely must live in two places, and
it is not a mirror. When the addon list needed one home and could not have it,
what got written was `tools/check-readme-catalog.php`, which fails CI on drift.
Enforce agreement mechanically, or do not have the second copy.

Applying that here leaves two options and no third:

- **In pattern.** `ci.yml` stops carrying its own step list and calls the entry
  point. Its four jobs are a partition of the check set, so the entry point has
  to expose that partition, and CI's job structure becomes part of a script's
  public interface. The bare whole-repo form needs addon discovery, so the
  `[A-Za-z0-9_]` rule that `ci.yml` and `tools/check-readme-catalog.php` already
  state twice gets stated a third time, or gets extracted — and extracting it
  moves an injection guard out of the workflow file, where a reviewer can
  currently read it inline next to the interpolation it protects. Holding the
  workflow to the entry point at all needs a check that parses `ci.yml`, since
  nothing otherwise fails when a step drifts back in.
- **Out of pattern.** A small script that mirrors the list, drifts silently, and
  contradicts the rule above.

Neither earns it, because of what the drift actually costs.

## The drift is fail-safe, which is what settles it

CI is the gate, and CI is never the copy that is wrong. A runner that has fallen
behind gives a false green *locally* and the true red on push. The worst case of
this whole class of bug is a push and a wait — which is the situation today. The
other direction, local red against CI green, costs an unnecessary look.

So the expensive option buys a guarantee against a failure mode whose worst
outcome is the status quo, and the cheap option buys convenience by breaking a
stated rule. The check set runs clean over all 16 addons in about 18 seconds, and
a full CI run finishes in about 50. The gap being closed is small in the first
place.

## What answers the question instead

`CONTRIBUTING.md` for what CI checks and why, `tools/README.md` for each script
and its arguments, and `.github/workflows/ci.yml` for the composition. Push and
read the result — that answer is authoritative, and no local one is.

What is rejected is a committed entry point that runs the set. Keeping the prose
enumeration accurate as checks are added is not, and neither is the reverse
direction: if `ci.yml` ever grows logic that belongs in a script, it belongs in
`tools/` on its own merits rather than as a step toward this.

## Prior requests

- #202 — "Nothing runs the full CI check set locally, so everyone rebuilds it by
  hand"
