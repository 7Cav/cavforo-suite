# Addon skeleton

A starting point for a new addon in the suite. Copy this directory to `src/addons/Cav7/<AddonId>/` and make it yours.

## Steps

1. Copy `_skeleton/` to `src/addons/Cav7/<AddonId>/`.
2. In `addon.json`, set `title`, `description`, `addon_id`, `namespace`, `version_string`, and `version_id`, and declare any dependencies in `require`.
3. Rename the namespace `Cav7\AddonId` to `Cav7\<YourAddonId>` in every PHP file, including `Setup.php`.
4. If the addon has no install logic (no tables, options, fields, or similar), delete `Setup.php` and remove the `setup` key from `addon.json`.
5. Build and install it (see [CONTRIBUTING.md](../CONTRIBUTING.md)), then start developing.
6. Replace this README with one describing your addon.

## About the directories

- `_output/` is where XenForo writes development-mode exports (options, phrases, templates). You edit through the admin control panel; XenForo writes here.
- `_data/` is generated from `_output/` by `xf-addon:build`. It is not in the skeleton because a fresh addon has nothing to build yet. It appears once you add data and build.

See [docs/addon-format.md](../docs/addon-format.md) for the full format.
