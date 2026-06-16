# cavforo-suite

One home for the 7th Cavalry's XenForo addons, plus `Cav7/Core`, a shared library the addons will lean on over time.

The addons were scattered across separate repositories, each with its own layout and tooling. This repo brings them together under one structure so they are easier to maintain and so new addons can follow the same pattern. See issue #1 for the migration plan and progress.

## What lives here

Addons sit at `src/addons/Cav7/<AddonId>/`, mirroring a XenForo install's tree, so a dev install can symlink `src/addons/Cav7` straight at this repo.

| Addon | What it does | Status |
|---|---|---|
| `Cav7/Core` | Shared library for the suite | Placeholder, code extracted later |
| `Cav7/SteamChecker` | Steam VAC/game-ban checks on enlistment | Migrating (#3) |
| `Cav7/ApiKeyManager` | Personal API keys for the external API | Migrating (#4) |
| `Cav7/RosterAudit` | Audit trail for NF/Rosters changes | Migrating (#5) |
| `Cav7/RosterSearch` | Gamertag/name search over the roster | Migrating (#6) |
| `Cav7/UserGroupsScope` | `user:groups` API scope | Migrating (#7) |
| `Cav7/DotTokenFix` | Dot-splitting fix for ElasticSearch | Migrating (#8) |
| `Cav7/AvatarByRole` | Forces avatars to match 7Cav rank | Migrating (#9) |

The vendor prefix is `Cav7`, not `7Cav`, because a PHP namespace cannot start with a digit. The GitHub org is `7Cav`; the code vendor is `Cav7`.

## Working on an addon

You need a XenForo dev install to run and build addons. The short version:

1. Point a XenForo install at this repo (symlink `src/addons/Cav7`, details in [CONTRIBUTING.md](CONTRIBUTING.md)).
2. Turn on development mode in that install.
3. Edit the addon, export to `_output/`, and rebuild `_data/` with `xf-addon:build`.

Full setup, the addon format, and how to add a new addon are in [CONTRIBUTING.md](CONTRIBUTING.md) and [docs/addon-format.md](docs/addon-format.md).

## License

MIT. See [LICENSE](LICENSE).
