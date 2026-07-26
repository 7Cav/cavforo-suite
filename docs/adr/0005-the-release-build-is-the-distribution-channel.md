# 0005 - The release build is the distribution channel; the local build is not

## Status

Accepted.

## Context

Two paths turn an add-on into a zip and they do not agree about what goes in it.

`tools/package-addon.sh` archives committed files with no XenForo install. It carries its own `excludes` array, which drops `tests/`, `docs/`, `CONTEXT.md` and `.out-of-scope/`. CI's build check and the release workflow both use it, and the zip it produces is what GitHub Releases attach.

`tools/build.sh` hands the job to XenForo's `xf-addon:build-release`. That builder's excluded-directory list is fixed and short — `_build`, `_files`, `_no_upload`, `_output`, `_releases`, `_stubs`, `.git`, `.svn` — with no `tests`, no `docs` and no `CONTEXT.md`, so it ships all three. It also ships `.out-of-scope/`, despite dropping every dotfile: the copy loop walks `CHILD_FIRST`, so a dot-directory's children are visited before the directory entry that would have marked them excluded. It needs a working XenForo install, so it never runs in CI or the release workflow.

The divergence was filed as a bug against the second path, on the reading that it is the canonical build — which is what `CONTRIBUTING.md` and `tools/README.md` called it. That reading had the risk backwards. The zip that reaches a board comes from the first path, and nothing asserted its exclusions: deleting one word from the `excludes` array put test files into eleven add-ons' zips with the whole suite green.

The risk is specific rather than general. XenForo's only protection for `src/` is a `src/.htaccess` containing `Deny from all`, which is Apache syntax and which nginx ignores. On an nginx-served board a shipped `tests/*.php` is an unauthenticated, executable endpoint — verified against the dev stack, where fetching one returns 200 and runs it. `docs/` and `CONTEXT.md` are inert by comparison and ride along because it is the same exclusion either way.

## Decision

The release build is the only supported distribution channel, and its exclusions are asserted, once, for every add-on: `tools/tests/package-addon-test.php` packages each add-on with the real script and reads the archive.

The local build is a testing artifact. It is not held to the same content standard, and it is expected to ship dev-only paths.

"Canonical" stays attached to *export* — `xf-addon:export` and `xf-dev:export` remain the source of truth for `_data` and `_output`. It is retired for builds, where it had come to name the one artifact nobody installs from.

## Considered Options

Bringing the local build to parity was costed and rejected. XenForo reads a build manifest per add-on, so it would mean roughly sixteen near-identical `build.json` files, each with an `exec` entry pruning the staged paths — the mechanism works, and was verified on the dev stack against a real build. Alongside them it would need a repo-level check that the manifests actually prune, because `execCmds()` passes each entry to `passthru()` and discards the exit status, so a manifest that prunes nothing fails silently.

That is three moving parts guarding an artifact that reaches nobody. It was declined.

The stated goal in the original issue — that both paths produce the same set of files — was declined for a second reason: it is not achievable and never was. The local build writes `hashes.json`, the file-health manifest, and the release build does not. A literal parity assertion fails on day one for every add-on, and greening it means encoding the exceptions, at which point it is an exclusion check wearing a parity costume.

## Consequences

- A locally built zip carries the add-on's `tests/`, `docs/` and `CONTEXT.md`. That is intended. Installing a board from one is not a supported route.
- The exclusion is stated twice on purpose: `package-addon.sh` implements it, `package-addon-test.php` specifies it. A test that read the `excludes` array would shrink with it and pass forever, which is exactly the failure it exists to catch. Adding a legitimate exclusion means editing both, and forgetting the test is a red build.
- The tools tests no longer need only `php`. Testing the packaging path means running it, which brings `bash`, `git` and `zip`. The binding constraint on CI was never "only php" — it is that CI cannot run a XenForo install. A host missing `zip` cannot build a release at all, so a red test there is telling the truth, and it must fail rather than skip: a skip reads as a pass.
- A dot- or underscore-prefixed top-level entry does not ship, with `_data` the named exception. This is a rule and not a list of known names, so a future `_scratch/` fails until somebody excludes it. It cost one rename to adopt: `EnlistmentDefaults/_assets` is runtime data the add-on attaches to milpacs and must ship, so it became `assets/` rather than a second exception. All six directories XenForo itself excludes are underscore-prefixed, which is what made the prefix worth honouring.
- Nothing watches for a divergence between the two paths in some other dimension — a XenForo release relocating files, or a build manifest that stages rather than prunes. That promise was in `tools/README.md` and was not kept by anything; it has been withdrawn rather than left standing.
- `tools/build.sh` builds nothing anyone installs, so its output is unchecked. If it ever becomes a distribution route, this ADR is what has to change first.
