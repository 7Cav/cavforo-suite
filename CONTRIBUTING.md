# Contributing

This repo holds the 7th Cavalry's XenForo addons in one place. Before you start, skim [docs/addon-format.md](docs/addon-format.md), which describes the shape every addon follows.

## Dev setup

You need a working XenForo 2.3 install to run an addon, or to change anything that lives in XenForo data (options, phrases, templates, and the like). Pure PHP changes do not need one: you can edit the code and run an addon's tests with just `php`. If you need a stack and do not have one, contact the maintainers. Some addons also need third-party addons present to install; each addon's README lists what it requires.

1. Install XenForo somewhere local. A common layout is a `xenforo/` directory beside this repo, which `.gitignore` already keeps out of version control.
2. Point the install's addon tree at this repo so it loads every addon:

   ```
   ln -s /path/to/cavforo-suite/src/addons/Cav7 /path/to/xenforo/src/addons/Cav7
   ```

3. Turn on development mode in the XenForo install's `config.php`:

   ```php
   $config['development']['enabled'] = true;
   ```

4. Install the addon you are working on from the admin control panel, or with `php cmd.php xf-addon:install Cav7/<AddonId>`.

## Editing an addon

Code changes are just PHP edits in the addon directory. Changes to options, phrases, templates, routes, and permissions go through XenForo's data flow:

1. Edit through the admin control panel with development mode on. XenForo writes the changes to the addon's `_output/` directory.
2. Refresh the install bundle with `php cmd.php xf-addon:export Cav7/<AddonId>`, which exports the current data to `_data/`. Both `_output/` and `_data/` are exported from the database; one is not compiled from the other.
3. Commit both `_output/` and the regenerated `_data/`. Do not hand-edit either.

There is more detail on the `_output/` and `_data/` split in [docs/addon-format.md](docs/addon-format.md).

## Adding a new addon

1. Copy [`_skeleton/`](_skeleton/) to `src/addons/Cav7/<AddonId>/`.
2. Rename the namespace from `Cav7\AddonId` to `Cav7\<YourAddonId>` in `addon.json` and every PHP file.
3. Fill in `addon.json`: title, description, version, and any dependencies in `require`. The add-on id and namespace come from the directory path, so they do not go in the file.
4. Install it (`php cmd.php xf-addon:install Cav7/<AddonId>`), then develop as above.
5. Add a short `README.md` describing what the addon does and what it requires.

## Build, test, and release

Shared scripts live in [`tools/`](tools/); [tools/README.md](tools/README.md) has the details.

- Run an addon's tests: `tools/run-tests.sh <AddonId>`. These are standalone PHP scripts and need only `php`.
- Build a release zip the canonical way (needs a XenForo install): `tools/build.sh <AddonId>`. It wraps `xf-addon:build-release` (export to `_data/`, then package). Point it at your install with `XF_ROOT` or `XF_CMD`.
- Package a zip from committed files with no XenForo install: `tools/package-addon.sh <AddonId>`. CI and the release workflow use this, and it produces the same `upload/...` layout the admin panel installs from.

CI runs the tests and a static build check on every push and pull request: lint, `addon.json` and `_data/` validation, an `_output/`-to-`_data/` consistency check, and a packaging dry-run. None of it runs XenForo. It trusts the committed `_data/`, so re-export and commit `_data/` whenever you change XenForo data, or the consistency check will fail.

Release tags are per addon and use `<AddonId>-vX.Y.Z` (for example `SteamChecker-v1.1.4`). Pushing one builds that addon's zip and publishes it as a GitHub release. The tag version must match the addon's `addon.json` `version_string`.

## A note on the vendor name

The vendor prefix is `Cav7`, not `7Cav`, because a PHP namespace cannot start with a digit. Keep `Cav7` everywhere in code and addon ids.
