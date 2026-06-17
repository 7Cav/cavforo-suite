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
the org.
_Avoid_: profile (the XenForo user profile is a separate thing), personnel
jacket, record (ambiguous with service record)
