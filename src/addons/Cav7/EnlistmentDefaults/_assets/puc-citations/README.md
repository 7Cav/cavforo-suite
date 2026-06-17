# PUC citation images

The Presidential Unit Citation documents that this addon attaches to each PUC
grant it applies to a new milpac. See the addon `CONTEXT.md` (PUC, citation) and
`docs/adr/0001-bundle-citation-set.md` for why the set is bundled here rather
than cloned from a template milpac.

Each filename is the award date (`YYYY-MM-DD.jpg`), which is also the
`award_date` the addon sets on the matching `RosterUserAward`. The date printed
on each citation confirms the mapping.

| File | Award date on the citation |
|---|---|
| `2003-03-18.jpg` | 18th day of March 2003 |
| `2004-09-01.jpg` | 1st day of September 2004 |
| `2009-08-10.jpg` | 10th day of August 2009 |
| `2010-09-18.jpg` | 18th day of September 2010 |
| `2011-06-02.jpg` | 2nd day of June 2011 |
| `2021-05-16.jpg` | 16th day of May 2021 |

These are unit-level documents: the same image is reused for a given date across
every member, so they are shared assets rather than per-member files. When the
unit earns another PUC, add its citation here named by date and apply it in the
addon (see ADR-0001).
