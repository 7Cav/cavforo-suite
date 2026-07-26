# cavforo-suite

One home for the 7th Cavalry's XenForo addons, plus `Cav7/Core`, which carries what serves the suite as a whole.

The addons were scattered across separate repositories, each with its own layout and tooling. This repo brings them together under one structure so they are easier to maintain and so new addons can follow the same pattern.

## What lives here

Addons sit at `src/addons/Cav7/<AddonId>/`, mirroring a XenForo install's tree, so a dev install can symlink `src/addons/Cav7` straight at this repo.

| Addon | What it does |
|---|---|
| `Cav7/Core` | Suite-wide tooling (the template modification check) and the shared library the addons draw on |
| `Cav7/SteamChecker` | Steam VAC/game-ban checks on enlistment |
| `Cav7/ApiKeyManager` | Personal API keys for the external API |
| `Cav7/RosterAudit` | Audit trail for NF/Rosters changes |
| `Cav7/RosterSearch` | Gamertag/name search over the roster |
| `Cav7/UserGroupsScope` | `user:groups` API scope |
| `Cav7/DotTokenFix` | Dot-splitting fix for ElasticSearch |
| `Cav7/AvatarByRole` | Forces avatars to match 7Cav rank |
| `Cav7/MilpacTooltip` | Milpac (rank/billet/status) on the member hovercard |
| `Cav7/EnlistmentReminder` | Reminds the processing team about enlistment applications stalled in the queue |
| `Cav7/DiscordSyncPatch` | Makes forum user groups authoritative for the Discord roles they grant, and lets members resync their own |
| `Cav7/CalendarPatch` | Behaviour fixes for NF/Calendar, kept out of the vendor addon |
| `Cav7/EnlistmentDefaults` | Prefills the add-milpac form; gives each new milpac its Presidential Unit Citations and first service record |
| `Cav7/MilpacMention` | Alerts a member when their milpac link is posted, as an `@`-mention would |
| `Cav7/RosterPatch` | Behaviour fixes for NF/Rosters, kept out of the vendor addon |
| `Cav7/ModeratorLogPatch` | Records a moderation action from whoever had permission to take it, not only from the few holding a moderator record |

The vendor prefix is `Cav7`, not `7Cav`, because a PHP namespace cannot start with a digit. The GitHub org is `7Cav`; the code vendor is `Cav7`.

## Working on an addon

You need a XenForo dev install to run and build addons. The short version:

1. Point a XenForo install at this repo (symlink `src/addons/Cav7`, details in [CONTRIBUTING.md](CONTRIBUTING.md)).
2. Turn on development mode in that install.
3. Edit the addon through the admin control panel, then refresh `_data/` with `xf-addon:export` (and `_output/` with `xf-dev:export`).

Full setup, the addon format, and how to add a new addon are in [CONTRIBUTING.md](CONTRIBUTING.md) and [docs/addon-format.md](docs/addon-format.md).

## License

MIT. See [LICENSE](LICENSE).
