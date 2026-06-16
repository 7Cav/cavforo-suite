# Contributing

This repo holds the 7th Cavalry's XenForo addons in one place. Before you start, skim [docs/addon-format.md](docs/addon-format.md), which describes the shape every addon follows.

## Dev setup

You need a working XenForo 2.3 install to run, edit, and build addons. Some addons also need third-party addons present to install (NF/Rosters, XenForo Enhanced Search, SV/ElasticSearchEssentials); each addon's README lists what it requires.

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

The shared build, test, and release tooling is being set up in #10. Until it lands, export an addon's data locally with `xf-addon:export` and package a release with `xf-addon:build-release`, as described above. Release tags are per addon and use `<AddonId>-vX.Y.Z`.

## A note on the vendor name

The vendor prefix is `Cav7`, not `7Cav`, because a PHP namespace cannot start with a digit. Keep `Cav7` everywhere in code and addon ids.
