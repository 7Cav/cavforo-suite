# Checking vendor markup from inside the test suite

This suite does not check its template modifications against vendor markup from
`tools/run-tests.sh`, whether by committing a captured vendor template or by
reading an installed vendor tree behind an environment variable. Vendor drift is
caught on a live board, by `cav7-core:check-template-modifications`.

## Why this is out of scope

**Committing the vendor's template** was tried in #181 and reverted. This is a
public repo, the vendor add-ons are paid third-party products, and verbatim
vendor code does not go in it — not a whole template, not a bounded excerpt with
a provenance header. That the release zip strips `tests/` is beside the point:
the concern is the repo, not the artifact.

**Reading an installed vendor tree behind an environment variable** (#172)
commits no vendor code, so it clears that constraint, and is still the wrong
place to ask:

- It reaches only the modifications whose targets belong to a vendor add-on. A
  vendor's templates are readable from its `_data` export; XenForo's own core
  templates exist on disk only as compiled per-style PHP under
  `internal_data/code_cache`, which is board state. The add-ons targeting those
  are permanently unreachable this way.
- Deciding whether a find matches means applying XenForo's matching rules — so
  either a hand-written mirror of `applyTemplateModifications()` that nothing
  holds to the original across an upgrade, or a XenForo that has to be present
  anyway, in which case the board has the better answer.
- Everyone without a vendor tree, CI included, sees a skip, and a skip reads like
  a pass.

## What answers the question instead

`cav7-core:check-template-modifications`, in `Cav7/Core`. It asks the board what
XenForo recorded when it applied each modification, rather than re-deciding the
question against a file. It checks each target's master copy precisely because a
find failing *there* means the vendor's markup has moved rather than a style
having been edited, and `Cav7\Core\TemplateModification\Shape` covers the vendor
renaming or dropping a template outright. It reads every modification the suite
ships, not the subset above, and runs daily.

This is the division `CONTRIBUTING.md` already states for vendor-coupled
behaviour. No test here can see vendor drift, because every one of them compares
our code against our own expectations — a property of the test suite, not a gap
in it.

What is rejected is asking from the test suite. Improving what the board check
reports is not.

## Prior requests

- #172 — "Check template modification finds against a vendor tree when one is
  available"
- #106, acceptance criterion 2 — a test asserting each find against the vendor
  markup of the supported NF/Rosters version
