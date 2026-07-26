# ADR-0003: A rejected prefill value stays silent; the error-log rule is the applier's

- **Status:** Accepted
- **Date:** 2026-07-25
- **Issues:** #169
- **Amends:** [ADR-0002](0002-error-log-is-the-only-failure-surface.md)

## Context

ADR-0002 decided that "failures are recorded in the XenForo admin error log and
nowhere else", and that what the log records has to be actionable. Its context is
the applier path — the grants and the enlistment record written from
`RosterUser::_postSave()` — but that sentence reads as a rule for the whole
add-on, and for a while two documents claimed it was one.

The add-form prefill has two failure modes and they do not behave alike. #169 was
filed because the controller's class docblock and the README both described them
as one thing, saying a bad option **or** a custom-field rejection is logged. Only
the first half was true. Driving the real add-form GET render on a dev stack
settled it:

| condition | form renders | prefill result | error log |
|---|---|---|---|
| healthy | yes | rank, position (when the roster lists it) and both dates | no entry |
| `joinDate` rejected | yes | `joinDate` blank, `promoDate` still filled | **no entry** |
| bad board timezone | yes | **nothing** prefilled | one entry, stamped with the add-on's context |

A rejection does not throw. Both `set()` calls pass `ignoreInvalid`, and every
rejection branch in `XF\CustomField\Set::set()` returns false without throwing and
without putting an error on the entity. Nothing reaches the catch, so nothing
reaches the log.

## Decision

ADR-0002's rule is the applier's, and this narrows it to that path. A prefill
value the field set rejects is dropped silently: nothing is logged, no error is
put on the entity, and that one field is left unfilled while the others still
fill.

The prefill's other failure is unchanged. A bad board timezone throws, is logged,
and costs the whole prefill rather than one field. (A wrong rank or position id
throws nothing; it prefills the wrong value, which is a configuration error the
recruiter sees on the form.)

The docblock and the README now say this plainly, because the cost of the silence
is not that it happens but that someone searches the log for it.

## Considered options

- **Log a rejection, so ADR-0002's rule holds add-on-wide.** Rejected, on
  ADR-0002's own reasoning. That ADR rejected a health-check cron because
  "detecting on a schedule what is already obvious on sight is not worth" the
  cost, and a rejection is obvious on sight in the strongest sense available: the
  recruiter is looking at the blank field on the form in front of them and types
  the value. This is the opposite of a dropped PUC grant, which is invisible on a
  milpac that looks entirely ordinary and is exactly why the log matters there.
  An entry nobody would act on makes the log worse, not better, when ADR-0002's
  whole point is that an entry has to be enough on its own. The cost is real too:
  no milpac exists at prefill time, so the entry could not carry the identity
  stamp ADR-0002 requires, and testing it would mean building a controller seam
  the add-on does not have.
- **Turn off `ignoreInvalid` so a rejection surfaces as an entity error.**
  Rejected: that puts a validation error on the add form and breaks the fail-open
  guarantee. A prefill must never block the form.
- **Say nothing and leave the documents as they were.** Rejected: that is the
  defect #169 reports. Two documents describing a failure as landing in the log
  when it does not is worse than the silence itself.

## Consequences

A misconfigured roster field prefills nothing into that field, forever, with no
trace anywhere. Nobody finds out from the add-on; they find out from a recruiter
saying a field stopped filling in. That is accepted, because the repair is to fix
the field's configuration and the symptom points straight at it.

The prefill's `catch (\Throwable)` stays broad and stays unpinned, which is the one
loose end this ADR knowingly leaves. Its breadth was measured on a dev stack rather
than in CI: with an `\Error` thrown through the prefill, `\Throwable` renders the
add form and logs with the add-on's context prefix, while narrowing to `\Exception`
lets the `\Error` escape and XenForo returns an error page in place of the add form.
The justification is written into the docblock so narrowing it later is a deliberate
act.

Being unpinned is an absence, not an impossibility, and the docblock says so. This
add-on has no controller harness; it does have XenForo stand-ins.
`tests/FailureLoggingTest.php` declares its own `XF` facade and `XFCP_RosterUser`
and throws `\Error` through the entity-level catches, which is exactly what pins
their breadth. The same pattern extended to `View` and `ParameterBag` would pin
this one. That work was not in #169's scope and is worth its own issue. What is not
the answer is a test asserting the docblock's wording, which would be a source-text
change detector; see
["What belongs in CI, and what does not"](../../../../../../CONTRIBUTING.md).
