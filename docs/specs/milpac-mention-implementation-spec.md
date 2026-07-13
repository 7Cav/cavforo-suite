# Cav7/MilpacMention — implementation spec

Implementation-ready spec for a new addon, `Cav7/MilpacMention`. It assembles
every decision made on the wayfinder map
[Milpac mention notifications (Cav7/MilpacMention)](https://github.com/7Cav/cavforo-suite/issues/65)
into one hand-off for a build session. Nothing here is an open question; where a
detail can only be confirmed with running code, it is called out explicitly in
[§8 Build-time checklist](#8-build-time-checklist).

Two research spikes ground this spec and should be read alongside it:

- Engine: [`docs/research/milpac-mention-engine-hook-point.md`](../research/milpac-mention-engine-hook-point.md)
- `$name` completer: [`docs/research/milpac-mention-name-autocomplete-feasibility.md`](../research/milpac-mention-name-autocomplete-feasibility.md)

Decision provenance, one line each (full detail in each closed ticket):

- Engine hook-point — [#66](https://github.com/7Cav/cavforo-suite/issues/66)
- `$name` feasibility — [#67](https://github.com/7Cav/cavforo-suite/issues/67)
- Notification behaviour rules — [#68](https://github.com/7Cav/cavforo-suite/issues/68)
- Alert content + preferences — [#69](https://github.com/7Cav/cavforo-suite/issues/69)
- `$name` UX design — [#70](https://github.com/7Cav/cavforo-suite/issues/70)

> **Vendor line numbers drift.** XenForo core (`XF\…`) and the NetworkedForums
> addons (`NF\Rosters`, `NF\Tickets`, `NF\Calendar`) are third-party and are not
> vendored in this repo. File:line references to them are navigation aids against
> the current dev install (`~/srv/xenforo-dev`) and move with vendor upgrades.
> A "milpac" is one `NF\Rosters\Entity\RosterUser` row; its profile URL is
> `/rosters/profile/<relation_id>/`, where `relation_id` is the `RosterUser`
> primary key and the row carries a `user_id`.

---

## 0. What this addon does, in one paragraph

When a member's milpac (their roster-profile link, `/rosters/profile/<relation_id>/`)
appears in a post, profile post, profile-post comment, report comment, or ticket
message, that member gets a distinct, toggleable `milpac_mention` alert — the same
reach as an `@`-mention, on every surface where `@` already fires. That is the
**engine**, and it is the part that has to ship. A second, staged layer adds a
`$name` editor autocomplete that inserts a milpac link by username the way `@`
works, so staff stop hand-building roster links. `$name` is only an input method:
the links it inserts flow through the same engine, with no second notification path.

The engine ships first and stands alone. `$name` layers on top later.

---

## 1. Addon identity

| Field | Value |
| --- | --- |
| Add-on id | `Cav7/MilpacMention` |
| PHP namespace root | `Cav7\MilpacMention` |
| Directory | `src/addons/Cav7/MilpacMention/` |
| Title | `7Cav - Milpac Mention` |
| Vendor | `Cav7` (GitHub org is `7Cav`; PHP namespaces can't start with a digit) |

Copy [`_skeleton/`](../../_skeleton/) and adjust, per
[`docs/addon-format.md`](../addon-format.md).

### Dependencies (`addon.json` `require`)

- `XF`: the install's floor. NF/Tickets is XF 2.3-era, so require `2030070`
  ("XenForo 2.3.0+"), matching the class shapes this spec targets.
- `NF/Rosters`: hard dependency — the addon resolves `relation_id` → `user_id`
  against `NF\Rosters:RosterUser`. Declare it (`2010000`, "Rosters 2.1+"), as
  `Cav7/MilpacTooltip` already does.
- `NF/Tickets`: **not** a hard dependency. The ticket surface is optional. The
  addon covers tickets when NF/Tickets is present and degrades to the four core
  surfaces when it isn't. Guard the tickets extensions with
  `dependsOnAddOnId`/`depends_on_addon_id => 'NF/Tickets'` and make the firing
  extension a no-op if the class is absent, rather than declaring a `require`
  that would block install on sites without tickets. (Confirm at build whether
  the org's install always ships NF/Tickets; if it does and you would rather fail
  loudly, promote it to `require`. Default: soft dependency.)

No `addon_id`, `namespace`, or `setup` keys — XenForo derives those from the path
(`xf-addon:validate-json` rewrites the file if you add them).

### Setup.php

**Not needed.** The addon creates no tables, options, or fields. It is
class-extensions plus data items (alert templates, opt-out phrases, one route,
and phase-2 JS). Per [`docs/addon-format.md`](../addon-format.md), a
class-extension-only addon needs no `Setup.php`. Alert rows self-clean on
uninstall because each `alert()` call carries `depends_on_addon_id =>
'Cav7/MilpacMention'` (`UserAlertRepository::insertAlert` honours it;
`findAlertsForUser` filters inactive addons).

---

## 2. The engine (phase 1 — ships first)

The engine has two layers:

1. **Detection** — one shared class-extension that finds milpac links on every
   surface at once and resolves them to members.
2. **Firing** — a thin per-surface extension that raises the distinct
   `milpac_mention` alert for the resolved members, reusing each surface's own
   alert handler.

The split is deliberate. Detection is a single seam so it can never miss a
surface. Firing is per-surface because the map's destination requires a
*distinct, member-toggleable* alert with its own copy — which the pure
"merge-and-reuse-the-stock-mention-alert" shortcut can't give (it would read "X
mentioned you" and share the `mention` toggle). See
[#66](https://github.com/7Cav/cavforo-suite/issues/66) for why the shared hook
wins over per-dispatcher detection, and
[#69](https://github.com/7Cav/cavforo-suite/issues/69) for why the distinct alert
is worth the per-surface firing cost.

### 2.1 The five surfaces

There are **five** live mention surfaces, not four. Enumerating "the four core
dispatchers" silently drops NF/Tickets:

| Surface | Content type | Notifier (fires the alert) | Alert handler |
| --- | --- | --- | --- |
| Post | `post` | `XF\Service\Post\NotifierService` (loads `XF\Notifier\Post\Mention`) | `XF\Alert\PostHandler` |
| Profile post | `profile_post` | `XF\Service\ProfilePost\NotifierService` | `XF\Alert\ProfilePostHandler` |
| Profile-post comment | `profile_post_comment` | `XF\Service\ProfilePostComment\NotifierService` | `XF\Alert\ProfilePostCommentHandler` |
| Report | `report` | `XF\Service\Report\NotifierService` | `XF\Alert\ReportHandler` |
| Ticket message | `nf_tickets_message` | `NF\Tickets\Service\Message\Notifier` (loads `NF\Tickets:Message\Mention`) | `NF\Tickets\Alert\Message` |

- **NF/Calendar** rides the linked-discussion **Post** path (its event preparer
  extracts mentions, but the alert fires through the thread's Post notifier), so
  covering Post covers it. No calendar-specific work.
- **NF/Rosters** adds no mention surface. Expected — it stores roster data, not
  message content.
- The notifier shapes are **not** uniform. Post and NF/Tickets use the
  `loadNotifiers()` / `AbstractNotifier` collection pattern; ProfilePost,
  ProfilePostComment, and Report use bespoke `notify()` / `notifyMentioned()`
  methods. Each of the five firing extensions therefore looks different — budget
  for five small, distinct extensions, not one shape ×5.

### 2.2 Detection — the shared hook

Class-extend `XF\Service\Message\PreparerService::prepare()`. After
`parent::prepare()`, the message is BbCode-processed text, so every surface's
recipients have been computed and the literal roster hrefs are present.

```php
namespace Cav7\MilpacMention\XF\Service\Message;

class PreparerService extends XFCP_PreparerService
{
    public function prepare($message, $rules = ..., ...)   // match the parent signature exactly
    {
        $message = parent::prepare($message, $rules, ...);

        // 1. Extract relation_ids from every roster-profile link in the message.
        // 2. Resolve relation_id -> user_id (§2.3).
        // 3. Cap against the author's remaining mention budget and stash the
        //    surviving user_ids for the firing layer to read (§2.5).

        return $message;
    }
}
```

**Extraction regex** — one path-segment match catches both a bare auto-linked URL
and a `[URL=…]…[/URL]` hyperlink, relative or canonical:

```
#/rosters/profile/(\d+)#
```

Capture group 1 is `relation_id`. The route is
`profile/:int<relation_id,username>/`, so an optional `-slug` suffix follows the
int and the regex ignores it. Dedupe the captured ids before resolving.

### 2.3 Resolution — `relation_id` → `user_id`

Reuse the finder `Cav7/MilpacTooltip` uses in the forward direction
(`\XF::finder('NF\Rosters:RosterUser')`,
`MemberMilpacTooltip::renderMilpac`), run in reverse. `relation_id` is the PK,
`user_id` is a column with a `User` relation:

```php
$rows = \XF::finder('NF\Rosters:RosterUser')
    ->where('relation_id', $relationIds)
    ->fetch();
$userIds = $rows->pluckNamed('user_id', 'relation_id'); // [relation_id => user_id]
```

One user = one milpac = one `relation_id` is an **invariant** (the roster stores
one lifecycle row per member; positions and billets are columns, not extra rows).
So resolution is a straight lookup with no tiebreak.

Factor this into a small shared resolver class (e.g.
`Cav7\MilpacMention\MilpacResolver`) so the detection hook and the phase-2
find-endpoint (§4) share one implementation and one test.

### 2.4 Firing — the distinct `milpac_mention` alert

The shared hook does **not** merge milpac users into the stock mention set (that
would fire "X mentioned you" and share the `mention` toggle). Instead each
surface's notifier extension raises a distinct alert for the stashed, milpac-only
recipients:

```php
// Inside each surface's NotifierService extension, after the stock mention pass:
$alertRepo->alert(
    $user,                 // recipient
    $author->user_id,
    $author->username,
    '<contentType>',       // post | profile_post | profile_post_comment | report | nf_tickets_message
    $contentId,
    'milpac_mention',      // the action — this is the only new action string
    $extraData,
    ['depends_on_addon_id' => 'Cav7/MilpacMention']
);
```

- **No new content type and no new alert handler.** The milpac link always lives
  inside an existing post / comment / report / ticket message, so the action
  reuses that content's existing handler. XF resolves the handler purely by the
  `(contentType)` pair and the template by `(contentType, action)`, so adding the
  action + templates + phrases is all that's needed.
- **The alert deep-links to the content** — the post, comment, report, or ticket
  where the link appears — the same as an `@`-mention. This falls out of the
  reused content handler for free.
- **Prior art in this repo:** `Cav7/EnlistmentReminder` already registers a custom
  `enlistment_reminder` action on the core `thread` content type via
  `XF\Alert\ThreadHandler::getOptOutActions()`, with an
  `alert_opt_out.thread_enlistment_reminder` phrase and an
  `alert_thread_enlistment_reminder` template. `milpac_mention` is the same
  pattern, applied five times.

### 2.5 Firing rules (from [#68](https://github.com/7Cav/cavforo-suite/issues/68))

Every rule tracks `@`-mention behaviour. Under the pure "merge into the mention
set" shortcut these came free; under the **distinct** alert the firing layer must
enforce them itself, because it no longer rides the core `Mention` notifier. All
six are invariants for the build; the mechanism is the recommended one, the
outcomes are non-negotiable.

1. **Self-links suppressed.** A member linking their own milpac is not alerted.
   The firing extension must repeat `recipient->user_id != author->user_id`
   (the core `Mention::canNotify` won't run for the distinct action). Skipping
   the author's own `relation_id` early in the hook also avoids a pointless load.
2. **Multiplicity → one alert.** Any number of links to the same member yields a
   single alert. Free: resolution is keyed by `user_id`, duplicate keys collapse.
3. **Every roster notifies.** Resolution maps `relation_id` → `user_id` regardless
   of which roster (Combat, Reserve, ELOA, Past members, Wall of Honor, Arlington
   — all lifecycle states, one row per member) the row sits on. No per-roster
   allow/deny list. Deliverability is then decided by the surface's own gating
   (§2.6).
4. **No notification on edit.** XF wires mentions into a notifier only from the
   *creator* and *replier* paths, never the editor path, so it fires no mention
   alert on edit — for added mentions or pre-existing ones. The hook runs on edit
   (`prepare()` does), but nothing downstream fires, so a milpac link added by a
   later edit inherits "new content only" with no extra code. **Do not** add an
   edit-time firing path.
5. **Dedup with `@`, preferring `@`.** A member both `@`-mentioned and
   milpac-linked in the same content gets **one** alert — the native `@` one.
   Fire `milpac_mention` only for users who are milpac-linked **and not** already
   in that content's `@`-mention set. (Accepted tradeoff: in the overlap case the
   member sees the ordinary mention wording, not milpac-specific copy.)
6. **Shared author cap.** Milpac links count against the author's
   `general:maxMentionedUsers` budget alongside `@`-mentions — they do not get a
   separate or uncapped budget. `@`-mentions are kept and milpac links drop first
   on overflow (append milpac after `@`). The addon reads no number: apply the
   author's resolved `getAllowedUserMentions` cap (`XF\Entity\User`) to the
   combined set so per-user and per-group permission overrides carry automatically
   (Registered = 5 in this install, staff unlimited). Parity was chosen over
   exemption deliberately — exempting would hand every member an uncapped
   mass-ping, the exact thing the cap exists to stop.

> **Correction carried from #66 → #68.** #66 labelled the cap recipient
> "mention-privacy." That is wrong. `getAllowedUserMentions` is an **author-side
> cap** that slices the mention set to the first N by the *author's*
> `maxMentionedUsers` permission. Core XF has **no** per-recipient mention
> opt-out. The spec and any comments must not describe it as a privacy filter.

### 2.6 Gating parity — free

Injecting/notifying through each surface routes recipients through that surface's
own `canView` before it alerts (Post `AbstractNotifier`, ProfilePost/Comment
`getUsersForNotification`, Report `NotifierService`, NF/Tickets `Notifier`). A
member who can't see a report or a hidden ticket is filtered out and never
alerted to a milpac link inside it. No extra code — verify parity holds in tests
(§7) rather than re-implementing it.

### 2.7 Moderation-approval behaviour — free, verified

When moderated content is approved later, a milpac link fires (or stays silent) in
step with the surface's own re-notification. This falls out of the same-instance
stash design and was verified against the dev install:

- **Post** and **ticket message** re-run the message preparer on approval
  (`XF\Service\Post\ApproverService`, `NF\Tickets\Service\Message\Approver` both call
  `setMessage()`, which runs `prepare()`). The shared detection hook re-runs with it
  and re-stashes on the same entity the approver's notifier reads, so milpac fires on
  approval on both surfaces.
- **Profile post** and **profile-post comment** approvers call `notify()` only, with
  no preparer re-run, so nothing re-stashes and milpac stays silent. XF's own
  `mention` is also silent here (the approver sets no mentioned users), so parity
  holds by both staying quiet.
- **Report** has no approval queue, so the case does not arise.

One deliberate deviation, on the **Post** surface: XF core does not re-fire its own
`@`-mention on approval — `Post\ApproverService` carries a standing
`// TODO: this doesn't solve mentioned user IDs` and sets only quoted users — while
milpac *does* fire, because detection rides the preparer the approver re-runs for
those quotes. Milpac is therefore marginally ahead of `@` on this one path. This is
accepted, not fixed: the alert goes to a member genuinely linked in now-visible
content, and the inconsistency is XF's `@` under-firing rather than milpac
over-firing. No code withholds the milpac alert to mirror the XF limitation. (Filed
and closed as [#101](https://github.com/7Cav/cavforo-suite/issues/101), whose
original premise — that milpac *fails* to fire on approval — the code does not match.)

---

## 3. Notification preferences and copy (from [#69](https://github.com/7Cav/cavforo-suite/issues/69))

### 3.1 Toggles — mirror XF's `mention` opt-out, surface for surface

XF stores opt-outs as `"{contentType}_{action}"` in
`xf_user_option.alert_optout` and builds the preferences screen from each
handler's `getOptOutActions()`, labelling each row with the phrase
`alert_opt_out.{contentType}_{action}`. Register `milpac_mention` **exactly where
XF registers `mention` as opt-out-able, and omit it exactly where XF omits it.**

- **Three opt-out rows**, via `getOptOutActions()` overrides:
  - `XF\Alert\PostHandler` → add `milpac_mention`
  - `XF\Alert\ProfilePostHandler` → add `milpac_mention`. This is the only
    profile-surface opt-out, and it mirrors XF exactly: XF registers the `mention`
    opt-out on `ProfilePostHandler` (content type `profile_post`) and **not** on
    `ProfilePostCommentHandler`. Comment mentions fire under their own content type
    `profile_post_comment`, and XF's opt-out check keys on `"{contentType}_{action}"`,
    so the `profile_post` row never governs a comment alert — it does not "cover
    both surfaces". Stock XF gives no way to mute a mention made in a profile-post
    comment, and `milpac_mention` inherits that: comment milpac alerts are always
    on. Do **not** add a comment-handler override.
  - `NF\Tickets\Alert\Message` handler → add `milpac_mention`
- **No opt-out on Report.** Being named in a report can't be muted in XF; match
  that — `milpac_mention` stays non-toggleable there.
- **Default: on**, like every other XF alert. Members who don't want it use the
  toggle above.

> **Comment mentions are unmutable, by XF parity (verified §8.5).** XF's own
> `profile_post` opt-out is *labelled* "Mentions you in a profile post or comment",
> but the toggle does not actually mute comment mentions — they fire under
> `profile_post_comment`, which registers no opt-out at all. The milpac copy
> mirrors that label verbatim ("Links your milpac in a profile post or comment",
> §3.2), inheriting XF's slightly-misleading wording on purpose so a member who
> knows XF's toggle sees identical behaviour. If the wording ever draws a real
> complaint, the fix is to raise it upstream with XF and correct both toggles
> together; to date it has not.

### 3.2 Copy

Five alert templates `alert_<contentType>_milpac_mention`, verb **"linked your
milpac"**, mirroring XF's mention wording. This copy was passed through
`/humanizer` in [#69](https://github.com/7Cav/cavforo-suite/issues/69) and
**ships unchanged** — do not re-run it or reword it.

| Content type | Template | Alert line |
| --- | --- | --- |
| `post` | `alert_post_milpac_mention` | `{name} linked your milpac in a post in the thread {title}` |
| `profile_post` | `alert_profile_post_milpac_mention` | `{name} linked your milpac in a message on {profile}'s profile` |
| `profile_post_comment` | `alert_profile_post_comment_milpac_mention` | `{name} linked your milpac in a comment on {profile}'s profile` |
| `report` | `alert_report_milpac_mention` | `{name} linked your milpac in a comment in the report {title}` |
| `nf_tickets_message` | `alert_nf_tickets_message_milpac_mention` | `{name} linked your milpac in a message in the ticket {title}` |

Three opt-out phrases `alert_opt_out.<contentType>_milpac_mention`, mirroring
XF's "Mentions you in a message" family:

| Phrase key | Label |
| --- | --- |
| `alert_opt_out.post_milpac_mention` | `Links your milpac in a message` |
| `alert_opt_out.profile_post_milpac_mention` | `Links your milpac in a profile post or comment` |
| `alert_opt_out.nf_tickets_message_milpac_mention` | `Links your milpac in a ticket` |

> The exact NF/Tickets content-type token (`nf_tickets_message` above) is taken
> from the engine spike; confirm it against the addon's registered alert content
> type at build and use whatever NF/Tickets actually registers for both the
> template and the opt-out phrase keys.

Optional `push_<contentType>_milpac_mention` templates may be added if push
copy should differ from the on-site line; otherwise XF falls back to the alert
template.

---

## 4. The `$name` layer (phase 2 — staged on top of the engine)

`$name` is an **editor autocomplete only**. It inserts a well-formed roster link
that the phase-1 engine already detects; it adds **no** notification path. Ship it
after the engine is live. Feasibility and the four residual risks:
[#67](https://github.com/7Cav/cavforo-suite/issues/67); UX decisions:
[#70](https://github.com/7Cav/cavforo-suite/issues/70).

### 4.1 Trigger: `$`

`$`, not `!`. The feature was written up as `!name`, but members live in Discord
where `!command` is muscle memory, so `!<word>` collides often here; `$` almost
never precedes a word in prose or chat. **Drop the `!name` naming everywhere** —
the feature is `$name`.

Wire a third `XF.AutoCompleter` beside XF's `@` and `:` completers, with
`at: '$'`, attached through the **bubbling `editor:init` event** (`XF.on(document,
'editor:init', …)`), so it overrides no core method. That seam is upgrade-safe —
XF's own `editor_manager.js` and the shipped `SV/AdvancedBBCode` addon already
consume the same event. The constructor accepts any single-character `at`.

```js
XF.on(document, 'editor:init', e => {
    const { ed } = e   // Froala instance; ed.$el[0] is the editable
    new XF.AutoCompleter(ed.$el[0], {
        url: XF.canonicalizeUrl('index.php?milpac-mention/find'),
        at: '$',
        keepAt: false,        // consume the '$' on insert, emoji-style
        insertMode: 'html',
        displayTemplate: /* rank + name primary, roster secondary (§4.5) */,
    }, ed)
})
```

### 4.2 What it inserts: a plain named anchor

`$name` inserts the milpac's **rank and name** as a named link:

```
[URL='https://…/rosters/profile/<relation_id>/']Rank Name[/URL]
```

rendered in the rich editor as `<a href="/rosters/profile/N/">Rank Name</a>`
(display text e.g. "Corporal Banfield.H"). This reproduces the workflow it
replaces: of 66,889 posts that link a roster profile, 57,325 (86%) are already
this exact named-link shape. A bare URL is **not** acceptable — it drops the name
that makes the link useful.

No new render path. The anchor is the same artifact the engine detects, so
`$name` closes the loop through the one existing notification path.

### 4.3 Population: mirror `@` exactly

The find endpoint copies `XF\Pub\Controller\MemberController::actionFind`. Require
`q` length ≥ 2, then match usernames that own a milpac:

```php
$userFinder
    ->where('username', 'like', $userFinder->escapeLike($q, '?%'))
    ->isValidUser(true)   // not banned, user_state=valid, active within 180 days
    // INNER JOIN NF\Rosters:RosterUser so only milpac owners return
    ->fetch(10);
```

- The roster **join sits inside the query, before the limit**, so the result is
  ten milpac-owning actives — not ten actives then filtered down.
- Members with no milpac fall out through the join, the way a non-mentionable user
  is absent from `@` results.
- `isValidUser(true)` carries the 180-day activity filter, scoping the completer
  to current members with no roster-specific rule (≈1,715 milpac holders pass,
  down from 5,946; dormant past and memorial members drop out).
- **Population does not constrain the engine.** The engine still detects any
  manually pasted roster link to any member, active or not, per `@`-parity. The
  completer is only the input convenience.

Endpoint contract is standard XF autocomplete: GET `{q}`, return `{q, results}`;
each row carries the display fields (§4.5) and the HTML insert value (§4.2).
Reuse the shared `MilpacResolver` (§2.3) for the join.

### 4.4 Disambiguation: none

One user = one milpac = one `relation_id` (invariant, §2.3). No picker, no
tiebreak — a second row would be a data bug, not a state to design for.

### 4.5 Dropdown rows

Primary line: rank and name. Secondary: roster (e.g. "Corporal Banfield.H ·
Combat"). The roster line does real work — 752 active past members share the pool
with current members, so same-name collisions happen and the roster tells the
author what standing they're about to link. A rank-insignia thumbnail is an
optional build-time nicety; it's pure completer chrome and never touches the
saved link.

### 4.6 Editor-mode fallbacks and input edges

- **Plain-BBCode / mobile.** When the rich editor is off, the completer inserts
  **text**, and the inserted string is ours to set — insert the BBCode
  `[URL='…/rosters/profile/N/']Rank Name[/URL]` as text (round-trips to the same
  named link and the same engine detection), **not** a bare URL. Author sees raw
  BBCode brackets while composing, as plain-BBCode mode already shows every tag.
  Fidelity stays uniform across editor modes. (The spike's "bare URL only" reading
  was the emoji completer's design choice, not a class limit.) Register a parallel
  `milpac-mentioner` element handler on the plain BBCode box, beside XF's
  `user-mentioner` / `emoji-completer`.
- **Draft, preview, paste.** No special handling. `$name` inserts an ordinary
  anchor with no hidden token, so it drafts, previews, and saves like any link,
  and the engine reads the link out of the saved message either way. `@` itself
  inserts plain text that resolves on save, so it has no editor-side token to
  preserve either. **Paste is not intercepted** — a pasted roster URL is already
  covered by the engine. There is no paste-linkify feature.

### 4.7 Discoverability

No toolbar button — `@` has none and is pure convention; mirror it.
Discoverability rides on a one-time announcement plus an SOP or help note. Primary
users are staff who use it constantly and learn `$` on day one. A toolbar button
stays available as a later enhancement if adoption lags.

### 4.8 The four build-time risks from the [#67](https://github.com/7Cav/cavforo-suite/issues/67) spike

Carry these into the `$name` build; they are build-time details, not blockers:

1. **Result-row insert-value shape.** Confirm the exact field a selected row
   exposes as the HTML insert value `insertResult` receives, and the
   `displayTemplate` field names — modelled on `find-emoji`, verified against the
   running endpoint.
2. **Anchor round-trip.** Confirm the inserted `<a href="/rosters/profile/N/">`
   survives Froala's HTML→BBCode serialisation as a link whose href still contains
   `/rosters/profile/N/` (not stripped, rel-mangled, or shortened past the engine
   regex). Low-risk (auto-linked URLs and `[URL=…]` both retain the literal path)
   but this is the one behaviour only a running prototype nails down.
3. **Plain-BBCode / mobile fidelity.** Resolved to "named BBCode, not a bare URL"
   (§4.6) — verify the round-trip in that mode too.
4. **Trigger firing mid-word.** Confirm `$name` opens only after a word boundary
   and never fires mid-word in ordinary prose. `$` was chosen partly to minimise
   this; verify the feel during the build.

---

## 5. File manifest (what the build creates)

Under `src/addons/Cav7/MilpacMention/`:

**Phase 1 — engine**

```
addon.json
README.md                                        # description, requirements, provenance note
CONTEXT.md                                        # optional: addon-local glossary, links suite CONTEXT.md
MilpacResolver.php                                # shared relation_id -> user_id reverse resolver
XF/Service/Message/PreparerService.php            # shared detection hook (§2.2)
XF/Service/Post/NotifierService.php               # fire milpac_mention on post (§2.4)
XF/Service/ProfilePost/NotifierService.php        # "
XF/Service/ProfilePostComment/NotifierService.php # "
XF/Service/Report/NotifierService.php             # "
NF/Tickets/Service/Message/Notifier.php           # " (guarded by NF/Tickets present)
XF/Alert/PostHandler.php                          # getOptOutActions += milpac_mention (§3.1)
XF/Alert/ProfilePostHandler.php                   # "
NF/Tickets/Alert/Message.php                      # " (guarded by NF/Tickets present)
_data/*.xml + _output/…                           # class_extensions, 5 alert templates,
                                                  #   3 opt-out phrases, 5 alert-line phrases
tests/*.php                                        # §7
```

**Phase 2 — `$name`**

```
Pub/Controller/MilpacMention.php                  # actionFind, mirrors MemberController::actionFind (§4.3)
_data/routes.xml (+ _output)                       # route: milpac-mention/find
_assets/… (addon JS)                               # $ AutoCompleter on editor:init (§4.1); rich + plain handlers
_data/templates + template_modification            # deliver/load the JS; displayTemplate for rows
```

Every extension is registered in `_data/class_extensions.xml` (fromClass →
`Cav7\MilpacMention\…`). Follow the `_data/` + `_output/` export workflow in
[`docs/addon-format.md`](../addon-format.md) — edit through the admin CP in dev
mode, then `xf-addon:export`; don't hand-edit either tree.

---

## 6. Phasing

- **Phase 1 (engine)** is the shippable unit and the priority. It stands alone:
  members already hand-build roster links today (86% of 66,889 existing links),
  so the engine delivers notifications for the current manual workflow with no
  editor change.
- **Phase 2 (`$name`)** layers on with no change to the engine — it only inserts
  links the engine already handles. It can ship as a later version of the same
  addon.

Split the two into separate build sessions / PRs. Phase 2 must not be a
prerequisite for phase 1 review or release.

---

## 7. Test strategy

This repo's addons carry **standalone PHP tests** (`tests/*.php`), no XenForo and
no framework — each is a self-contained script that exits non-zero on failure,
run by `tools/run-tests.sh MilpacMention` (see `Cav7/EnlistmentReminder/tests/`
for the shape). Tests pin the vendor-coupled wiring so a regression fails CI
rather than shipping silently. Cover:

**Pure logic (fully unit-testable, no XF):**

- **Detection regex.** `#/rosters/profile/(\d+)#` against a table of inputs:
  relative (`/rosters/profile/42/`), canonical
  (`https://board/rosters/profile/42-slug/`), inside `[URL=…]`, bare auto-link,
  multiple links in one message, no match, and the dedupe of repeated ids.
- **Resolution / cap logic.** `MilpacResolver` maps `relation_id` → `user_id`;
  the cap+dedup helper enforces the six firing rules as pure functions where
  possible: self-skip (author dropped), multiplicity (one entry per user),
  `@`-dedup (milpac set minus `@` set), and the shared-cap ordering (`@` kept,
  milpac dropped first on overflow to N).

**Wiring pins (assert the data items exist and are shaped right — self-contained
reads of `_output/`, EnlistmentReminder-style):**

- Five class-extensions registered against the five notifier classes plus the
  shared `PreparerService`, and the three alert-handler extensions.
- Five `alert_<type>_milpac_mention` templates present; three
  `alert_opt_out.*_milpac_mention` phrases present with the exact §3.2 labels.
- `getOptOutActions()` overrides add `milpac_mention` on Post, ProfilePost, and
  NF/Tickets handlers, and **not** on Report or the comment handler.
- Every `alert()` call passes `depends_on_addon_id => 'Cav7/MilpacMention'`.

**Behaviour that needs a live XF + NF/Rosters (integration, gated like
`EnlistmentReminder/tests/ScanWiringTest.php`):**

- Gating parity: a member who can't view a report/hidden ticket receives no
  `milpac_mention` for a link inside it.
- No alert on edit: adding a milpac link by editing an existing post fires
  nothing.
- End-to-end on each of the five surfaces: a milpac link in new content produces
  exactly one `milpac_mention` to the linked member, with the right copy, and the
  `@`-overlap case produces one `@` alert and no milpac alert.

**Phase 2:**

- `milpac-mention/find` returns `{q, results}`, requires `q` length ≥ 2, and
  returns only milpac-owning `isValidUser(true)` members via the inner join.
- The anchor round-trip (risk §4.8.2) — best exercised with a running prototype /
  the `/verify` flow, since HTML→BBCode serialisation can't be asserted by a
  static read.

---

## 8. Build-time checklist

Decisions are settled; these are the confirmations a build session must make
against running code before or during implementation. None is an open design
question.

1. **Extension not bypassed on tickets.** NF/Tickets extends the same class via
   the legacy alias `XF\Service\Message\Preparer`. Confirm our
   `XF\Service\Message\PreparerService` extension and the firing extension on
   `NF\Tickets\Service\Message\Notifier` both resolve into one inheritance chain
   and are not bypassed on the ticket surface. (Carried from #66; #69 extended it
   to cover the firing extension, not just detection.)
2. **`prepare()` signature.** Match the parent `PreparerService::prepare()`
   signature exactly against the installed XF version.
3. **Cap mechanism.** The six firing rules (§2.5) are invariants; the exact
   mechanism for honouring the shared cap while firing a *distinct* alert (apply
   `getAllowedUserMentions` to the union and tag milpac-sourced users, vs. cap a
   separate stash against the same remaining budget) is a build choice. Whichever
   you pick, assert the observable outcomes in tests: no double-alert, `@` kept
   over milpac on overflow, self-skip, and one alert per member.
4. **NF/Tickets content-type token.** Confirm the exact registered alert content
   type (`nf_tickets_message` is the spike's reading) and use it consistently in
   the template and opt-out phrase keys.
5. **Profile-post-comment opt-out coverage — confirmed.** XF fires comment
   mentions under content type `profile_post_comment` and registers no opt-out for
   them (`ProfilePostCommentHandler::getOptOutActions()` omits `mention`), so no
   toggle mutes a comment mention. The `profile_post` row governs `profile_post`
   alerts only — it does not cover comments. `milpac_mention` reproduces this: three
   opt-out rows, none on the comment handler, and comment milpac alerts stay on.
   (Verified against the dev install; see §3.1.)
6. **The four `$name` spike risks** (§4.8), especially the anchor HTML→BBCode
   round-trip.

---

## 9. Out of scope

- **Richer rendering** of a linked milpac (a hovercard on the roster link, or an
  inline rank-coloured chip). That belongs to `Cav7/MilpacTooltip`'s display
  layer, not this addon, and is filed as
  [Visual styling for in-post milpac links](https://github.com/7Cav/cavforo-suite/issues/83).
  BBCode strips any class off the saved link, so styling has to be a display-layer
  decoration keyed on `a[href*="/rosters/profile/"]`, uniform across manual and
  `$name` links.
- **Back-notifying** for milpac links posted before the addon is installed.
  Notifications are forward-only.
- **DMs / conversations.** Conversations dispatch no mention alert in XF, so they
  stay out by parity — no work, and no attempt to add one.
```
