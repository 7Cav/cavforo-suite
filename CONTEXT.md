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
running it — its `tests/`, `docs/`, `CONTEXT.md` and `_output` tree. Distinct
from a build input, below, which the build itself consumes.

**build input**:
A path the build reads while assembling the zip, and which is no part of what
installs — `build.json`, which declares the build's own handling, and `_files`.
Neither is dev-only: they are there for the build, not for the people working on
the add-on. Neither survives as itself, but they leave differently: `build.json`
is dropped, while `_files`' contents are relocated to the upload web root.
What asserts this: [`tools/README.md`](tools/README.md).

**template copy**:
One style's own version of a template — a single `xf_template` row. A template
is a _title_, and a title can have several copies: the master one and a copy in
each style that has edited it. A style with no copy of its own renders the
nearest ancestor's. Modifications are applied to, and recorded against, a copy
rather than a title, so a patch can be working on one copy and broken on
another.
_Avoid_: template on its own where the copy is what is meant

**in-use style**:
The board default style together with any style a member can select. The styles
whose template copies actually render for somebody. A style that is neither is
inert: its copies can be broken without any member seeing it.

**in force**:
Said of a template modification that XenForo has actually applied to the
template copies it targets. Three separate things have to be true and only the
third is _in force_: the modification is **shipped** (present in an add-on's
`_data`), **installed** (a record for it exists on the board), and in force. An
add-on can report installed and active while a modification it ships is in
neither of the later states.

**not in force**:
The umbrella for every way a shipped modification fails to reach the page,
enumerated under _shape_ below. What they share is silence — none of them stops
the board rendering, and none is reported by anything until somebody notices
the wrong output.

**shape**:
Which of those ways a given failure is. A report names the shape because the
remedies have nothing in common: an add-on whose data never imported is fixed
by rebuilding it, a find that stopped matching by editing a template copy.
These are the words a report prints, and the only six it prints:

`shipped but not installed`, `installed but disabled`, `owning add-on
inactive`, `target template does not exist`, `matched nothing`, `recorded a
non-ok status`.

`matched nothing` covers two states rather than one, because both mean the same
thing to a reader — the patch is provably not on that copy. XenForo either
recorded zero matches against it, or recorded nothing at all, never having
compiled it since the modification was installed. The failure's own words say
which; the shape does not.

**master mismatch** / **style mismatch**:
The two diagnoses behind a find that stopped matching. A _master mismatch_
means the vendor's own markup moved, and every style inherits the problem. A
_style mismatch_ means somebody edited that style's copy, and only the styles
resolving to it are affected. Same symptom, different cause, different fix.
