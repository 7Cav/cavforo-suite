# Cav7/MilpacMention: engine hook-point research spike

Research spike only. No addon code was written. XenForo core (`XF\…`) and the
NetworkedForums addons (`NF\Rosters`, `NF\Tickets`, `NF\Calendar`) are third-party
code and are not vendored in this repository. Paths to them below are given relative
to a standard XenForo `app/src/` tree in a local dev install (see CONTRIBUTING.md);
paths inside this repository are repo-relative. Line numbers are navigation aids
against the current dev install and drift with vendor upgrades. A "milpac" is one
`NF\Rosters\Entity\RosterUser` row; its profile URL is `/rosters/profile/<relation_id>/`,
where `relation_id` is the `RosterUser` primary key and the row carries a `user_id`.

---

## 1. Is the mention seam really just four dispatchers?

In XF core, yes: four, and only four. Method: `rg "'mention'"` over the XF core tree
(9 hits), plus `rg "setMentionedUserIds|setNotifyMentioned"` (the setters that seed a
mention alert) and `rg "getMentionedUserIds|getMentionedUsers"` (the consumers). The
four places that actually fire a `mention` alert:

- **Post**: `XF/Service/Post/NotifierService.php:65` loads the `XF\Notifier\Post\Mention`
  notifier, whose `sendAlert()` calls `basicAlert(… 'post', post_id, 'mention')` at
  `XF/Notifier/Post/Mention.php:29-40`, which runs through `XF/Notifier/AbstractNotifier.php:63-92`.
- **ProfilePost**: `XF/Service/ProfilePost/NotifierService.php:64` dispatches
  `sendNotification(…, 'mention')`, which calls `alertRepo->alert(… 'profile_post', …, 'mention')`
  at `:97-126`.
- **ProfilePostComment**: `XF/Service/ProfilePostComment/NotifierService.php:99` calls
  `alert(… 'profile_post_comment', …, 'mention')` at `:144-173`.
- **Report**: `XF/Service/Report/NotifierService.php:101-119` `notifyMentioned()` runs
  `sendMentionNotification()`, which calls `alert(… 'report', …, 'mention')` at `:149-176`.

The callers that seed those dispatchers (the "surfaces"): `Thread/ReplierService.php:269`
and `Thread/CreatorService.php:570` (both feed the Post notifier), `ProfilePost/CreatorService.php:129`,
`ProfilePostComment/CreatorService.php:129`, `Report/CommenterService.php:177`. Conversations
do not fire mention alerts (a grep of `Service/Conversation` is empty).

But "four" is only true for stock core. Installed vendor addons add surfaces:

- **NF/Tickets adds a genuine fifth dispatcher.** `NF/Tickets/Service/Message/Notifier.php:82`
  loads `'mention' => notifier('NF\Tickets:Message\Mention')` (the same AbstractNotifier
  pattern as Post), with its own alert handler `NF/Tickets/Alert/Message.php` (opt-out action
  `'mention'` at `:21`). It is seeded from `Ticket/Creator.php:603`, `Replier.php:320`, and
  `Approver.php:67`.
- **NF/Calendar does not add its own mention notifier.** Its event NotifierService carries
  only cancel, reinstate, and alertRespondents notifiers
  (`NF/Calendar/Service/Event/NotifierService.php:164-182`). It does extract mentioned users
  (`NF/Calendar/Service/Event/PreparerService.php:105`), but the alert for a description
  `@`-mention rides the linked-discussion Post path, so covering Post covers it.
- **NF/Rosters adds no mention surface** (`find NF/Rosters -iname '*mention*' -o -iname '*notifier*'`
  is empty), which is expected: it stores roster data, not message content.

Consequence: a model that enumerates "four dispatchers" silently misses NF/Tickets today,
and any future addon surface tomorrow. This is the decisive input to section 2.

---

## 2. Hook decision: extend each dispatcher, or one shared seam

Every surface, all four core plus NF/Tickets and NF/Calendar, obtains its mention recipients
from the same choke point. The content preparer calls `XF\Service\Message\PreparerService`,
whose `prepare()` sets `$this->mentionedUsers` at `XF/Service/Message/PreparerService.php:158`
(map shape `[user_id => User]`, built at `XF/Str/MentionFormatter.php:281`). The confirmed
consumers all instantiate the base `XF\Service\Message\PreparerService`:

- `Post/PreparerService.php:107`, `ProfilePost/PreparerService.php:72,84`,
  `ProfilePostComment/PreparerService.php:72,84`, `Report/CommentPreparerService.php:48`,
  `NF/Calendar/Service/Event/PreparerService.php:105,118`,
  `NF/Tickets/Service/Message/Preparer.php:194,228` (via the legacy alias `\XF\Service\Message\Preparer`).

### Option (a): class-extend each NotifierService

Fire a distinct `milpac_mention` alert from inside each dispatcher, reusing that surface's
already-computed recipient and visibility gating.

- Pro: allows distinct alert copy and opt-out; gating reused.
- Con: this is not four extensions but five (four core plus NF/Tickets), and the shapes are
  not uniform. Post and NF/Tickets use the `loadNotifiers()` collection pattern
  (`AbstractNotifier`), while ProfilePost, ProfilePostComment and Report use bespoke
  `notify()`/`notifyMentioned()` methods, so each hook looks different. Any future addon
  mention surface is silently missed until someone adds a sixth extension, which is exactly
  the fragility this addon needs to avoid.

### Option (b): one shared seam at the mention-extraction layer (recommended)

Class-extend `XF\Service\Message\PreparerService`. After `parent::prepare()`, scan the
returned message for roster-profile links, resolve them to `user_id`s, and merge those into
`$this->mentionedUsers`. Because every surface reads its recipients from this one method, the
linked member is then alerted through each surface's own existing mention pipeline: same alert
action, same content type, same gating, with no per-surface code.

- Pro: one seam; it cannot miss a surface (it covers NF/Tickets and any future addon for free);
  it is upgrade-resilient; gating parity is automatic (section 4).
- Con: the linked member receives the surface's standard `mention` alert ("X mentioned you"),
  not a distinct `milpac_mention` alert. The merged users also pass through the mentioner's
  mention permission and each mentionee's privacy filter `XF\Entity\User::getAllowedUserMentions`
  (`XF/Entity/User.php:1251`, applied for example at `Post/PreparerService.php:87`). That is
  defensible parity, but it means a member who set "receive mentions from nobody" is not alerted.

Recommendation: Option (b). See the Recommendation section for the exact trade and the fallback
if distinct alert copy is a hard requirement.

One extension-mechanics risk to verify during the build: our extension must target the canonical
`XF\Service\Message\PreparerService`. NF/Tickets extends the same class via the legacy alias
`XF\Service\Message\Preparer` (`NF/Tickets/Service/Message/Preparer.php:228`). Confirm that both
extensions resolve into one inheritance chain (they should, since it is the same underlying class)
so ours is not bypassed on the tickets surface.

---

## 3. Detection: matching roster-profile links and resolving to a user

**Route.** `NF/Rosters/_data/routes.xml` defines `format="profile/:int<relation_id,username>/"`.
So the int segment is `relation_id`, optionally followed by a `-username` slug. Built links look
like `/rosters/profile/42/` or `/rosters/profile/42-username-slug/`; the controller reads only the
leading int (`NF/Rosters/Pub/Controller/Roster.php:97`, `assertRosterUserExists($params->relation_id)`).

**Matching both forms.** By the time `Message\PreparerService::prepare()` returns, the message is
BbCode-processed text. An auto-linked bare URL and a `[URL=…]…[/URL]` hyperlink both contain the
literal href, so a single path-segment regex catches both and tolerates a leading scheme, host, or
board path, an optional slug, and the trailing slash:

```
#/rosters/profile/(\d+)#
```

Capture group 1 is `relation_id`. It matches relative (`/rosters/profile/42/`) and canonical
(`https://board/rosters/profile/42-username-slug/`) forms alike; dedupe the captured IDs.

**Resolving `relation_id` to `user_id`.** Reuse the finder MilpacTooltip already uses in the forward
direction (`\XF::finder('NF\Rosters:RosterUser')`,
`src/addons/Cav7/MilpacTooltip/Template/MemberMilpacTooltip.php:44`), run in reverse. `relation_id`
is the primary key (`RosterUser.php:221`) and `user_id` is a column with a `User` relation
(`RosterUser.php:225,249-254`), so:

```php
$rows = \XF::finder('NF\Rosters:RosterUser')
    ->where('relation_id', $relationIds)   // or $em->findByIds('NF\Rosters:RosterUser', $relationIds)
    ->fetch();
$userIds = $rows->pluckNamed('user_id', 'relation_id'); // [relation_id => user_id]
```

Then merge as `$mentionedUsers[$row->user_id] = $row->User;` to match the map shape at
`MentionFormatter.php:281`. Skip a row whose `user_id` equals the content author (the dispatchers
already self-skip the author, but skipping early avoids a pointless load).

---

## 4. Gating parity: is reusing each surface's own check correct?

Yes, and it is free under Option (b). Injecting users into the mention set routes them through the
identical visibility check each surface already runs before it alerts a mentioned user:

- Post: `canUserViewContent()` calls `post->canView()` at `Post/NotifierService.php:94-100`,
  enforced in the notifier loop at `XF/Service/AbstractNotifier.php:87`.
- ProfilePost: `getUsersForNotification()` drops users failing `profilePost->canView()` at
  `ProfilePost/NotifierService.php:87-91`.
- ProfilePostComment: `comment->canView()` at `ProfilePostComment/NotifierService.php:134-138`.
- Report: `report->canView()` at `Report/NotifierService.php:137` (re-checked at `:111`).
- NF/Tickets: `message->canView()` at `NF/Tickets/Service/Message/Notifier.php:120-126`.

So a member who cannot view a `Report` (or a hidden ticket) is filtered out at `:137` and never
alerted to a milpac link inside it. The parity requirement holds with no extra work. Under Option (a)
the same checks are reused, so parity holds there too; the difference is purely surface count, not
correctness.

---

## 5. Alert plumbing: what a milpac alert needs

An alert row is `(content_type, content_id, action)` plus extra data. `UserAlertRepository::insertAlert()`
creates the `UserAlert` and pushes it (`XF/Repository/UserAlertRepository.php:147-232`). Delivery and
opt-out are keyed on `(content_type, action)` through `userReceivesAlert`, which calls
`Option->doesReceiveAlert($contentType, $action)` (`:57-76`). The handler renders the template
`public:alert_<contentType>_<action>` (`XF/Alert/AbstractHandler.php:73-76`), loads the content through
`findByContentType` (`:194-197`), and re-checks `canViewContent` (`:34-42`) at read time.

- **Under Option (b) (recommended), nothing new is required.** The linked member receives the surface's
  existing `mention` action on its existing content type (`post`, `profile_post`, `profile_post_comment`,
  `report`, `nf_tickets_message`), using the existing handler and the existing `alert_<type>_mention`
  templates. No new content type, no new handler, no new templates or phrases.
- **If a distinct `milpac_mention` alert is mandated,** reuse the target content's existing alert content
  type and handler (the handler resolves purely by action string, so it needs no code change) and add only:
  1. new templates `alert_<type>_milpac_mention` (and optional `push_<type>_milpac_mention`) per surface;
  2. new phrases for the copy;
  3. optionally, an entry in each handler's `getOptOutActions()` (for example `Alert/PostHandler.php:17-26`)
     to add `'milpac_mention'` for separate opt-out;
  4. `depends_on_addon_id => 'Cav7/MilpacMention'` on the `alert()` call so rows self-clean on uninstall
     (`insertAlert` honours it at `:199-210`; `findAlertsForUser` filters inactive addons at `:30-34`).

  A new content type or alert handler is not needed, because the milpac link always lives inside an
  existing post, comment, report, or ticket message.

---

## Recommendation

Detect once, in a single class extension on `XF\Service\Message\PreparerService`, and merge the
milpac-linked members into the existing mention recipient set, reusing each surface's stock `mention`
alert.

One-line rationale: it is the only hook that fires on every surface a `@`-mention fires, including the
NF/Tickets dispatcher that a "four NotifierServices" model silently misses, and any future addon, with
correct per-surface visibility gating for free and no alert plumbing.

Concretely: extend `XF\Service\Message\PreparerService::prepare()`, call the parent, regex the returned
message for `#/rosters/profile/(\d+)#`, resolve `relation_id` to `user_id` via the MilpacTooltip
`NF\Rosters:RosterUser` finder run in reverse, and inject `[user_id => User]` into `$this->mentionedUsers`.
The four core NotifierServices, NF/Tickets, and NF/Calendar-via-Post then alert the linked member through
their own pipelines, unchanged.

**Fallback**, only if distinct milpac copy or opt-out is a hard requirement: keep the detection in the same
shared Message-Preparer hook (still one detection seam), but stash the resolved `user_id`s on the entity and
add a thin per-dispatcher extension (four core plus NF/Tickets) that fires
`alert(… <existingContentType>, <id>, 'milpac_mention', dependsOnAddOnId: 'Cav7/MilpacMention')`, reusing each
content type's existing alert handler and adding only templates and phrases. Accept that this reintroduces the
"a new addon surface is silently missed" fragility.

**Open risks the spec must note:**

1. **Surface count is not four.** NF/Tickets is a live fifth mention surface. The shared hook covers it; a
   per-dispatcher extension must not forget it.
2. **Extension-alias check.** Verify our `XF\Service\Message\PreparerService` extension is not bypassed on the
   NF/Tickets surface, which extends the same class via the legacy `Preparer` alias.
3. **Privacy filter.** Option (b) routes milpac members through `getAllowedUserMentions`
   (`XF/Entity/User.php:1251`), so a member with restrictive mention-privacy is not alerted. Confirm that is the
   desired behaviour for a milpac reference, as opposed to an `@`-mention.
4. **Semantics.** Under Option (b) the alert reads "X mentioned you" with no `@`-name in the text (the reference
   is a URL). That is acceptable, and it is why the fallback exists.
