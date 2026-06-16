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
`tests/`, `docs/`, `CONTEXT.md`, ...) are left out.

The one thing it does not reproduce is XenForo's `hashes.json` file-health
manifest, which the real build generates. The zip installs fine without it.

```
tools/package-addon.sh SteamChecker
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
