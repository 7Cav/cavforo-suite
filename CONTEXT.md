# cavforo-suite — shared glossary

Suite-wide vocabulary, for terms that cut across more than one addon. Terms
specific to a single addon live in that addon's
`src/addons/Cav7/<AddonId>/CONTEXT.md`. See [CONTEXT-MAP.md](CONTEXT-MAP.md).

## Language

**milpac**:
A member's roster record in NF/Rosters — one `NF\Rosters\Entity\RosterUser`
row. The 7Cav name for a member's military personnel record. A milpac is
_created_ when that row is first inserted on the current roster system. Because
the org predates this site and has run earlier roster systems, a returning
member can have a new milpac created here without it being their first time in
the org. Within this system a member has at most one milpac; two
`RosterUser` rows for the same member is a data error, not a supported state.
_Avoid_: profile (the XenForo user profile is a separate thing), personnel
jacket, record (ambiguous with service record)

**data type**:
One kind of XenForo add-on data — options, phrases, routes, cron entries. A
data type is named twice, once for each of the two trees below, and the two
names are not always the same string.
_Avoid_: type on its own where it could mean either name

**`_data` tree**:
An add-on's data as XenForo exports it for release. This is the tree that
ships. What it holds:
[`docs/addon-format.md`](docs/addon-format.md).

**`_output` tree**:
The same data as XenForo exports it in development mode. What it holds:
[`docs/addon-format.md`](docs/addon-format.md).
_Avoid_: build output (nothing compiles it; it is an export like `_data`)

**release build**:
The zip an add-on is distributed as, and the only one anyone installs from. It
carries add-on code and its `_data` tree, and no dev-only path. Built without a
XenForo install, so CI produces it.
_Avoid_: canonical build (which named the other one)

**local build**:
The zip XenForo's own release builder produces from a working install. A testing
artifact — it carries dev-only paths and is not a thing to install a board from.
Why the two differ:
[`docs/adr/0005-the-release-build-is-the-distribution-channel.md`](docs/adr/0005-the-release-build-is-the-distribution-channel.md).
_Avoid_: canonical build

**dev-only path**:
A path an add-on carries for the people working on it rather than for the board
running it — its `tests/`, `docs/`, `CONTEXT.md`. Distinct from a path that is
merely not shipped *in place*, such as `_files`.
