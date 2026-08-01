# Addon skeleton

A starting point for a new addon in the suite. Copy this directory to `src/addons/Cav7/<AddonId>/` and make it yours.

## Steps

1. Copy `_skeleton/` to `src/addons/Cav7/<AddonId>/`.
2. In `addon.json`, set `title`, `description`, `version_string`, and `version_id`, and declare any dependencies in `require`. The add-on id and namespace come from the directory path; do not list them in the file.
3. Rename the namespace `Cav7\AddonId` to `Cav7\<YourAddonId>` in every PHP file, including `Setup.php`.
4. If the addon has no install logic (no tables, options, fields, or similar), delete `Setup.php`. XenForo only runs a setup class when one exists.
5. Build and install it (see [CONTRIBUTING.md](../CONTRIBUTING.md)), then start developing.
6. Replace this README with one that answers what your addon is, how to run it, and how to use it — see [CONTRIBUTING.md](../CONTRIBUTING.md#what-goes-in-an-addon-readme).

## About the directories

- `_output/` is where XenForo writes development-mode exports (options, phrases, templates), one file per item. You edit through the admin control panel; XenForo writes here.
- `_data/` is the bundle XenForo installs from, written by `xf-addon:export`. It is not in the skeleton because a fresh addon has no data yet. It appears once you add data and export.

See [docs/addon-format.md](../docs/addon-format.md) for the full format.
