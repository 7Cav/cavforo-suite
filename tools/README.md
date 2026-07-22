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
there is no framework and no XenForo. It discovers every `tools/tests/*.php`, so
a new one is picked up with no change here. Takes no arguments and needs only
`php`. CI runs this in its own job.

```
tools/run-tools-tests.sh
```

### `build.sh <AddonId>`

Builds a release zip the canonical way, through XenForo's own
`xf-addon:build-release`: it exports `_data/` from the database, then packages
the zip into the addon's `_releases/` directory. This needs a working XenForo
install. Point it at yours with one of:

```
XF_ROOT=/path/to/xenforo                              tools/build.sh SteamChecker
XF_CMD="docker exec xenforo-staging-fpm php cmd.php"  tools/build.sh SteamChecker
```

Your repo's `src/addons/Cav7` must be reachable by that install (the symlink
setup in [CONTRIBUTING.md](../CONTRIBUTING.md)), so the export writes back into
this repo.

### `package-addon.sh <AddonId> [--ref <git-ref>] [--out <file.zip>]`

Builds a release zip from committed files, with no XenForo install. It
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

Two things it does not reproduce. XenForo's `hashes.json` file-health manifest,
which the real build generates; the zip installs fine without it. And the
`build.json` keys `package-web-assets.php` never reads, `exec` and `rollup`: it
reads `additional_files` and `minify` and nothing else. RosterPatch's `exec`
prunes `tests/`, which this path leaves out anyway, so the two agree there by
coincidence rather than by design — an `exec` pruning anything else needs that
path adding to the `excludes` array in `package-addon.sh` to be reproduced here,
and an `exec` doing something other than pruning, along with any `rollup`
bundling, does not happen here at all.

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
`addon.json` shape and that every `_data/*.xml` is well-formed.
`check-data-consistency.php` cross-checks the `_output/` tree against the
`_data/` bundle and fails on drift, which catches a `_data/` that was not
re-exported after a change. Both need only `php`.

```
php tools/validate-addon.php src/addons/Cav7/SteamChecker
php tools/check-data-consistency.php src/addons/Cav7/SteamChecker
```
