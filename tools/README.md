# tools

Shared scripts for building, testing, and releasing addons. They back the CI and
release workflows in [`.github/workflows/`](../.github/workflows/) and work the
same way when you run them by hand.

## Scripts

### `run-tests.sh <AddonId>`

Runs an addon's standalone PHP tests (`tests/*.php`). Each test is a
self-contained script that exits non-zero on failure, so there is no framework
and no XenForo. Addons without a `tests/` directory are a no-op. Needs only
`php`.

```
tools/run-tests.sh SteamChecker
```

### `run-tools-tests.sh`

Runs the repo-level tool tests (`tools/tests/*.php`), which pin the scripts in
this directory (`package-web-assets.php`, `check-data-consistency.php`, ...)
against failure modes the live build's happy path does not exercise. Like the
addon tests, each is a self-contained script that exits non-zero on failure, so
there is no framework. It discovers every `tools/tests/*.php`, so a new one is
picked up with no change here. Takes no arguments. CI runs this in its own job.

The constraint on these is **no XenForo**, not "only `php`". Most need nothing
but `php`; `package-addon-test.php` runs the real packaging script, so it also
needs the `bash`, `git` and `zip` that script needs, plus PHP's `zip` extension
to read the archive back. A host missing any of those cannot build a release
either, so the test fails there rather than skipping — a skip reads as a pass.

```
tools/run-tools-tests.sh
```

### `build.sh <AddonId>`

Builds a **local build** through XenForo's own `xf-addon:build-release`: it
exports `_data/` from the database, then packages the zip into the addon's
`_releases/` directory. This needs a working XenForo install.

A local build is a testing artifact, not something to install a board from. It
ships the addon's `tests/`, `docs/` and `CONTEXT.md`, deliberately and
unchecked; `package-addon.sh` below is the release build, and the only
distribution channel. The reasoning is in
[ADR 0005](../docs/adr/0005-the-release-build-is-the-distribution-channel.md).

Point it at your install with one of:

```
XF_ROOT=/path/to/xenforo                              tools/build.sh SteamChecker
XF_CMD="docker exec xenforo-staging-fpm php cmd.php"  tools/build.sh SteamChecker
```

Your repo's `src/addons/Cav7` must be reachable by that install (the symlink
setup in [CONTRIBUTING.md](../CONTRIBUTING.md)), so the export writes back into
this repo.

### `package-addon.sh <AddonId> [--ref <git-ref>] [--out <file.zip>]`

Builds the **release build** from committed files, with no XenForo install —
the zip CI checks, the release workflow publishes, and a board installs from. It
reproduces the `upload/src/addons/Cav7/<Id>/` layout that
`xf-addon:build-release` emits, so the zip installs through the admin panel's
"Install/upgrade from archive". Because it archives committed content only,
`_data/` must already be exported and committed. Dev-only paths (`_output/`,
`tests/`, `docs/`, `CONTEXT.md`, ...) are left out, and so are `_files/` and
`build.json`, which XenForo also keeps out of `upload/src/addons/...`.

`package-addon.sh` always runs `package-web-assets.php` (below): it copies web
assets when a `build.json` declares them, and verifies every `<xf:js>` the addon
owns resolves at the web root regardless of whether a `build.json` is present.
The copy and placement match what `xf-addon:build-release` does. The `<xf:js>`
resolution check is an extra guard this path adds, not something XenForo runs at
build time. It needs `php` on the PATH.

**The two paths do not produce matching zips, and are not meant to.** Parity
was never achievable: the local build writes `hashes.json`, the file-health
manifest, and this path writes none — the zip installs fine without it. What is
asserted instead is exclusion, on this path only, by
`tools/tests/package-addon-test.php`: no addon's release zip carries a dev-only
path, and no dot- or underscore-prefixed top-level entry ships except `_data`.
The local build ships all of those. [ADR 0005](../docs/adr/0005-the-release-build-is-the-distribution-channel.md)
covers why that is left alone.

The rest of what this path does not reproduce, as it has come up rather than
exhaustively. It ignores two `build.json` keys, `exec` and `rollup`, because
`package-web-assets.php` reads `additional_files` and `minify` and nothing else;
no addon declares either key today, and an `exec` that pruned something would
need that path adding to the `excludes` array here to have the same effect.
There is no install-root fallback for an `additional_files` path with no
`_files/` backing, and no `_no_upload` relocation. The exclusion lists differ in
both directions: `package-addon.sh` carries a fixed `excludes` array, so
`_build/`, `_no_upload/`, `_releases/` and `_stubs/` are not dropped here the way
`ReleaseBuilderService::getExcludedDirectories()` drops them, and it names two
dotfiles where XenForo's `isExcludedFileName()` strips every dotfile but
`.htaccess` — though only leaf files, since its copy loop walks `CHILD_FIRST`
and a dot-*directory*'s contents are visited before the entry that would exclude
them. No addon carries anything in that first group today; one that did needs the
array widening.

```
tools/package-addon.sh SteamChecker
```

### `package-web-assets.php <addon-src-dir> <upload-root> <addon-id>`

Reproduces XenForo's `build.json` web-asset handling for the no-XenForo
packaging path, and is called by `package-addon.sh` for every addon. It reads
`build.json`, copies each `additional_files` path out of `_files/` to the
matching path under the upload web root (so `_files/js/Cav7/MilpacMention`
becomes `upload/js/Cav7/MilpacMention`), and writes the `<name>.min.js` that a
`minify` entry asks for. It then checks that every `<xf:js src>` the addon owns
(its `addon="..."` attribute) resolves at that web root, including the
`.min.js` a `min="1"` include requests. An addon with no `build.json` and no
owned `<xf:js>` is a no-op.

The main thing it does not reproduce is real minification: XenForo's Closure
Compiler is not on the CI and release runners, so the `.min.js` is a copy of the
source. It is valid, working JS at the path a `min="1"` include requests, so the
asset serves; it is just not size-optimised. It also diverges from XenForo on
`additional_files`: there is no install-root fallback for a declared path, and a
path with no (or an empty) `_files` backing is an error here rather than silently
skipped.

```
php tools/package-web-assets.php src/addons/Cav7/MilpacMention build/upload Cav7/MilpacMention
```

### `validate-addon.php <addon-dir>` and `check-data-consistency.php <addon-dir>`

The static checks CI runs in place of an install. `validate-addon.php` checks the
`addon.json` shape, that every `_data/*.xml` is well-formed, and that
`_data/class_extensions.xml` holds its rows in the canonical order
[ADR 0004](../docs/adr/0004-class-extension-order-is-case-folded.md) defines. It
names the add-on and the two rows that are out of order relative to each other,
and rewrites nothing: a file that fails is reconciled by re-exporting it, not by
hand.
`check-data-consistency.php` cross-checks the `_output/` tree and the `_data/`
bundle against each other and fails on drift, which catches either side not
being re-exported after a change — including a data type that reached `_data/`
but was never exported to `_output/`, and an addon whose `_output/` tree is gone
while `_data/` still holds records. Both need only `php`.

How hard it looks depends on the type, and the report says which it used for
each, so a weakly-checked type is visible rather than assumed covered:

| strength | what is compared | types |
| --- | --- | --- |
| content-checked | every field the mapping names, or the whole body where that is the payload | `class_extensions`, `phrases`, `templates`, `options`, `template_modifications` |
| id-checked | the `_output` filename against the id in `_data`, and nothing inside the record | `option_groups` |
| count-checked | the record count only | `routes`, `code_event_listeners`, `cron_entries`, `admin_navigation`, `api_scopes` |

It also verifies each type directory's `_metadata.json` — the index
`xf-dev:export` writes beside the items — so that every item is indexed, every
entry names a file that exists, and every hash is the md5 of that file with
carriage returns stripped. Comparing the two trees says they agree with each
other; two hand-edited trees agree with each other and with nothing else, and a
stale hash is the only trace that leaves. Neither tree is safe to hand-edit,
which is what [CONTRIBUTING.md](../CONTRIBUTING.md) already asks.

```
php tools/validate-addon.php src/addons/Cav7/SteamChecker
php tools/check-data-consistency.php src/addons/Cav7/SteamChecker
```

### `sync-addon-data.php <addon-dir> --to-output|--to-data`

Derives an addon's `_output/` tree from its `_data/` bundle, or the other way
round, with no XenForo install and no database. This is the supported path for a
change that is nothing but XenForo data: edit one tree, derive the other, commit
both. Needs only `php`.

```
php tools/sync-addon-data.php src/addons/Cav7/SteamChecker --to-output
php tools/sync-addon-data.php src/addons/Cav7/SteamChecker --to-data
```

It reimplements XenForo's own exporters rather than wrapping them, so it is only
correct while it agrees with them byte for byte. `tools/tests/sync-addon-data-test.php`
holds that down by deriving both trees for every committed addon and comparing
against what is in the repo.

Three things worth knowing before you trust a derived tree:

- **It cannot see a type no addon here commits.** The type table covers the
  eleven types that appear in this repo. A type XenForo adds later, or one this
  suite starts using, needs adding to that table; `_data` files for the other
  types are still written, as the empty containers `xf-addon:export` emits.
- **`_data` is lossy in two known places, and the tool fills them from XenForo's
  entity defaults.** `admin_navigation.super_admin_only`,
  `api_scopes.usable_with_oauth_clients` and `option_groups.advanced` exist in
  `_output` but have no `_data` attribute at all. Every committed record happens
  to hold the default, so the round trip is exact today; a record that set one of
  them to a non-default value would lose it going through `_data`. That is a
  property of XenForo's export format, not of this script.
- **Record order comes from the database, so it comes from the column type.**
  Nearly every id column these exports sort on is `varbinary` and orders by
  bytes. `xf_class_extension.from_class`/`to_class` are the exception —
  `varchar` under `utf8mb4_general_ci`, which folds case — which is why
  [ADR 0004](../docs/adr/0004-class-extension-order-is-case-folded.md) scopes its
  case-folded rule to class extensions and to nothing else.

### `discord-resync-cooldown-check.sh`

The odd one out here: every other script in this directory runs without a
XenForo install,
and this one needs a live XenForo dev stack with NF/Discord and
`Cav7/DiscordSyncPatch` installed. CI does not run it. It is here rather than in
the addon's `tests/` because `run-tests.sh` runs everything in there with bare
`php`, and this cannot.

It presses the resync button twice inside the cooldown window and checks the
second press is refused — once as a member holding XenForo's
`general:bypassFloodCheck` permission and once as a member without it. On this
forum that permission reaches almost every member who can see the button, so the
holder is the case that matters; see the addon's ADR-0007.

It builds its own user group, members and Discord credentials, lets XenForo build
the permission cache from the group rather than editing the cache, and removes all
of it afterwards. It refuses to start if a previous run left its dummy
credentials behind. Point it at your stack with `XENFORO_DEV_STACK` if it is not
at `~/srv/xenforo-dev`.

```
tools/discord-resync-cooldown-check.sh
```
