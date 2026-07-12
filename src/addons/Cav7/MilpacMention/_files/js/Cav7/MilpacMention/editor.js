/**
 * Cav7/MilpacMention — the $name editor completer (issue #89, spec §4.1–§4.8).
 *
 * A third XF.AutoCompleter, trigger '$', wired beside XF's '@' and ':' completers
 * through the bubbling editor:init event — no core method is overridden (§4.1).
 * Selecting a row inserts a NAMED roster-profile link that the phase-1 engine
 * already detects, so insertion adds no second notification path (§4.2, §2.2).
 *
 * Two insertion surfaces, one artifact:
 *   - Rich editor (Froala): insertMode 'html' drops the row's `html` — the anchor
 *     <a href=".../rosters/profile/N/">Rank Name</a> — in as a node, which Froala
 *     serialises back to [URL='.../rosters/profile/N/']Rank Name[/URL] on save,
 *     with the roster path intact so #/rosters/profile/(\d+)# still matches (§4.8.2).
 *   - Plain BBCode / mobile: the same named link written as the BBCode text
 *     [URL='.../rosters/profile/N/']Rank Name[/URL] — never a bare URL (§4.6).
 *
 * The '$' only opens a lookup at a word boundary: XF.AutoCompleter.getCurrentMatchInfo
 * accepts the '$' only at the start of the text or right after whitespace / ] ( , / --,
 * the same guard '@' rides, so a '$' embedded mid-word (e.g. "cost$5") never queries
 * (§4.8.4). This addon adds no boundary logic of its own — it inherits XF's. That
 * word-boundary start is the real guarantee; "$"+digits is NOT specially filtered — a
 * boundary "$50" does reach the endpoint (XF's minLength is 2), it just returns no
 * milpac so the dropdown stays hidden. "it cost $5" happens to stay quiet only because
 * "$5" is one character under that minLength, not because digits after "$" are blocked.
 *
 * The row shape comes from the merged find endpoint (#88), which returns the
 * standard {q, results} envelope with each row carrying id, iconHtml, text
 * ("Rank Name"), desc (roster) and html (the named anchor). Rows are inserted
 * verbatim from `html`; the completer never rebuilds the insert value from
 * rank+name, or the artifact the engine detects could drift (§4.8.1).
 */
((window, document) =>
{
	'use strict'

	const XF = window.XF
	if (!XF || !XF.AutoCompleter)
	{
		return
	}

	// The merged find endpoint (#88): GET index.php?milpac-mention/find?q=… → {q, results}.
	const FIND_URL = 'index.php?milpac-mention/find'

	// Dropdown row: rank + name primary ({{{text}}}), roster secondary ({{{desc}}}),
	// avatar thumbnail ({{{icon}}}) — mirrors XF's emoji-completer chrome (§4.5).
	const DISPLAY_TEMPLATE =
		'<div class="contentRow">' +
			'<div class="contentRow-figure">{{{icon}}}</div>' +
			'<div class="contentRow-main contentRow-main--close">{{{text}}}' +
				'<div class="contentRow-minor contentRow-minor--smaller">{{{desc}}}</div>' +
			'</div>' +
		'</div>'

	// Options shared by both surfaces. at '$' (§4.1); keepAt false consumes the '$'
	// emoji-style on insert (§4.1); insertMode 'html' takes the row's `html` as the
	// insert value (§4.8.1) — the named anchor the phase-1 engine detects (§2.2).
	const baseOptions = () => ({
		url: XF.canonicalizeUrl(FIND_URL),
		at: '$',
		keepAt: false,
		insertMode: 'html',
		displayTemplate: DISPLAY_TEMPLATE,
	})

	// Rewrite the row's named anchor <a href="URL">TEXT</a> to the BBCode
	// [URL='URL']TEXT[/URL] (§4.6). The href keeps /rosters/profile/N/ verbatim, so
	// the engine regex #/rosters/profile/(\d+)# still matches — a NAMED link, never
	// a bare URL. Same artifact as the rich editor, serialised for a textarea.
	const anchorToBbCode = html =>
	{
		// createElementFromString returns the <a> directly for a single-node string,
		// but wraps a multi-node string in a div.js-createdContainer — in which case
		// the <a> is a descendant, not the element itself. Select the anchor
		// explicitly so a wrapper (or a row that carries no <a> at all) can't slip
		// through as getAttribute('href') === null and emit a URL-less [URL='']…[/URL].
		const el = XF.createElementFromString(html)
		const anchor = el && el.matches && el.matches('a')
			? el
			: (el && el.querySelector ? el.querySelector('a') : null)
		if (!anchor)
		{
			// No anchor to name a link from — leave the row's html untouched rather
			// than rewrite it into a broken, name-losing [URL=''] tag.
			return html
		}

		const href = anchor.getAttribute('href') || ''
		const text = anchor.textContent || ''
		return "[URL='" + href + "']" + text + '[/URL]'
	}

	// A plain-textarea AutoCompleter that rewrites each result's `html` from the
	// named anchor to the [URL=…] BBCode form before the row is built. It has to
	// sit upstream of the row: XF applies beforeInsert only on keyboard selection,
	// not on mouse click, so transforming the response is the only place that makes
	// BOTH paths insert the named BBCode. insertMode stays 'html', so getResultText
	// returns the rewritten `html`, and a textarea insert drops it in as text (§4.6).
	XF.MilpacBbCodeCompleter = XF.extend(XF.AutoCompleter, {
		__backup: { handlePendingQueryOptions: '_milpacSuperHandlePendingQueryOptions' },

		handlePendingQueryOptions (data)
		{
			if (data && data.results)
			{
				for (const row of Object.values(data.results))
				{
					if (row && row.html)
					{
						row.html = anchorToBbCode(row.html)
					}
				}
			}

			this._milpacSuperHandlePendingQueryOptions(data)
		},
	})

	// The plain-BBCode / mobile element handler, registered beside XF's
	// user-mentioner and emoji-completer (§4.6). Applied to the plain textarea via
	// its data-xf-init (template modification) and to the rich editor's BBCode
	// source box (see attachToBbCodeSourceBox).
	XF.MilpacMentioner = XF.Element.newHandler({
		handler: null,

		init ()
		{
			this.handler = new XF.MilpacBbCodeCompleter(this.target, baseOptions())
		},
	})

	XF.Element.register('milpac-mentioner', 'XF.MilpacMentioner')

	// The rich editor's "view BBCode source" toggle builds a plain <textarea> in JS
	// (editor.js getBbCodeBox) and applies user-mentioner / emoji-completer to it,
	// but not ours, and it fires no event. Watch for that box appearing beside the
	// editor and attach milpac-mentioner once, so the source view inserts the same
	// named BBCode as any plain textarea (§4.6).
	const attachToBbCodeSourceBox = ed =>
	{
		// When the editor initialises in source mode (!XF.isEditorEnabled()), the vendor
		// builds the BBCode source <textarea> during setup — BEFORE editor:init fires —
		// and caches it on ed.$oel.data('xfBbCodeBox') (editor.js getBbCodeBox). The
		// observer below only sees FUTURE additions, so it never catches that already-
		// present box, leaving the source view without $name completion. Attach to it
		// directly if it exists, then observe only for the lazy "view BBCode source" case.
		const existing = ed.$oel && typeof ed.$oel.data === 'function'
			? ed.$oel.data('xfBbCodeBox')
			: null
		if (existing)
		{
			XF.Element.applyHandler(existing, 'milpac-mentioner')
			return
		}

		const wrapper = ed.$wp && ed.$wp[0]
		const parent = wrapper && wrapper.parentNode
		if (!parent || typeof MutationObserver === 'undefined')
		{
			return
		}

		// Match the source box by its actual shape — the vendor's <textarea class="input">
		// (getBbCodeBox) — not the first <textarea> to appear, so an unrelated textarea
		// added beside the editor can't be mistaken for the BBCode source box.
		const findSourceBox = node =>
		{
			if (node.nodeType !== Node.ELEMENT_NODE)
			{
				return null
			}
			if (node.matches && node.matches('textarea.input'))
			{
				return node
			}
			return node.querySelector ? node.querySelector('textarea.input') : null
		}

		const observer = new MutationObserver(records =>
		{
			for (const record of records)
			{
				for (const node of record.addedNodes)
				{
					const box = findSourceBox(node)
					if (box)
					{
						XF.Element.applyHandler(box, 'milpac-mentioner')
						observer.disconnect()
						return
					}
				}
			}
		})
		observer.observe(parent, { childList: true })
	}

	// Rich editor: attach the '$' completer on the bubbling editor:init event
	// (§4.1). insertMode 'html' inserts the row's <a> node, which Froala serialises
	// to [URL=…]Rank Name[/URL] with /rosters/profile/N/ intact (§4.8.2).
	XF.on(document, 'editor:init', e =>
	{
		const ed = e.ed
		if (!ed || !ed.$el || !ed.$el[0])
		{
			return
		}

		new XF.AutoCompleter(ed.$el[0], baseOptions(), ed)

		attachToBbCodeSourceBox(ed)
	})
})(window, document)
