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
3. Commit both `_output/` and the regenerated `_data/`. Never write either tree
   by hand: derive the one you did not edit, as below.

### Data-only changes without an install

When the whole change is XenForo data — a template modification's find string, an
option's default, a phrase's wording — you do not need an install to keep the two
trees in step. Edit one tree and derive the other:

```
php tools/sync-addon-data.php src/addons/Cav7/<AddonId> --to-output
php tools/sync-addon-data.php src/addons/Cav7/<AddonId> --to-data
```

The script needs only `php`. It is what step 3 means by deriving rather than
writing by hand: reproducing XenForo's formatting yourself is guesswork, and the
consistency check fails on any drift.

It is a second path, not a replacement. With an install, `xf-addon:export` and
`xf-dev:export` stay canonical, and they are the only path that picks up a change
made in the control panel. What the script does and does not reproduce is in
[tools/README.md](tools/README.md).

There is more detail on the `_output/` and `_data/` split in [docs/addon-format.md](docs/addon-format.md).

## Adding a new addon

1. Copy [`_skeleton/`](_skeleton/) to `src/addons/Cav7/<AddonId>/`.
2. Rename the namespace from `Cav7\AddonId` to `Cav7\<YourAddonId>` in `addon.json` and every PHP file.
3. Fill in `addon.json`: title, description, version, and any dependencies in `require`. The add-on id and namespace come from the directory path, so they do not go in the file.
4. Install it (`php cmd.php xf-addon:install Cav7/<AddonId>`), then develop as above.
5. Add a short `README.md` describing what the addon does and what it requires.
6. Add a row for it to the addon catalog in the repo-root [README.md](README.md).
   That table is the one home for the list of addons, and CI fails when it and
   the addons that ship disagree — see `check-readme-catalog.php` in
   [tools/README.md](tools/README.md).

## Build, test, and release

Shared scripts live in [`tools/`](tools/); [tools/README.md](tools/README.md) has the details.

- Run an addon's tests: `tools/run-tests.sh <AddonId>`. These are standalone PHP scripts and need only `php`, plus `pdo_sqlite` for DiscordSyncPatch, whose sweep test runs the addon's real SQL against an in-memory database rather than a fake that answers by query shape.
- Build the **release build** from committed files, with no XenForo install: `tools/package-addon.sh <AddonId>`. CI and the release workflow use this, it produces the `upload/...` layout the admin panel installs from, and it is the only zip anyone should install a board from.
- Build a **local build** for testing against a real install (needs a XenForo install): `tools/build.sh <AddonId>`. It wraps `xf-addon:build-release` (export to `_data/`, then package). Point it at your install with `XF_ROOT` or `XF_CMD`. It ships each addon's `tests/`, `docs/` and `CONTEXT.md` deliberately — see [ADR 0005](docs/adr/0005-the-release-build-is-the-distribution-channel.md).

CI runs the tests and a static build check on every push and pull request: lint, `addon.json` and `_data/` validation, an `_output/`-and-`_data/` consistency check, and a packaging dry-run. It also packages every addon for real and reads the archive, so a release zip that gained a `tests/` directory fails the build. Once for the repo rather than per addon, it checks the root README's addon catalog against the addons that ship, so adding an addon and leaving the table alone fails. None of it runs XenForo — that is the constraint on what CI can check, rather than any rule about which tools a check may use. The consistency check reads both trees against each other, so re-export and commit both whenever you change XenForo data, or it will fail — including when a data type reaches one tree and not the other. For most types it compares what the records actually say and not just how many there are, so editing one side alone fails even when the counts still line up; [tools/README.md](tools/README.md) lists which types are checked how. It also reads each `_output/<type>/_metadata.json` against the files beside it, which is what catches a tree that was hand-edited rather than exported — so hand-edit neither tree, in either direction.

### What belongs in CI, and what does not

CI tests the behaviour it can actually execute. A test calls the code with real inputs and asserts on what comes back; if a behaviour-preserving refactor would break it, it is testing the wrong thing and does not belong here.

A seam that needs a live XenForo does not become testable by reading the source as text. Asserting that a file contains a call, a signature, a statement in a given order, or a variable spelled a certain way does not check the behaviour — it fails when the code is tidied and passes when the code is wrong, and it hides the fact that the seam was never covered. Do not add those.

Vendor-coupled behaviour is verified by hand on a dev stack before a release: install the addon, exercise the feature, confirm the vendor has not moved underneath it. That is also the only check that can catch vendor drift, which no test in this repo can see — every one of them compares our code against our own expectations. Several addon READMEs carry the specific list to re-run after a XenForo or vendor upgrade.

Where a structural fact is genuinely worth enforcing and is the same for every addon — `_data`/`_output` agreement, `addon.json` shape, class-extension ordering — it lives in `tools/` and runs against all of them, rather than being restated per addon.

Release tags are per addon and use `<AddonId>-vX.Y.Z` (for example `SteamChecker-v1.1.4`). Pushing one builds that addon's zip and publishes it as a GitHub release. The tag version must match the addon's `addon.json` `version_string`.

## A note on the vendor name

The vendor prefix is `Cav7`, not `7Cav`, because a PHP namespace cannot start with a digit. Keep `Cav7` everywhere in code and addon ids.
