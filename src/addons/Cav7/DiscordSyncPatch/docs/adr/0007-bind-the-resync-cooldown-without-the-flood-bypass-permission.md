# ADR-0007: Bind the resync cooldown without XenForo's flood-bypass permission

- **Status:** Accepted
- **Date:** 2026-07-25
- **Issues:** #167
- **Amends:** [ADR-0005](0005-honour-a-resync-request-without-checking-divergence.md)
- **Note (2026-07-25):** the `tests/WiringTest.php` named below was deleted as a
  source-text change detector. Do not reinstate it or write another like it; see
  ["What belongs in CI, and what does not"](../../../../../../CONTRIBUTING.md). The
  hazard this consequence describes is real — the answer to it is
  `tools/discord-resync-cooldown-check.php` on a dev stack, as the entry itself says.

## Context

ADR-0005 gave the resync button two guards and said what they are for: "so that one
member cannot spend the forum's Discord rate limit." It also recorded why they are
two things rather than one:

> Staff usually hold XenForo's flood-bypass permission, which is why the pending check
> exists separately from the cooldown rather than as a refinement of it.

That sentence is the problem. The cooldown was `assertNotFlooding()`, and
`XF\Pub\Controller\AbstractController::assertNotFlooding()` returns before the flood
check writes anything when the visitor holds `general:bypassFloodCheck`. So the
cooldown bound only the members without that permission, and ADR-0005 took the
affected population to be staff.

It is not staff on this forum. Read from the built permission cache on a
production mirror, seven user groups grant `general:bypassFloodCheck`:
Administrative, Moderating and Admin Override — and the four member status groups,
7th Cavalry Member Active, Reserve, Retired and Discharged. Membership of the unit
carries it. The Registered group has no entry for it at all.

What that meant for this button, measured on the same mirror: of 3,577 members with a
linked Discord account — the only people who can press it — 3,445 held the bypass, or
96.3%. The cooldown bound 132 of them. Every other member could press again as soon as
the queue drained, and `Repository\Queue::enqueueJob()` enqueues with
`trigger_date = \XF::$time`, so that is the next job-runner tick rather than five
minutes. The guard ADR-0005 called load-bearing was not bearing the load.

Two remedies were open.

**Narrow the permission.** Take `general:bypassFloodCheck` off the four member status
groups. This is not scoped to the resync button: `assertNotFlooding()` gates posting,
searching, reporting and reactions throughout XenForo core, so it would put roughly
5,900 members back under the forum-wide posting flood check to bound one button. It is
also a decision about how the forum treats its own membership rather than a defect
fix, and there is no reason to believe the grant was accidental.

**Make the cooldown bind regardless.** `assertNotFlooding()` is a thin helper: a
permission test, then a call to `XF\Service\FloodCheckService::checkFlooding()`, then
a throw through `responseFlooding()`. Calling the service directly is the same
cooldown without the exemption.

## Decision

The resync cooldown calls `FloodCheckService::checkFlooding()` directly and throws
`responseFlooding()` itself, rather than going through `assertNotFlooding()`. It binds
every member, whether or not they hold `general:bypassFloodCheck`.

The permission is left alone. Nothing here forecloses narrowing it later; that is a
separate decision, on separate evidence, and it is no longer needed to make this
button's limit mean what ADR-0005 said it meant.

ADR-0005 stands otherwise. Its reasoning about why a resync runs blind, and why the
two guards bound different things, is unaffected — only its premise about who holds
the bypass was wrong, and only the sentence quoted above depended on it.

## Consequences

- Skipping a permission XenForo would honour is deliberate, and worth naming as such.
  `general:bypassFloodCheck` exempts a member from the posting flood check. Pressing
  this button is not posting: one press fans a queued message out per guild against a
  rate-limited integration, which is the cost ADR-0005 wrote the guards to bound. The
  exemption was never about this.
- The cooldown is now what serialises concurrent presses, for everyone.
  `checkFlooding()` decides on the row count of an `UPDATE` and then an
  `INSERT IGNORE`. Previously a bypass holder reached neither, leaving the pending
  guard's plain `SELECT` as the only bound — and a plain `SELECT` serialises nothing,
  so two genuinely concurrent presses from one holder could both fan out. That gap is
  closed as a side effect rather than by design.
- Every member who uses the button now leaves a `xf_flood_check` row under the
  `cav7_discord_resync` key, where before only non-holders did. XenForo's hourly
  cleanup prunes them a day after they were last touched, so nothing accumulates.
- The failure branch that hands the cooldown back still hands it back to everyone, and
  that release is now a real release rather than a no-op for most members. What it
  un-bounds is unchanged and is argued at the call site.
- A future edit that "tidies" the direct service call back into `assertNotFlooding()`
  would silently restore the original defect. `tests/WiringTest.php` matched the
  source for the absence of `assertNotFlooding()` for this reason; that file has since
  been removed (see the note above), because — as this entry already said when it was
  written — the check proved nothing about behaviour.
  `tools/discord-resync-cooldown-check.php` is what presses the button twice as a
  permission holder against a live stack, and CI cannot run it. That is the guard.
- This addon now depends on `FloodCheckService::checkFlooding()`'s signature and its
  atomicity, where before it depended on `assertNotFlooding()`'s. Both are core, and
  the swap is recorded with the other core assumptions in the docblock of
  `XF/Pub/Controller/Account.php`.
