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
- `build.json`: only if the addon needs XenForo's build-time handling — web assets copied out of `_files/` (`additional_files`, `minify`, `rollup`), or `exec` commands, which run from the addon's own source directory and have to spell the staged path themselves. XenForo reads it during `xf-addon:build-release` and keeps it out of the zip; most addons do not have one. For what each key does here, and which of them the release build reproduces, see [`tools/README.md`](../tools/README.md). An `exec` cannot report its own failure — `execCmds()` hands each entry to `passthru()` and discards the exit status — which is part of why the release build does not rely on one; see [ADR 0005](adr/0005-the-release-build-is-the-distribution-channel.md).

### addon.json

Match XenForo's own schema, the one `xf-addon:create` writes and `xf-addon:validate-json` checks:

```json
{
    "legacy_addon_id": "",
    "title": "7Cav - Example",
    "description": "One sentence on what the addon does.",
    "version_id": 1000070,
    "version_string": "1.0.0",
    "dev": "Cav7",
    "dev_url": "https://github.com/7Cav",
    "faq_url": "",
    "support_url": "",
    "extra_urls": [],
    "require": {
        "XF": [2030070, "XenForo 2.3.0+"]
    },
    "icon": ""
}
```

- The add-on id is the directory path (`Cav7/Example`), the namespace follows from it (`Cav7\Example`), and the setup class is whatever `Cav7\Example\Setup` resolves to if that class exists. XenForo derives all three, so do not add `addon_id`, `namespace`, or `setup` keys. `xf-addon:validate-json` flags them as unexpected and rewrites the file.
- Keep `version_id` and the directory path stable once an addon ships. Existing installs upgrade by `version_id`, so changing either forces a reinstall.
- Declare every hard dependency in `require`, including the XenForo floor and any third-party addons (for example `NF/Rosters`, `XFES`, `SV/ElasticSearchEssentials`). An addon that needs another addon but does not declare it can install into a broken state.

### README provenance note

Each addon was imported from its own repository with `git subtree`, so its history and authorship live in this repo's log. Record where it came from at the bottom of the addon's README:

```
Imported from https://github.com/7Cav/<source-repo> at commit <sha>.
```

## XenForo data: `_data/` and `_output/`

XenForo stores an addon's options, phrases, templates, routes, permissions, and similar as XML. It keeps two on-disk forms, and both are exported from the database. One is not compiled from the other.

- `_data/*.xml` is the bundle XenForo installs from, and what `xf-addon:install` reads. It is always the full set of data-type files (around 27), including empty ones for types the addon does not use. `xf-addon:export` writes it.
- `_output/` is the development tree: one file per item, grouped by type, with only the types the addon actually uses. It diffs cleanly in version control. `xf-dev:export` writes it, and with development mode on, XenForo also writes to it as you edit through the admin control panel.

The workflow:

1. Turn on development mode in your XenForo dev install (`$config['development']['enabled'] = true`).
2. Edit options, phrases, templates, and so on through the admin control panel. XenForo writes the changes to `_output/`.
3. Refresh the install bundle with `php cmd.php xf-addon:export Cav7/<AddonId>`. To (re)generate `_output/` explicitly, for example for an addon imported with only `_data/`, run `php cmd.php xf-dev:export --addon Cav7/<AddonId>`.
4. Commit both `_output/` and the regenerated `_data/`.

Do not hand-edit either tree. Treat them as exports that happen to be committed so the addon installs from a fresh clone. There is no `xf-addon:build`. The only build command is `xf-addon:build-release`, which packages `_data/` into a release zip.

## Versioning and release tags

- `version_string` is the human version (`1.2.0`); `version_id` is XenForo's integer form used for upgrade ordering.
- Release tags are per addon and use the form `<AddonId>-vX.Y.Z`, for example `SteamChecker-v1.1.4`. The shared release workflow ([`.github/workflows/release.yml`](../.github/workflows/release.yml)) turns one of these tags into that addon's release zip, laid out as `upload/src/addons/Cav7/<Id>/` so it installs through the admin panel's "Install/upgrade from archive". The tag version must match `version_string`.

## What belongs at the addon level vs the suite level

Per-repo scaffolding from the original repositories does not all carry over the same way. Some of it the suite handles once at the top level; some of it stays with the addon.

The suite handles these once, at the root:

- CI and release workflows ([`.github/workflows/`](../.github/workflows/)) and the shared scripts in [`tools/`](../tools/).
- Dev harnesses such as Docker compose setups.
- Agent and suite-wide governance docs (`AGENTS.md`, `docs/agents/`, `CLAUDE.md`).
- Suite-wide architecture decisions, under [`docs/adr/`](adr/).

These stay with the addon, under `src/addons/Cav7/<AddonId>/`:

- The addon's `README.md`.
- The addon's `CONTEXT.md` (its domain glossary), if it has one.
- The addon's own `docs/adr/`, including any ADRs it arrived with. This repo is multi-context; see [CONTEXT-MAP.md](../CONTEXT-MAP.md).
- The addon's `docs/verification/`, if it has a seam CI cannot reach. One file per seam, recording a pass against a dev stack: the steps that produced each outcome, the output they produced, and how the board was put back. See [CONTRIBUTING.md](../CONTRIBUTING.md#what-belongs-in-ci-and-what-does-not) for when one of these is the right answer, and the addon's own README for when it is re-run.
