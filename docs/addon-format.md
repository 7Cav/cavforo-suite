# Addon format

Every addon in this repo follows the same shape. If you are adding a new one, copy [`_skeleton/`](../_skeleton/) and adjust it to match what is described here.

## Where an addon lives

```
src/addons/Cav7/<AddonId>/
```

This mirrors the path XenForo itself uses (`src/addons/<Vendor>/<AddonId>/`), so a dev install can symlink `src/addons/Cav7` at this repo and load every addon with no copy step. See [CONTRIBUTING.md](../CONTRIBUTING.md) for the symlink setup.

## Vendor and namespace

The vendor is `Cav7` and the PHP namespace root is `Cav7\<AddonId>`. It is `Cav7` rather than `7Cav` because a PHP namespace cannot start with a digit. The GitHub org is `7Cav`; the code vendor is `Cav7`. Keep this consistent across every addon.

## Required files

- `addon.json`: the manifest (see below).
- `Setup.php`: only if the addon creates tables, options, fields, or other install state. A class-extension-only addon does not need one.
- PHP classes under namespaced directories that match XenForo's conventions (`XF/`, `Pub/`, `Admin/`, `Entity/`, `Repository/`, `Job/`, and so on).
- `README.md`: a short description, the requirements, and a provenance note (see below).

### addon.json

```json
{
    "title": "7Cav - Example",
    "description": "One sentence on what the addon does.",
    "version_id": 1000070,
    "version_string": "1.0.0",
    "dev": "Cav7",
    "dev_url": "https://github.com/7Cav",
    "addon_id": "Cav7/Example",
    "namespace": "Cav7\\Example",
    "setup": "Cav7\\Example\\Setup",
    "require": {
        "XF": [2030070, "XenForo 2.3.0+"]
    },
    "license": "MIT"
}
```

- Keep `addon_id`, `version_id`, and `namespace` stable once an addon ships. Existing installs upgrade by `version_id`, so changing it forces a reinstall.
- Drop the `setup` key if the addon has no `Setup.php`.
- Declare every hard dependency in `require`, including the XenForo floor and any third-party addons (for example `NF/Rosters`, `XFES`, `SV/ElasticSearchEssentials`). An addon that needs another addon but does not declare it can install into a broken state.

### README provenance note

Each addon was imported from its own repository with `git subtree`, so its history and authorship live in this repo's log. Record where it came from at the bottom of the addon's README:

```
Imported from https://github.com/7Cav/<source-repo> at commit <sha>.
```

## XenForo data: `_output/` is the source, `_data/` is built

XenForo stores an addon's options, phrases, templates, routes, permissions, and similar as XML. There are two forms:

- `_output/` is the development-mode export tree, one file per item. This is what you edit.
- `_data/*.xml` is the bundle XenForo installs from. It is generated from `_output/` by `xf-addon:build`.

The workflow:

1. Turn on development mode in your XenForo dev install.
2. Edit options, phrases, templates, and so on through the admin control panel. XenForo writes them to `_output/`.
3. Run `xf-addon:build Cav7/<AddonId>` to compile `_output/` into `_data/`.
4. Commit both `_output/` and the regenerated `_data/`.

Do not hand-edit `_data/`. Treat it as build output that happens to be committed so the addon installs from a fresh clone.

## Versioning and release tags

- `version_string` is the human version (`1.2.0`); `version_id` is XenForo's integer form used for upgrade ordering.
- Release tags are per addon and use the form `<AddonId>-vX.Y.Z`, for example `SteamChecker-v1.1.4`. The shared release workflow (tracked in #10) turns one of these tags into that addon's release zip.

## What belongs at the addon level vs the suite level

Per-repo scaffolding from the original repositories does not all carry over the same way. Some of it the suite handles once at the top level; some of it stays with the addon.

The suite handles these once, at the root:

- CI workflows (the shared build and CI is tracked in #10).
- Dev harnesses such as Docker compose setups.
- Agent and suite-wide governance docs (`AGENTS.md`, `docs/agents/`, `CLAUDE.md`).
- Suite-wide architecture decisions, under [`docs/adr/`](adr/).

These stay with the addon, under `src/addons/Cav7/<AddonId>/`:

- The addon's `README.md`.
- The addon's `CONTEXT.md` (its domain glossary), if it has one.
- The addon's own `docs/adr/`, including any ADRs it arrived with. This repo is multi-context; see [CONTEXT-MAP.md](../CONTEXT-MAP.md).
