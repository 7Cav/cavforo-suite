# Checking vendor markup from inside the test suite

This suite does not check its template modifications against vendor markup from
within `tools/run-tests.sh`, whether by committing a captured vendor template or
by reading an installed vendor tree behind an environment variable. Vendor drift
is caught on a live board, by `cav7-core:check-template-modifications`.

## Why this is out of scope

Eight add-ons here patch templates they do not own. The question worth asking is
always the same one — *has the vendor's markup moved out from under a find?* —
and there have been two proposals for asking it from the test suite. Both are
closed.

**Committing the vendor's template.** Tried, in #181, as two small annotated
excerpts under `RosterPatch/tests/fixtures/`. Deleted. `7Cav/cavforo-suite` is a
public repo, the vendor add-ons are paid third-party products, and verbatim
vendor code does not go in it — not a whole template, not a bounded excerpt with
a provenance header. The release zip already strips `tests/`, which is beside the
point: the concern is the repo, not the artifact. No tests at all is preferable
to shipping vendor code. This constraint is not negotiable and is not a
consequence of any current circumstance.

**Reading an installed vendor tree behind an environment variable.** Proposed in
#172: point a variable at a XenForo root, read the vendor add-on's own
`_data/templates.xml`, run each shipped `<find>` against the template body it
targets, skip cleanly when the tree is absent so CI stays green. It commits no
vendor code, so it clears the constraint above. It is still the wrong place to
ask, for three reasons.

*It cannot reach a third of the surface.* A vendor add-on's templates are
readable from its `_data` export. XenForo's own core templates are not — on disk
they exist only as compiled per-style PHP under `internal_data/code_cache`, which
is board state. Of the add-ons that ship template modifications, the ones
targeting core templates (`ApiKeyManager`, `MilpacMention`, `MilpacTooltip`) are
permanently out of reach of any check built this way.

*It re-implements the thing it is checking.* Deciding whether a find matches
means applying XenForo's matching rules, which means either mirroring
`XF\Repository\TemplateModificationRepository::applyTemplateModifications()` by
hand or requiring XenForo to be present. The hand-written mirror is a second
implementation that nothing holds to the original across a XenForo upgrade; and
if XenForo has to be present anyway, the board is right there and has a better
answer.

*A test that runs on one machine is not a test.* Every developer without a vendor
tree — and CI — sees a skip. A skipped check reads exactly like a passing one at
a glance, and this would be the first environment-gated test in the repo, so
whatever shape it took would become the convention for the next one.

## What answers the question instead

`cav7-core:check-template-modifications`, in `Cav7/Core`. It asks the board what
XenForo actually recorded when it applied each modification, rather than
re-deciding the question against a file:

```
php cmd.php cav7-core:check-template-modifications
```

It checks the master copy of every target template precisely because a find
failing *there* means the vendor's markup has moved, as distinct from a style
having been edited — the two are separate diagnoses behind the same shape, and
the failure's reason says which. `Cav7\Core\TemplateModification\Shape` also
carries `target template does not exist`, for the vendor renaming or dropping a
template outright.

Compared with either proposal above it covers every shipped modification rather
than the subset whose targets belong to a vendor add-on, it uses XenForo's own
verdict rather than a mirror of its matching rules, and it runs daily from
`cav7CoreTemplateModCheck` on the board that matters rather than when somebody
remembers to run a suite on the one machine that has a vendor tree.

This is the same division `CONTRIBUTING.md` states for everything vendor-coupled:
CI tests behaviour it can execute, and vendor drift is caught against a live
install. Nothing in this repo can see vendor drift, because every test here
compares our code against our own expectations. That is a property of the test
suite, not a gap in it.

## What is still fair to ask for

Rejecting the mechanism does not reject every criterion the proposals carried.
Two are legitimate and belong on the check rather than in a test:

- Reporting the vendor add-on's version on a failure line, so a pass is
  attributable to a version rather than to "some board was present".
- Retiring the hand-written `applyTemplateModifications()` mirror in
  `RosterPatch/tests/MilpacDateTemplateModificationTest.php`, which that test's
  own docblock names as an unenforced limit.

Neither requires reading vendor markup from a test, and neither reopens this.

## Prior requests

- #172 — "Check template modification finds against a vendor tree when one is
  available" (the environment-variable form)
- #106, acceptance criterion 2 — "A test asserts each modification's find matches
  the vendor template markup shipped with the supported NF/Rosters version"
  (satisfied by #181's committed fixtures, which were then removed)
