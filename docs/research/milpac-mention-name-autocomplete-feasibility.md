# Cav7/MilpacMention: `!name` autocomplete feasibility spike

Feasibility spike only. No addon code was written; nothing here shipped. XenForo
core (`XF\…`, and its `js/xf/…` client code) is third-party and is not vendored in
this repository. Paths below are relative to a standard XenForo `app/src/` /
`js/xf/` tree in a local dev install (see CONTRIBUTING.md). Line numbers are
navigation aids against the current dev install and drift with vendor upgrades.
A "milpac" is one `NF\Rosters\Entity\RosterUser` row; its profile URL is
`/rosters/profile/<relation_id>/`, where `relation_id` is the `RosterUser`
primary key and the row carries a `user_id`.

The question: prove a `!name` editor autocomplete can sit beside XenForo's `@`
(mention) and `:` (emoji) completers, resolve a username to their milpac, and
insert a roster-profile link — and gauge how exposed the JS wiring is to XF
upgrades.

**Verdict: GO, low upgrade risk.** Every piece already exists in core and is
reused by other addons; a `!` completer is the same construction as the two
completers XF already runs, attached through an established public event. The
residual risks are all build-time details, not blockers.

---

## 1. The completer primitive is generic, and `!` is a valid trigger

`XF.AutoCompleter` (`js/xf/core.js:10496`) is a generic completer, not a
mention-specific one. Its default options (`core.js:10497-10505`) are
`url`, `at: '@'`, `keepAt: true`, `insertMode: 'text'`, `displayTemplate`. The
`@` mentioner and the `:` emoji completer are both nothing more than one
construction of this class each:

- **Mentioner** (`js/xf/editor.js:624-631`):
  `new XF.AutoCompleter(ed.$el[0], { url: XF.getAutoCompleteUrl() }, ed)` — takes
  the default `at: '@'`.
- **Emoji** (`js/xf/editor.js:633-657`, guarded by `XF.config.shortcodeToEmoji`):
  `new XF.AutoCompleter(ed.$el[0], { url: 'index.php?misc/find-emoji', at: ':',
  keepAt: false, insertMode: 'html', displayTemplate, beforeInsert }, ed)`.

A `!` completer is a third construction with `at: '!'`. The constructor validates
that `at` is a single character (`core.js:~10531`, `this.options.at.length > 1` is
an error), and `!` qualifies. So the shape is:

```js
new XF.AutoCompleter(ed.$el[0], {
  url: XF.canonicalizeUrl('index.php?milpac-mention/find'),
  at: '!',
  keepAt: false,
  insertMode: 'html',
  displayTemplate: '<div class="contentRow">…name · rank · roster…</div>',
}, ed)
```

No subclassing, no core method touched — the emoji completer proves an arbitrary
non-`@` trigger with a custom endpoint and HTML insertion already works through
this exact class.

## 2. The attachment seam is a proven public event (the upgrade-risk crux)

The only real upgrade question is *where* the addon hangs its completer without
overriding a core method. XF answers this directly. After the editor finishes
initialising — including after the mentioner and emoji completers are wired — it
fires a bubbling custom event (`js/xf/editor.js:710-713`):

```js
XF.trigger(this.target, XF.customEvent('editor:init', { ed, editor: this }))
```

`XF_CustomEvent` defaults `bubbles: true` (`js/xf/core.js:10960-10962`), so the
event reaches `document`. An addon attaches with no core override:

```js
XF.on(document, 'editor:init', e => {
  const { ed } = e            // the Froala instance; ed.$el[0] is the editable
  new XF.AutoCompleter(ed.$el[0], { at: '!', /* …opts… */ }, ed)
})
```

This is not a private internal we would be leaning on. The same `editor:init`
event is already consumed by:

- **XF's own** `js/xf/editor_manager.js`
  (`XF.on(xfEditor.target, n, this.rebuildValueCache.bind(this))`), and
- **a shipped third-party addon**, `SV/AdvancedBBCode`
  (`js/sv/advancedbbcode/editor.js`, `…EditorButtons.editorInit.bind(this)`).

An extension point that XF uses internally *and* that a third-party addon already
builds on is about as upgrade-stable as client-side XF wiring gets. The addon
never patches `editor.js`; it only reacts to the event and constructs a public
class. The editor is registered as a standard element handler
(`XF.Editor = XF.Element.newHandler({…})` at `editor.js:21`,
`XF.Element.register('editor', 'XF.Editor')` at `editor.js:4406`), so nothing
about the attachment is bespoke.

## 3. The endpoint contract is standard XF autocomplete

The completer drives the endpoint itself. On each keystroke it GETs the typed
fragment as `q`:

```js
XF.ajax(method, this.options.url, { q: this.pendingQuery }, handler, …)  // core.js:~10763
```

and expects a JSON envelope `{ q: <echo>, results: [ … ] }` — `handlePendingQueryOptions`
(`core.js:~10769`) ignores the response unless `data.q` matches the current query
and `data.results` is a non-empty array. This is the identical contract the `@`
endpoint (`XF\Pub\Controller\Member::actionFind`) and the emoji endpoint
(`misc/find-emoji`) satisfy.

So the server side is one controller action, e.g. `milpac-mention/find`:

1. read `q` (`$this->filter('q', 'str', ['no-trim'])`, as `actionFind` does);
2. find members whose username matches `q` **and** who own a milpac — join to
   `NF\Rosters:RosterUser`, reusing the resolution MilpacTooltip already performs
   in the forward direction (`Cav7/MilpacTooltip`);
3. return `{ q, results }`, each row carrying the fields the `displayTemplate`
   renders (name, rank, roster) and the value to insert (section 4).

Members with no milpac are simply absent from `results` — the same way a
non-mentionable user is absent from the `@` results — which pre-answers part of
the no-milpac case that #70 owns.

## 4. Insertion closes the loop with no second notification path

With `insertMode: 'html'`, `insertResult` builds a real DOM node from the row's
insert value and splices it into the Froala editable:

```js
if (this.options.insertMode === 'html') {
  insert = XF.createElementFromString(result + suffix)   // core.js:10814-10816
}
insertRef.parentNode.insertBefore(insert, insertRef)     // core.js:10823
```

This is exactly how the emoji completer inserts an `<img>` smilie. For `!name`
the row's insert value is a roster-profile anchor:

```html
<a href="/rosters/profile/<relation_id>/">Name</a>
```

On save, Froala's HTML→BBCode serialises the anchor to a link that still contains
the literal `/rosters/profile/<relation_id>/` path. That is precisely what the
engine spike (#66) detects: the `XF\Service\Message\PreparerService` extension
regexes the stored message for `#/rosters/profile/(\d+)#` and merges the linked
member into the mention set. So `!name` is an *input method only* — the link it
inserts flows through the one notification path every milpac link already flows
through. There is no second code path to build or test for notifications; the
`!name` layer's job ends at inserting a well-formed link.

## 5. Plain-BBCode / mobile fallback, and other input edges

When the rich editor is off (plain BBCode, and the mobile fallback), the completer
runs against a plain `<textarea>` instead of Froala. `insertResult` takes its
other branch (`core.js:10829-10842`): it has no HTML mode and inserts the row's
value as **text**. XF wires this path through the element handlers
`user-mentioner` and `emoji-completer`
(`editor.js:3694-3695`, `XF.Element.applyHandler(bbCodeBox, …)`), so a `!`
completer would register a parallel `milpac-mentioner` handler there.

Consequence for `!name`: in the plain-BBCode/mobile path we insert a **bare
roster-profile URL** as text (e.g. `https://…/rosters/profile/42/`) rather than a
named link. It is lower fidelity — a URL, not a member's name — but it still
round-trips to a link the #66 engine detects, so notifications still fire. The
mechanism is settled; whether the bare-URL fallback fidelity is acceptable, and
the draft/preview and paste-vs-typing behaviours, are UX decisions now handed to
#70 (they were the map's "Not yet specified" fog for `!name`, and this spike
sharpens them enough for #70 to decide).

---

## Open risks the spec (#71) and the UX ticket (#70) must carry

1. **Result-row insert-value shape.** Confirm at build time the exact field the
   selected row exposes as the HTML insert value that `insertResult` receives, and
   the `displayTemplate` field names for the dropdown rows. Modelled on
   `find-emoji`, but verify against the running endpoint.
2. **Anchor round-trip.** Confirm the inserted `<a href="/rosters/profile/N/">`
   survives Froala's HTML→BBCode serialisation as a link whose href still contains
   `/rosters/profile/N/` (not stripped, rel-mangled, or shortened past the #66
   regex). This is the one behaviour a running prototype would nail down that a
   read cannot fully guarantee; it is low-risk because auto-linked URLs and
   `[URL=…]` both retain the literal path (see #66 §3), but it must be verified in
   the build.
3. **Plain-BBCode / mobile fidelity.** The fallback inserts a bare URL, not a
   named link. #70 decides whether that is acceptable or warrants extra handling.
4. **Trigger collision.** `!` is common in prose ("no!"). The completer only opens
   after a word boundary (`core.js` match-info checks the char before `at` is
   whitespace/bracket/`--`), which mitigates most false triggers, but confirm the
   feel during the build and that `!name` does not fire mid-word.

## What resolving this unblocks

`!name` is confirmed feasible as a staged second layer on top of the notification
engine, with no new notification path. #70 (`!name` UX design) is unblocked and
inherits the input-edge questions above. The spec (#71) carries the wiring
approach and the four open risks into the staged-`!name` section.
