# 0001 - Monorepo conventions

## Status

Accepted

## Context

The 7Cav XenForo addons lived in seven separate repositories, each with a different directory layout and its own (or no) build and release tooling. We are bringing them into one repo so they are easier to maintain and so contributors can add new addons by following a single pattern.

A few choices shape everything else: where addons sit, how their code arrives, and how XenForo data is edited and built.

## Decision

**Mirror the XenForo tree.** Addons live at `src/addons/Cav7/<AddonId>/`, the same path a XenForo install uses. A dev install symlinks `src/addons/Cav7` at this repo and loads every addon with no copy or build step. The vendor stays `Cav7` (a PHP namespace cannot start with a digit, so the `7Cav` org name becomes `Cav7` in code).

**Import with `git subtree`, preserving history.** Each addon comes in with its commits and authorship intact, then a follow-up commit moves its files into the standard path. Several addons were written by people other than the suite maintainers, and the first act of the monorepo should not be to erase who wrote them. Source repositories are archived rather than deleted so the record stays accessible.

**`_output/` is the editable source; `_data/` is built.** Contributors edit XenForo data (options, phrases, templates) through the admin control panel with development mode on, which writes to `_output/`. `xf-addon:build` compiles that into `_data/*.xml`. Both are committed so an addon installs from a fresh clone, but `_data/` is never hand-edited. This follows XenForo's intended workflow instead of the hand-authored XML the source repos used.

**Release tags are per addon.** A tag of the form `<AddonId>-vX.Y.Z` builds and publishes that one addon. `addon_id`, `version_id`, and `namespace` stay stable so existing installs upgrade rather than reinstall.

## Consequences

- A contributor can copy `_skeleton/`, follow [docs/addon-format.md](../addon-format.md), and end up with an addon shaped like every other one.
- The repo carries each addon's full history, including the binary history of `AvatarByRole`'s image assets. That weight is small and worth the preserved authorship.
- Shared build, release, and CI still need to be built (#10), since no source repo brought tooling that fits a multi-addon repo.
- Pulling shared code into `Cav7/Core` is a separate effort, taken up once everything is in one place.
