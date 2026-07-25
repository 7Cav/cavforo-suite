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
data type is named twice, once for each tree, and the two names are not always
the same string.
_Avoid_: type on its own where it could mean either name

**`_data` tree**:
An add-on's data as XenForo exports it for release, one XML file per data type.
Carries a file for every data type whether or not it holds records. This is the
tree that ships.

**`_output` tree**:
The same data as XenForo exports it in dev mode, one file per record. A data
type appears here only once it holds at least one record, so an absent
directory means either an unused type or one that was never exported.
